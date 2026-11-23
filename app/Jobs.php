<?php
declare(strict_types=1);
namespace Studio;

/**
 * Persistent generation requests.
 *
 * Every request is stored before any paid call is made, claimed exactly once and never
 * replayed automatically. Several requests may run at the same time (max_parallel).
 * While a request waits for the provider, the worker refreshes `updated` (heartbeat) and
 * `phase`, and checks whether the owner asked to cancel it. From before it claims a request until after it ends it,
 * the worker also holds a lock on a file of the request's own, so other requests can tell whether it still exists.
 */
final class Jobs
{
    public const OPERATIONS = ['generate','edit','expand','upscale'];
    /**
     * Seconds without a heartbeat before a running request is considered interrupted, when its process can't be
     * checked (no file locks on this host, or a request started by an earlier version).
     */
    public const STALE_AFTER = 180;
    /**
     * Seconds without a heartbeat before the server checks whether the process of a running request still exists.
     * A request whose process is alive is never interrupted for taking long; one whose process is gone is marked then.
     */
    public const CHECK_AFTER = 20;
    /** What each phase was doing, for the message of a request whose process stopped. */
    private static function phaseText(string $phase): ?string
    {
        return match ($phase) {
            'starting' => t('right at the start'), 'varying' => t('while planning the variations'), 'planning' => t('while planning the prompt'),
            'generating' => t('while waiting for the image'), 'saving' => t('while saving the image'), default => null,
        };
    }
    private const TERMINAL_UNSUCCESSFUL = ['failed','uncertain','cancelled'];
    /**
     * The images a request sends (the image being changed, its references, the painted area) take megabytes; the rest of
     * it, a few KB. They are kept apart, in one sealed file per request (shared by the images of a batch and by the
     * requests that repeat it, since they never change), and read only to run or repeat the request: the history, the
     * polling and the details read the rest. Requests from before kept them in their row; reading one moves them out.
     */
    private const INPUTS = ['references','mask'];
    /** The columns of a request without anything heavy (SELECT * would also read what later versions add). */
    private const COLUMNS = 'id,payload,status,created,updated,phase,user_id,preview,started,worker_lock,runner,progress';
    /** Seconds one process spends moving the images of earlier requests out of their rows, at most (the rest go later). */
    private const MOVE_BUDGET = 8.0;
    private float $moving = 0.0;

    public function __construct(private Vault $vault, private array $config) {}

    private function maxParallel(): int { return max(1, min(16, (int)($this->config['max_parallel'] ?? 4))); }

    /** @return list<array> one job per requested image */
    public function create(array $input, string $key): array
    {
        $id = (string)($input['id'] ?? ''); Vault::validId($id);
        $existing = $this->batch($id, (int)(filter_var($input['options']['n'] ?? 1, FILTER_VALIDATE_INT) ?: 1), $key); if ($existing) return $existing;
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 32000) throw new HttpError(422, t('Write a prompt of up to 32,000 characters.'));
        $options = Images::options(is_array($input['options'] ?? null) ? $input['options'] : []);

        $parent = null;
        if (isset($input['parent_id']) && $input['parent_id'] !== '') $parent = $this->vault->still((string)$input['parent_id'], $key)['id'];
        // Edits, expansions and upscales belong to the image they came from; plain generations start a new lineage.
        $requested = (string)($input['operation'] ?? '');
        $operation = $parent === null ? 'generate' : (in_array($requested, ['expand','upscale'], true) ? $requested : 'edit');
        $label = trim(preg_replace('/\s+/u', ' ', (string)($input['label'] ?? '')) ?? '');
        $label = $label === '' ? null : mb_substr($label, 0, 200);
        // Several images from one request, one line each: every line becomes the direction of one image, as written.
        $lines = null;
        if (isset($input['lines'])) {
            if (!is_array($input['lines']) || !in_array($operation, ['generate','edit'], true)) throw new HttpError(422, t('Invalid lines.'));
            $lines = [];
            foreach ($input['lines'] as $line) {
                if (!is_string($line)) throw new HttpError(422, t('Invalid lines.'));
                $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
                if ($line !== '') $lines[] = mb_substr($line, 0, 1000);
            }
            if (count($lines) < 2 || count($lines) > 10) throw new HttpError(422, t('Write from 2 to 10 lines, one per image.'));
            $options['n'] = count($lines);
        }

        $refs = $input['references'] ?? [];
        // GPT Image reads 16 images; Nano Banana 14, the copy that marks a painted area among them.
        $most = Images::maxImages($options['model']) - (!empty($input['mask']) && Images::provider($options['model']) === 'gemini' ? 1 : 0);
        if (!is_array($refs) || count($refs) > $most) {
            $model = Images::model($options['model'])['label'];
            throw new HttpError(422, $most >= 16 ? t('Use up to {most} references.', ['most'=>$most])
                : (!empty($input['mask']) ? t('Use up to {most} references with {model} and a painted area.', ['most'=>$most, 'model'=>$model]) : t('Use up to {most} references with {model}.', ['most'=>$most, 'model'=>$model])));
        }
        $references = []; $parents = []; $first = null; $cleaned = null;
        foreach ($refs as $n => $ref) {
            if (!is_array($ref)) throw new HttpError(422, t('Invalid reference.'));
            $levels = [];
            if (isset($ref['id'])) {
                $im = $this->vault->still((string)$ref['id'], $key);
                $url = 'data:'.$im['mime'].';base64,'.base64_encode($this->vault->bytes($im['id'], 'full', $key)); $parents[] = $im['id'];
                $levels[] = self::cleanedLevel($im);
            } else {
                $url = (string)($ref['data'] ?? '');
                if (!empty($ref['source_id'])) { $source = $this->vault->still((string)$ref['source_id'], $key); $parents[] = $source['id']; $levels[] = self::cleanedLevel($source); }
                // The browser cleaned this image of dots and fine lines right before sending it.
                if (($ref['cleaned'] ?? false) === true) $levels[] = is_string($ref['clean'] ?? null) && isset(Vault::CLEAN_LEVELS[$ref['clean']]) ? $ref['clean'] : 'media';
            }
            // The image being changed went through Limpar (now, or as the Limpar version it is): the model is told that its
            // blur is on purpose and must be undone, at the strongest level it went through.
            if ($n === 0 && $parent !== null && ($levels = array_filter($levels))) $cleaned = self::strongestClean($levels);
            $valid = OpenAI::imageInput($url); $first ??= $valid; $references[] = $valid['url'];
        }
        if ($parent !== null && !$references) throw new HttpError(422, t('The edit needs the original image.'));
        $mask = null;
        if (!empty($input['mask'])) {
            if (!$first) throw new HttpError(422, t('Add an image before using the mask.'));
            $m = OpenAI::imageInput((string)$input['mask'], true);
            if ($m['width'] !== $first['width'] || $m['height'] !== $first['height']) throw new HttpError(422, t('The mask must be the size of the first image.'));
            $mask = $m['url'];
        }
        // With an area marked (and in an expansion) what is outside the area comes back as it was sent: asking for the
        // blur to be undone would only make the new part sharper than the rest.
        if ($mask !== null || $operation === 'expand') $cleaned = null;
        Images::requireKey($this->vault, $key, $options['model']);
        $payload = ['prompt'=>$prompt,'options'=>$options,'references'=>$references,'parent_ids'=>array_values(array_unique($parents)),'mask'=>$mask,
            'parent_id'=>$parent,'operation'=>$operation,'label'=>$label,'image_ids'=>[],'error'=>null];
        if ($lines !== null) $payload['lines'] = $lines;
        // The images also go into this album when they are saved (a story's illustrations go into the story's album).
        if (isset($input['album'])) { Vault::validId((string)$input['album']); if ($this->vault->hasAlbum((string)$input['album'])) $payload['album'] = (string)$input['album']; }
        if ($cleaned !== null) $payload['cleaned'] = $cleaned;
        // What the person wrote in the box when the prompt is a ready-made instruction put together with it (Fotorrealista,
        // Melhorar, Ampliar, Expandir): the details offer it to copy apart from the whole prompt. '' means nothing written.
        if (array_key_exists('typed', $input)) {
            if (!is_string($input['typed'])) throw new HttpError(422, t('Invalid typed text.'));
            $payload['typed'] = mb_substr(trim($input['typed']), 0, 32000);
        }
        return $this->insertEach($id, $payload, $key);
    }

    /** The Limpar level of a library image that is itself a Limpar version, else null. */
    private static function cleanedLevel(array $image): ?string
    {
        if (($image['operation'] ?? null) !== 'clean') return null;
        return is_string($image['clean'] ?? null) && isset(Vault::CLEAN_LEVELS[$image['clean']]) ? $image['clean'] : 'media';
    }

    /** The Limpar level the image being changed went through, for the details; requests from before levels were kept say true. */
    private static function cleanedOf(array $p): ?string
    {
        $c = $p['cleaned'] ?? null;
        return $c === true ? 'media' : (is_string($c) ? $c : null);
    }

    /** @param list<string> $levels Limpar levels; the strongest of them */
    private static function strongestClean(array $levels): string
    {
        $order = array_keys(Vault::CLEAN_LEVELS);
        usort($levels, fn($a, $b) => array_search($b, $order, true) <=> array_search($a, $order, true));
        return $levels[0];
    }

    /**
     * Ids of the jobs of one request: the first is the client's, the others derive from it, so a repeated
     * request finds the same jobs instead of creating more.
     * @return list<string>
     */
    public static function batchIds(string $id, int $count): array
    {
        $ids = [$id];
        for ($k = 1; $k < max(1, min(10, $count)); $k++) $ids[] = substr(hash('sha256', "$id:$k"), 0, 32);
        return $ids;
    }

    /** @return list<array> the jobs of an earlier identical request, or [] */
    private function batch(string $id, int $count, string $key): array
    {
        $jobs = [];
        foreach (self::batchIds($id, $count) as $jobId) { $row = $this->find($jobId, $key); if ($row) $jobs[] = $this->publicJob($row); }
        return $jobs;
    }

    /**
     * A request for several images becomes one job per image: each runs, fails, is cancelled or retried on its own,
     * and each can stream previews.
     * @return list<array>
     */
    private function insertEach(string $id, array $payload, string $key): array
    {
        $count = (int)$payload['options']['n'];
        $payload['options']['n'] = 1;
        // A single image keeps the variation it was asked with (repeating one image of a batch); a new batch gets its own.
        if ($count > 1) unset($payload['variation']);
        // Lines written by the owner go one per image; a batch of another size (repeating with a new count) is planned.
        $lines = $payload['lines'] ?? null;
        if (!is_array($lines) || count($lines) !== $count) { unset($payload['lines']); $lines = null; }
        $vary = $lines === null && $count > 1 && !empty($payload['options']['vary']) && in_array($payload['operation'] ?? 'generate', ['generate','edit'], true);
        // Every image of a set is told it is one of several, so it never comes back as a grid of them. A single image
        // repeated from a set keeps knowing that.
        if ($count > 1) $payload['batch_of'] = $count;
        $ids = self::batchIds($id, $count);
        // The images it sends go once into a file of the batch's first request; a repeated request keeps the file of the
        // one it repeats.
        $fresh = self::carriesInputs($payload) && !isset($payload['inputs']);
        if ($fresh) $payload['inputs'] = $ids[0];
        $inserted = [];
        foreach ($ids as $k => $jobId) {
            if ($lines !== null) $payload['variation'] = ['index'=>$k + 1, 'count'=>$count, 'label'=>$lines[$k], 'direction'=>'Direction for this image: '.$lines[$k], 'planned'=>false, 'written'=>true];
            elseif ($vary) $payload['variation'] = ['index'=>$k + 1, 'count'=>$count, 'batch'=>$id];
            if ($this->insert($jobId, $payload, $key)) $inserted[] = $jobId;
        }
        // Written only for a first request that is new here: an id already taken (by another account) is never touched.
        if ($fresh && in_array($ids[0], $inserted, true)) {
            try { $this->writeInputs($ids[0], $payload, $key); }
            catch (\Throwable $e) {
                foreach ($inserted as $jobId) $this->vault->db->prepare('DELETE FROM jobs WHERE id=? AND user_id=?')->execute([$jobId, $this->vault->owner()]);
                throw $e;
            }
        }
        $jobs = $this->batch($id, $count, $key);
        // Ids are random; one already taken by another account is a broken client, never something to merge with.
        if (count($jobs) !== max(1, min(10, $count))) throw new HttpError(409, t('Request id already used. Reload the page.'));
        return $jobs;
    }

    /** Queue a new request with the same inputs as an earlier one (references included). */
    /** @return list<array> one job per requested image */
    /** $album: the album the new images go into instead of the first request's (a comic page asked before the comic had one). */
    public function retry(string $id, string $from, mixed $n, string $key, ?string $album = null): array
    {
        Vault::validId($id); Vault::validId($from);
        $source = $this->find($from, $key); if (!$source) throw new HttpError(404, t('Request not found.'));
        $source = $this->withInputs($source, $key);
        if ($source['runner'] !== null) {
            $existing = $this->batch($id, 1, $key); if ($existing) return $existing;
            $p = $source['payload'];
            [$p['options']] = Gpu::options($p['options'], array_map(fn($m) => array_intersect_key($m, array_flip(['kind','role','at'])), $p['media'] ?? []));
            $p['image_ids'] = []; $p['error'] = null; unset($p['dismissed'], $p['error_kind'], $p['upload'], $p['made']);
            if ($album !== null) { Vault::validId($album); $p['album'] = $album; }
            $this->insert($id, $p, $key, 'gpu');
            return [$this->publicJob($this->find($id, $key))];
        }
        $count = $n === null ? (int)($source['payload']['options']['n'] ?? 1) : (int)(filter_var($n, FILTER_VALIDATE_INT) ?: 1);
        $existing = $this->batch($id, $count, $key); if ($existing) return $existing;
        $p = $source['payload'];
        $p['options'] = Images::options($n === null ? $p['options'] : array_merge($p['options'], ['n'=>$n]));
        Images::requireKey($this->vault, $key, $p['options']['model']);
        if (!empty($p['parent_id'])) {
            try { $this->vault->image((string)$p['parent_id'], $key, true); } catch (HttpError) { $p['parent_id'] = null; $p['operation'] = 'generate'; }
        }
        $p['image_ids'] = []; $p['error'] = null; $p['variation_usage'] = null; unset($p['dismissed'], $p['error_kind']);
        if ($album !== null) { Vault::validId($album); $p['album'] = $album; }
        return $this->insertEach($id, $p, $key);
    }

    /** True when the request is new (false: the id was already taken). */
    private function insert(string $id, array $payload, string $key, ?string $runner = null): bool
    {
        $q = $this->vault->db->prepare('INSERT OR IGNORE INTO jobs(id,payload,status,created,updated,phase,user_id,runner) VALUES(?,CAST(? AS BLOB),?,?,?,NULL,?,?)');
        $q->execute([$id, $this->sealPayload($id, $payload, $key), 'queued', time(), time(), $this->vault->owner(), $runner]);
        return $q->rowCount() === 1;
    }

    /** Whether a payload holds the images it sends (as read with them, or as kept before they went apart). */
    private static function carriesInputs(array $p): bool { return !empty($p['references']) || !empty($p['mask']); }

    /** A payload without the images it sends, with what the history shows about them. */
    private static function light(array $p): array
    {
        if (array_key_exists('references', $p) || array_key_exists('mask', $p)) {
            $p['reference_count'] = count($p['references'] ?? []); $p['has_mask'] = !empty($p['mask']);
        }
        foreach (self::INPUTS as $name) unset($p[$name]);
        return $p;
    }

    /**
     * The sealed payload of a row: never the images it sends. A payload that still holds them and has no file for them (a
     * request from before, read whole) gets its file first, so they are never lost.
     */
    private function sealPayload(string $id, array $p, string $key): string
    {
        if (self::carriesInputs($p) && !isset($p['inputs'])) { $this->writeInputs($id, $p, $key); $p['inputs'] = $id; }
        return Crypto::seal(json_encode(self::light($p), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "job:$id");
    }

    private function inputsPath(string $id): string { Vault::validId($id); return $this->vault->dir.'/blobs/job-'.$id.'-inputs.enc'; }

    private function writeInputs(string $id, array $p, string $key): void
    {
        $sealed = Crypto::seal(json_encode(['references'=>array_values($p['references'] ?? []), 'mask'=>$p['mask'] ?? null], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES), $key, "job:$id:inputs");
        $path = $this->inputsPath($id); $tmp = $path.'.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $sealed, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new HttpError(503, t('[DATA-WRITE] Could not keep the request\'s images in the private folder. Check the disk space.'));
        }
    }

    /** The request with the images it sends, to run or repeat it. */
    private function withInputs(array $job, string $key): array
    {
        $p = $job['payload'];
        if (array_key_exists('references', $p) || !isset($p['inputs'])) { $job['payload'] += ['references'=>[], 'mask'=>null]; return $job; }
        $from = (string)$p['inputs'];
        $data = @file_get_contents($this->inputsPath($from));
        if ($data === false) throw new HttpError(410, t('The images sent with this request are no longer in the private folder.'));
        $in = json_decode(Crypto::open($data, $key, "job:$from:inputs"), true, 512, JSON_THROW_ON_ERROR);
        $job['payload']['references'] = is_array($in['references'] ?? null) ? $in['references'] : [];
        $job['payload']['mask'] = is_string($in['mask'] ?? null) ? $in['mask'] : null;
        return $job;
    }

    /** The prompt a request sent to the image API, as it went, or null (a request from before it was kept, or not run). */
    public function sentPrompt(string $id, string $key): ?string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) return null;
        $sent = $this->find($id, $key)['payload']['sent_prompt'] ?? null;
        return is_string($sent) ? $sent : null;
    }

    /** A request of the account, without the images it sends (withInputs adds them). */
    private function find(string $id, string $key): ?array
    {
        $q = $this->vault->db->prepare('SELECT '.self::COLUMNS.' FROM jobs WHERE id=? AND user_id=?'); $q->execute([$id, $this->vault->owner()]);
        $r = $q->fetch(); $q->closeCursor(); if (!$r) return null;
        $sealed = (string)$r['payload'];
        $r['payload'] = json_decode(Crypto::open($sealed, $key, "job:$id"), true, 512, JSON_THROW_ON_ERROR);
        if (self::carriesInputs($r['payload']) && !isset($r['payload']['inputs'])) $r['payload'] = $this->moveInputs($id, $r['payload'], $sealed, $key);
        return $r;
    }

    /**
     * Moves the images of a request from before out of its row, into their own file, while this process has time for it;
     * otherwise the payload comes back whole (and is moved when it is saved). The row only changes if nothing wrote it
     * meanwhile: the images never change, but the rest may have.
     */
    private function moveInputs(string $id, array $p, string $sealed, string $key): array
    {
        if ($this->moving >= self::MOVE_BUDGET) return $p;
        $started = microtime(true);
        try {
            $this->writeInputs($id, $p, $key); $p['inputs'] = $id;
            $q = $this->vault->db->prepare('UPDATE jobs SET payload=CAST(? AS BLOB) WHERE id=? AND CAST(payload AS BLOB)=CAST(? AS BLOB)');
            $q->execute([Crypto::seal(json_encode(self::light($p), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "job:$id"), $id, $sealed]);
            return self::light($p);
        } catch (\Throwable $e) {
            logFailure($e); unset($p['inputs']); return $p;
        } finally { $this->moving += microtime(true) - $started; }
    }

    private function publicJob(array $r): array
    {
        $p = $r['payload'];
        // The album its images go into, and whether that album keeps them out of the library: then the library doesn't
        // show the request either, the album does.
        $album = is_string($p['album'] ?? null) ? $p['album'] : null;
        return ['id'=>$r['id'],'status'=>$r['status'],'phase'=>$r['phase'] ?? null,'created'=>(int)$r['created'],'updated'=>(int)$r['updated'],
            'prompt'=>$p['prompt'],'label'=>$p['label'] ?? null,'options'=>$p['options'],'image_ids'=>$p['image_ids'],'error'=>self::errorFor($r),'error_kind'=>$p['error_kind'] ?? null,
            'reference_count'=>(int)($p['reference_count'] ?? count($p['references'] ?? [])),'has_mask'=>!empty($p['has_mask'] ?? $p['mask'] ?? null),'parent_ids'=>$p['parent_ids'] ?? [],
            'parent_id'=>$p['parent_id'] ?? null,'operation'=>$p['operation'] ?? (empty($p['parent_ids']) ? 'generate' : 'edit'),
            'dismissed'=>!empty($p['dismissed']),'previews'=>(int)($r['preview'] ?? 0),
            'variation'=>self::variationInfo($p),'watched'=>!empty($r['worker_lock']),'cleaned'=>self::cleanedOf($p),
            'typed'=>is_string($p['typed'] ?? null) ? $p['typed'] : null]
            + ['album'=>$album, 'apart'=>$album !== null && ($this->apart[$album] ??= $this->vault->keepsApart($album))]
            // A video for the GPU worker: what it reports while making it (steps done, its own line about what it does).
            // Its inputs without their data: kind, role, moment, library id (pictures and videos) and a sound's name.
            + (($r['runner'] ?? null) !== null ? ['runner'=>$r['runner'], 'kind'=>'video', 'roles'=>$p['roles'] ?? [],
                'inputs'=>array_map(fn($m) => array_intersect_key($m, array_flip(['kind','role','at','id','name','duration'])), $p['media'] ?? []),
                'progress'=>is_string($r['progress'] ?? null) ? json_decode($r['progress'], true) : null, 'made'=>$p['made'] ?? null] : []);
    }
    /** Album id => whether it keeps its items out of the library, as read in this process. */
    private array $apart = [];

    private static function errorFor(array $r): ?string
    {
        if ($r['status'] === 'uncertain' && empty($r['payload']['error']) && ($r['runner'] ?? null) !== null)
            return t('The GPU stopped answering while it made this video: its runtime was probably turned off or disconnected (in Colab Pro, keep the notebook\'s tab open). Make it again when the GPU is on.');
        if ($r['status'] === 'uncertain' && empty($r['payload']['error'])) {
            // How long the process ran until its last sign of life, and what it was doing: a host limit stops it at
            // about the same time every time.
            $ran = isset($r['started']) ? max(0, (int)$r['updated'] - (int)$r['started']) : null;
            $phase = self::phaseText((string)($r['phase'] ?? ''));
            $after = $ran === null ? null : ($phase !== null ? t('after {time}, {phase}', ['time'=>self::duration($ran), 'phase'=>$phase]) : t('after {time}', ['time'=>self::duration($ran)]));
            $limits = t('PHP-FPM request_terminate_timeout, FcgidIOTimeout, ProxyTimeout or LiteSpeed');
            // LiteSpeed says how long it lets each PHP process live (Hostinger: 120 s): when the request stopped right there, that's it.
            $limit = Spawn::webLimit();
            if ($limit !== null && $ran !== null && abs($ran - $limit) <= 15) $limits = t('this host\'s web server ends every PHP process at {time}, LSAPI_MAX_PROCESS_TIME', ['time'=>self::duration($limit)]);
            $charged = self::charged((string)($r['payload']['options']['model'] ?? ''));
            if (!empty($r['worker_lock'])) {
                return ($after === null ? t('The server process that was handling this request was stopped.') : t('The server process that was handling this request was stopped {after}.', ['after'=>$after]))
                    .' '.t('This is usually a time limit of the host ({limits}); if it always happens near the same time, that is the limit.', ['limits'=>$limits]).' '.$charged;
            }
            return ($after === null ? t('The server stopped answering during the generation, probably because of a time limit of the host ({limits}).', ['limits'=>$limits])
                : t('The server stopped answering during the generation {after}, probably because of a time limit of the host ({limits}).', ['after'=>$after, 'limits'=>$limits])).' '.$charged;
        }
        return $r['payload']['error'] ?? null;
    }

    /** That a request that stopped midway may still have been paid for, and where to look. */
    private static function charged(string $model): string
    {
        return Images::provider($model) === 'gemini'
            ? t('Google may have processed and charged the request; check the usage in Google AI Studio before repeating it.')
            : t('OpenAI may have processed and charged the request; check your account\'s dashboard before repeating it.');
    }

    private static function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60); $seconds %= 60;
        return $minutes ? $minutes.' min'.($seconds ? ' '.$seconds.' s' : '') : $seconds.' s';
    }

    /**
     * Never replay an uncertain remote request automatically: mark it and let the owner decide.
     *
     * A running request is interrupted when its process is gone, however long it has been running: the Máxima level,
     * with its planning, often takes more than 5 minutes. The process holds a lock on the request's own file from
     * before it claims the request until after it ends it, so a free lock on a request that is still running means
     * the process stopped without finishing (the host killed it). A process that holds its lock but has gone silent
     * for longer than one whole call to the provider is given up on too. Requests whose process could not take the
     * lock fall back to the heartbeat alone: STALE_AFTER seconds without one.
     */
    private function markStale(): void
    {
        $now = time();
        $q = $this->vault->db->prepare("SELECT id,status,updated,started,phase,worker_lock,runner FROM jobs WHERE status IN ('running','cancelling') AND updated<?");
        $q->execute([$now - self::CHECK_AFTER]);
        foreach ($q->fetchAll() as $r) {
            $silent = $now - (int)$r['updated']; $locked = !empty($r['worker_lock']);
            // A video on the GPU worker: the worker speaks every few seconds while it works, also while it uploads.
            if ($r['runner'] !== null) {
                if ($silent <= Gpu::STALE) continue;
                $u = $this->vault->db->prepare('UPDATE jobs SET status=? WHERE id=? AND status=? AND updated=?');
                $u->execute([$r['status'] === 'cancelling' ? 'cancelled' : 'uncertain', $r['id'], $r['status'], $r['updated']]);
                continue;
            }
            // A cancellation waits for the process only as long as before: whoever cancelled wants it gone.
            $limit = $r['status'] === 'cancelling' ? self::STALE_AFTER : ($locked ? $this->ceiling() : self::STALE_AFTER);
            if ($silent <= $limit && !($locked && !$this->workerAlive((string)$r['id']))) continue;
            $to = $r['status'] === 'cancelling' ? 'cancelled' : 'uncertain';
            // Only if nothing changed since it was read: a heartbeat in between means the process is still there.
            $u = $this->vault->db->prepare('UPDATE jobs SET status=? WHERE id=? AND status=? AND updated=?'); $u->execute([$to, $r['id'], $r['status'], $r['updated']]);
            if ($u->rowCount() && $to === 'uncertain') {
                $phase = preg_replace('/[^a-z]/', '', (string)($r['phase'] ?? ''));
                error_log('Atelier: request '.($locked && $silent <= $limit ? 'process ended' : 'went silent').' without finishing'
                    .(isset($r['started']) ? ' after '.max(0, (int)$r['updated'] - (int)$r['started']).' s' : '').($phase !== '' ? " (phase $phase)" : '').'; marked as interrupted');
            }
        }
        // Batch variation plans are only needed while their images run.
        $q = $this->vault->db->prepare('DELETE FROM plans WHERE updated<?'); $q->execute([$now - 86400]);
    }

    /** Silence after which even a process that still holds its lock is given up on: one whole provider call and then some. */
    private function ceiling(): int { return (int)($this->config['api_timeout'] ?? 600) + self::STALE_AFTER; }

    private function lockPath(string $id): string { Vault::validId($id); return $this->vault->dir.'/locks/'.$id.'.lock'; }

    /**
     * Takes the lock that shows other requests this process is working on the request.
     * @return resource|false|null the open, locked file; false when another process holds it (it is starting or
     *                             running this request); null when this host has no lock another process would see
     */
    public function lockWorker(string $id): mixed
    {
        $dir = $this->vault->dir.'/locks';
        if (!@is_dir($dir) && !@mkdir($dir, 0700, true) && !@is_dir($dir)) return null;
        $path = $this->lockPath($id);
        for ($try = 0; $try < 3; $try++) {
            $handle = @fopen($path, 'c'); if ($handle === false) return null;
            if (!@flock($handle, LOCK_EX | LOCK_NB, $busy)) { fclose($handle); return $busy ? false : null; }
            // The file may have been removed between opening and locking it (by whoever held it last): the lock must be
            // on the file that is there now, the one other requests will open.
            $held = @fstat($handle); $there = @stat($path);
            if ($held === false || $there === false || $held['ino'] !== $there['ino'] || $held['dev'] !== $there['dev']) { @flock($handle, LOCK_UN); fclose($handle); continue; }
            // A second handle of this same process must be refused. Where it isn't (locks per process, as on NFS), other
            // processes can't be trusted to see the lock either.
            $probe = @fopen($path, 'r'); $leaks = $probe === false || @flock($probe, LOCK_EX | LOCK_NB);
            if ($probe !== false) fclose($probe);
            if ($leaks) { @flock($handle, LOCK_UN); fclose($handle); return null; }
            return $handle;
        }
        return null;
    }

    /** Lets the lock go. Its file is removed only once the request has ended, while still locked. */
    public function unlockWorker(mixed $handle, string $id, bool $ended): void
    {
        if (!is_resource($handle)) return;
        if ($ended) @unlink($this->lockPath($id));
        @flock($handle, LOCK_UN); @fclose($handle);
    }

    /** Whether the process of a running request still holds its lock. */
    public function workerAlive(string $id): bool
    {
        $path = $this->lockPath($id);
        // The process creates the file before it claims the request, and only a holder of the lock removes it.
        $handle = @fopen($path, 'r'); if ($handle === false) return false;
        if (!@flock($handle, LOCK_EX | LOCK_NB, $busy)) { fclose($handle); return true; } // held (or unreadable: the ceiling decides)
        $held = @fstat($handle); $there = @stat($path);
        if ($held !== false && $there !== false && $held['ino'] === $there['ino']) @unlink($path);
        @flock($handle, LOCK_UN); fclose($handle);
        return false;
    }

    /**
     * history: the 50 most recent requests; recent: the 10 most recent.
     * active: queued/running requests plus the given ids (to learn how they ended).
     * feed: active requests plus recent unsuccessful ones that were not dismissed.
     */
    public function list(string $key, string $scope = 'history', array $ids = []): array
    {
        $this->markStale();
        $owner = $this->vault->owner();
        if ($scope === 'active') {
            $q = $this->vault->db->prepare("SELECT id FROM jobs WHERE user_id=? AND status IN ('queued','running','cancelling') ORDER BY created DESC, rowid DESC");
            $q->execute([$owner]);
        } elseif ($scope === 'feed') {
            $q = $this->vault->db->prepare("SELECT id FROM jobs WHERE user_id=? AND (status IN ('queued','running','cancelling') OR (status IN ('failed','uncertain') AND created>?)) ORDER BY created DESC, rowid DESC LIMIT 100");
            $q->execute([$owner, time() - 3 * 86400]);
        } else {
            $q = $this->vault->db->prepare('SELECT id FROM jobs WHERE user_id=? ORDER BY created DESC, rowid DESC LIMIT ?');
            $q->bindValue(1, $owner); $q->bindValue(2, $scope === 'recent' ? 10 : 50, \PDO::PARAM_INT); $q->execute();
        }
        $rows = []; $seen = [];
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $job = $this->find((string)$id, $key); if (!$job) continue;
            if ($scope === 'feed' && in_array($job['status'], ['failed','uncertain'], true) && !empty($job['payload']['dismissed'])) continue;
            $rows[] = $this->publicJob($job); $seen[$id] = true;
        }
        foreach (array_slice($ids, 0, 100) as $id) {
            if (!is_string($id) || isset($seen[$id]) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) continue;
            $job = $this->find($id, $key); if ($job) { $rows[] = $this->publicJob($job); $seen[$id] = true; }
        }
        return $rows;
    }

    /**
     * The account's requests made before $at ([created, rowid] of the last one read; null: from the newest), newest first:
     * where each one is, to read them one at a time with entry().
     * @return list<array{at:array{0:int,1:int},id:string}>
     */
    public function before(?array $at, int $limit): array
    {
        $sql = 'SELECT rowid AS r,id,created FROM jobs WHERE user_id=?'.($at !== null ? ' AND (created<? OR (created=? AND rowid<?))' : '').' ORDER BY created DESC, rowid DESC LIMIT ?';
        $q = $this->vault->db->prepare($sql);
        $args = [$this->vault->owner()]; if ($at !== null) array_push($args, (int)$at[0], (int)$at[0], (int)$at[1]); $args[] = $limit;
        foreach ($args as $n => $v) $q->bindValue($n + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $q->execute();
        return array_map(fn($r) => ['at'=>[(int)$r['created'], (int)$r['r']], 'id'=>(string)$r['id']], $q->fetchAll());
    }

    /** A request as the history shows it, with the album its images go into (a story's), or null when it is gone. */
    public function entry(string $id, string $key): ?array
    {
        $job = $this->find($id, $key); if (!$job) return null;
        return $this->publicJob($job);
    }

    /** Before any listing: requests whose process is gone are marked. */
    public function refresh(): void { $this->markStale(); }

    /** Queued requests are removed from the queue; running ones are interrupted at the next heartbeat. */
    public function cancel(string $id, string $key): array
    {
        Vault::validId($id); if (!$this->find($id, $key)) throw new HttpError(404, t('Request not found.'));
        $q = $this->vault->db->prepare("UPDATE jobs SET status='cancelled',updated=? WHERE id=? AND user_id=? AND status='queued'"); $q->execute([time(), $id, $this->vault->owner()]);
        if (!$q->rowCount()) {
            $q = $this->vault->db->prepare("UPDATE jobs SET status='cancelling' WHERE id=? AND user_id=? AND status='running'"); $q->execute([$id, $this->vault->owner()]);
            if (!$q->rowCount()) throw new HttpError(409, t('This request has already finished.'));
        }
        return $this->publicJob($this->find($id, $key));
    }

    /** Hide an unsuccessful request from the feed. It stays in the request history. */
    public function dismiss(string $id, string $key): void
    {
        Vault::validId($id); $job = $this->find($id, $key); if (!$job) throw new HttpError(404, t('Request not found.'));
        if (!in_array($job['status'], self::TERMINAL_UNSUCCESSFUL, true)) throw new HttpError(409, t('Only requests that ended without a result can be dismissed.'));
        $job['payload']['dismissed'] = true;
        $this->savePayload($id, $job['payload'], $key);
    }

    /**
     * @param ?\Closure $keepalive called about every 10 s while waiting, so the web server and proxies
     *                             don't treat a silent request as idle and kill the PHP worker.
     * @param ?\Closure $onClaimed called with the request once this process has claimed it, before any call to the
     *                             provider: the web route answers the browser there and goes on in the background.
     */
    public function run(string $id, string $key, ?ImageClient $api = null, ?\Closure $keepalive = null, ?\Closure $onClaimed = null): array
    {
        Vault::validId($id); $job = $this->find($id, $key); if (!$job) throw new HttpError(404, t('Request not found.'));
        // A video is made by the GPU worker, which takes it from the queue itself (Gpu).
        if ($job['runner'] !== null) return $this->publicJob($job);
        $lock = $this->lockWorker($id);
        // Another process holds this request's lock: it is starting or running it.
        if ($lock === false) return $this->publicJob($this->find($id, $key));
        $ended = false;
        try {
            $this->vault->db->exec('BEGIN IMMEDIATE');
            try {
                $this->markStale();
                // The limit is shared by every account: it protects the host's PHP workers.
                $q = $this->vault->db->prepare("UPDATE jobs SET status='running',phase='starting',updated=:now,started=:now,worker_lock=:lock WHERE id=:id AND user_id=:owner AND status='queued' AND runner IS NULL AND (SELECT COUNT(*) FROM jobs WHERE status IN ('running','cancelling') AND runner IS NULL)<:max");
                // Bound as integers: SQLite ranks any number below any text, so a string limit would never hold.
                $q->bindValue(':now', time(), \PDO::PARAM_INT); $q->bindValue(':lock', $lock === null ? 0 : 1, \PDO::PARAM_INT);
                $q->bindValue(':id', $id); $q->bindValue(':owner', $this->vault->owner()); $q->bindValue(':max', $this->maxParallel(), \PDO::PARAM_INT);
                $q->execute();
                $claimed = $q->rowCount() === 1; $this->vault->db->exec('COMMIT');
            } catch (\Throwable $e) { $this->vault->db->exec('ROLLBACK'); throw $e; }
            if (!$claimed) return $this->publicJob($this->find($id, $key));
            try { $job = $this->withInputs($job, $key); }
            catch (HttpError $e) {
                $job['payload']['error'] = $e->getMessage();
                $q = $this->vault->db->prepare("UPDATE jobs SET payload=CAST(? AS BLOB),status='failed',phase=NULL,updated=? WHERE id=?");
                $q->execute([$this->sealPayload($id, $job['payload'], $key), time(), $id]); $ended = true;
                return $this->publicJob($this->find($id, $key));
            }
            if ($onClaimed !== null) {
                try { $onClaimed($this->publicJob($this->find($id, $key))); } catch (\Throwable $e) { logFailure($e); }
            }
            $result = $this->work($id, $job, $key, $api, $keepalive); $ended = true;
            return $result;
        } finally {
            $this->unlockWorker($lock, $id, $ended);
        }
    }

    /**
     * Runs a queued request in a separate command line process (bin/worker.php), which the web server doesn't stop as it
     * stops its own (Hostinger: at 120 s). Returns the request once that process has taken it or left it in line (no
     * room yet), or null when it couldn't work here: then the caller runs it itself.
     */
    public function runSeparately(string $id, string $key, ?string $script = null): ?array
    {
        Vault::validId($id); $job = $this->find($id, $key); if (!$job) throw new HttpError(404, t('Request not found.'));
        if ($job['status'] !== 'queued' || $job['runner'] !== null) return $this->publicJob($job);
        $dir = $this->vault->dir;
        if ($script === null && Spawn::failedRecently($dir)) return null;
        $said = Spawn::run($dir, $script ?? Spawn::worker(), ['job', $id, $this->vault->owner(), $dir], sodium_bin2hex($key));
        if ($said === 'claimed' || $said === 'idle') { Spawn::outcome($dir, null); return $this->freshJob($id, $key) ?? $this->publicJob($job); }
        if ($said === null || str_starts_with($said, 'failed')) Spawn::outcome($dir, $said === null ? t('did not answer in 6 s') : substr($said, 8));
        return null;
    }

    /**
     * Starts one separate process that runs these requests one after another, each as soon as there is room (MCP, which
     * has no page to launch them). False when it couldn't work here.
     */
    public function startSeparately(array $ids, string $key, ?string $script = null): bool
    {
        $dir = $this->vault->dir;
        if (!$ids || ($script === null && Spawn::failedRecently($dir))) return false;
        $said = Spawn::run($dir, $script ?? Spawn::worker(), ['jobs', $this->vault->owner(), $dir, ...$ids], sodium_bin2hex($key));
        if ($said === 'claimed' || $said === 'idle') { Spawn::outcome($dir, null); return true; }
        if ($said === null || str_starts_with($said, 'failed')) Spawn::outcome($dir, $said === null ? t('did not answer in 6 s') : substr($said, 8));
        return false;
    }

    /** A request as it is now, read past this request's connection (see Vault::fresh). */
    public function freshJob(string $id, string $key): ?array
    {
        $r = $this->vault->fresh('SELECT '.self::COLUMNS.' FROM jobs WHERE id=? AND user_id=?', [$id, $this->vault->owner()]); if (!$r) return null;
        $r['payload'] = json_decode(Crypto::open($r['payload'], $key, "job:$id"), true, 512, JSON_THROW_ON_ERROR);
        return $this->publicJob($r);
    }

    /** Makes the images of a request this process claimed, and ends it. */
    private function work(string $id, array $job, string $key, ?ImageClient $api, ?\Closure $keepalive): array
    {
        $p = $job['payload']; $status = 'completed'; $from = null; $kept = false;
        try {
            $api ??= Images::client((string)($p['options']['model'] ?? ''), $this->vault, $key, $this->config);
            $from = $api->paidCount();
            if (isset($p['variation']) && !array_key_exists('direction', $p['variation'])) {
                $p = $this->resolveVariation($id, $p, $api, $key, $keepalive);
                $this->savePayload($id, $p, $key);
            }
            $result = $api->withMonitor($this->monitor($id, $keepalive))
                ->withPreview(fn(string $bytes, int $index) => $this->vault->savePreview($id, $bytes, $index, $key))
                ->generate($p);
            // The spending is kept first: the request is paid even if saving its images fails. The planning calls made
            // for it (the prompt, the directions of a varied batch) are paid apart from the image and kept with it.
            $kept = true;
            // The prompt exactly as it went to the image API, with what the server adds (the fidelity guard, the Limpar
            // notice): the details of the image show it. Kept with the request, not the image, so the library stays light.
            if (is_string($result['sent_prompt'] ?? null)) $p['sent_prompt'] = $result['sent_prompt'];
            try { $this->vault->recordSpend($id, [...$api->paid($from), ['model'=>(string)($p['options']['model'] ?? ''), 'usage'=>$result['usage'] ?? null]], $key); }
            catch (\Throwable $e) { logFailure($e); }
            $this->phase($id, 'saving');
            $title = $p['label'] ?? mb_substr(preg_replace('/\s+/u', ' ', $p['prompt']), 0, 80);
            foreach ($result['data'] as $data) {
                $bytes = base64_decode($data['b64_json'] ?? '', true);
                if ($bytes === false || $bytes === '') throw new HttpError(502, t('The API returned an empty image.'));
                $item = $this->vault->saveImage($bytes, ['title'=>$title,'prompt'=>$p['prompt'],'original_prompt'=>$p['prompt'],'planned_prompt'=>$result['planned_prompt'],
                    'planning_skipped'=>$result['planning_skipped'] ?? null,'options'=>$p['options'],'parent_ids'=>$p['parent_ids'],'parent_id'=>$p['parent_id'] ?? null,'operation'=>$p['operation'] ?? 'generate',
                    'label'=>$p['label'] ?? null,'job_id'=>$id,'usage'=>$result['usage'],'planning_usage'=>$result['planning_usage'],
                    'variation'=>self::variationInfo($p),'variation_usage'=>$p['variation_usage'] ?? null,'cleaned'=>self::cleanedOf($p)]
                    + (is_string($p['typed'] ?? null) ? ['typed'=>$p['typed']] : []), $key, is_string($p['album'] ?? null) ? $p['album'] : null);
                // It went into its album as it was saved (unless the album was deleted meanwhile).
                $p['image_ids'][] = $item['id'];
                // Checkpoint every output so a partial disk failure doesn't lose completed image IDs.
                $this->savePayload($id, $p, $key);
            }
        } catch (Cancelled) {
            $status = 'cancelled'; $p['error'] = Images::provider((string)($p['options']['model'] ?? '')) === 'gemini'
                ? t('Cancelled. If Google was already processing it, the request may still be charged.') : t('Cancelled. If OpenAI was already processing it, the request may still be charged.');
        } catch (\Throwable $e) {
            $status = 'failed'; $p['error'] = $e instanceof HttpError ? $e->getMessage() : t('Could not finish the request. The images already saved stay in the gallery.');
            // A case the page tells apart: no credit left at OpenAI shows a notice to top up, wherever the person is.
            if ($e instanceof HttpError && $e->kind !== null) $p['error_kind'] = $e->kind;
        }
        // A request that ended without an image still paid for the planning that came before it.
        if (!$kept && $api !== null && $from !== null) {
            try { $this->vault->recordSpend($id, $api->paid($from), $key); } catch (\Throwable $e) { logFailure($e); }
        }
        // Also when the request was marked as interrupted meanwhile (its process went silent but lived): it ends as it did.
        $q = $this->vault->db->prepare('UPDATE jobs SET payload=CAST(? AS BLOB),status=?,phase=NULL,updated=? WHERE id=?');
        $q->execute([$this->sealPayload($id, $p, $key), $status, time(), $id]);
        $this->vault->deletePreview($id);
        return $this->publicJob($this->find($id, $key));
    }

    /**
     * Runs a request in this PHP process, waiting for a free slot first. MCP clients use it: they have no browser to
     * launch and poll requests. If another process claimed the request (a browser signed in to the same account),
     * waits for that one to end instead. Returns early, still active, at the deadline.
     */
    public function runAndWait(string $id, string $key, ?ImageClient $api = null, ?\Closure $keepalive = null, ?int $deadline = null): array
    {
        $deadline ??= time() + 900;
        while (true) {
            $job = $this->run($id, $key, $api, $keepalive);
            if (!in_array($job['status'], ['queued','running','cancelling'], true) || time() >= $deadline) return $job;
            if ($keepalive !== null) $keepalive();
            usleep(1500000);
        }
    }

    /** What the gallery shows about the variation of one image: its place in the batch and its direction. */
    private static function variationInfo(array $p): ?array
    {
        $v = $p['variation'] ?? null; if (!is_array($v)) return null;
        return ['index'=>(int)$v['index'], 'count'=>(int)$v['count'], 'label'=>$v['label'] ?? null, 'planned'=>!empty($v['planned']), 'written'=>!empty($v['written'])];
    }

    /**
     * The direction of one image of a varied batch. Whichever job of the batch runs first asks the planning model for
     * all the directions at once; the others wait for that answer. If planning fails, every image takes a fixed
     * direction instead, so a planning problem never costs the images themselves.
     */
    private function resolveVariation(string $id, array $p, ImageClient $api, string $key, ?\Closure $keepalive): array
    {
        $v = $p['variation'];
        if (!isset($v['batch'])) return $this->withDirection($p, null); // queued before batches were planned
        $batch = (string)$v['batch']; $index = (int)$v['index'];
        $monitor = $this->monitor($id, $keepalive); $monitor('varying');
        $deadline = time() + self::STALE_AFTER;
        while (true) {
            $q = $this->vault->db->prepare('SELECT status,payload,updated FROM plans WHERE id=?'); $q->execute([$batch]); $plan = $q->fetch() ?: null;
            if ($plan === null || ($plan['status'] === 'planning' && (int)$plan['updated'] < time() - 60)) {
                // Claim the planning: a new row, or one whose planner stopped answering.
                $q = $plan === null
                    ? $this->vault->db->prepare("INSERT OR IGNORE INTO plans(id,status,payload,updated) VALUES(?,'planning',NULL,?)")
                    : $this->vault->db->prepare("UPDATE plans SET updated=? WHERE id=? AND status='planning' AND updated=?");
                $q->execute($plan === null ? [$batch, time()] : [time(), $batch, (int)$plan['updated']]);
                if ($q->rowCount() !== 1) continue;
                $touched = time();
                $planner = $api->withMonitor(function (?string $phase) use ($monitor, $batch, &$touched): bool {
                    if (time() - $touched >= 5) {
                        $touched = time();
                        try { $this->vault->db->prepare("UPDATE plans SET updated=? WHERE id=? AND status='planning'")->execute([$touched, $batch]); } catch (\PDOException) {}
                    }
                    return $monitor($phase);
                });
                try {
                    $result = $planner->planVariations($p['prompt'], $p['references'] ?? [], (int)$v['count'], ($p['operation'] ?? 'generate') === 'edit');
                } catch (Cancelled $e) {
                    // Let another image of the batch plan instead.
                    $this->vault->db->prepare('DELETE FROM plans WHERE id=?')->execute([$batch]);
                    throw $e;
                } catch (\Throwable $e) {
                    error_log('Atelier: variation planning failed ('.($e instanceof HttpError ? 'HTTP '.$e->status : get_class($e)).')');
                    $this->vault->db->prepare("UPDATE plans SET status='failed',payload=NULL,updated=? WHERE id=?")->execute([time(), $batch]);
                    return $this->withDirection($p, null);
                }
                $this->vault->db->prepare("UPDATE plans SET status='ready',payload=CAST(? AS BLOB),updated=? WHERE id=?")
                    ->execute([Crypto::seal(json_encode($result['variations'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "plan:$batch"), time(), $batch]);
                $p['variation_usage'] = $result['usage'];
                return $this->withDirection($p, $result['variations'][$index - 1] ?? null);
            }
            if ($plan['status'] === 'ready') {
                $list = json_decode(Crypto::open($plan['payload'], $key, "plan:$batch"), true, 512, JSON_THROW_ON_ERROR);
                return $this->withDirection($p, is_string($list[$index - 1] ?? null) ? $list[$index - 1] : null);
            }
            if ($plan['status'] !== 'planning' || time() > $deadline) return $this->withDirection($p, null);
            if ($monitor(null)) throw new Cancelled();
            usleep(500000);
        }
    }

    private function withDirection(array $p, ?string $planned): array
    {
        $fixed = OpenAI::fixedVariation((int)$p['variation']['index']);
        $p['variation'] += $planned !== null
            ? ['direction'=>'Direction for this image: '.$planned, 'label'=>$planned, 'planned'=>true]
            : ['direction'=>$fixed['direction'], 'label'=>$fixed['label'], 'planned'=>false];
        return $p;
    }

    /**
     * Called by the API client with a phase name when a step starts, and with null about once per second
     * while waiting for the provider. Refreshes the heartbeat at most every 3 seconds. A request marked as
     * interrupted while this process was silent but alive is running again.
     * Returns true when the owner asked to cancel.
     */
    private function monitor(string $id, ?\Closure $keepalive): \Closure
    {
        $last = 0; $lastKeepalive = time();
        return function (?string $phase) use ($id, $keepalive, &$last, &$lastKeepalive): bool {
            $now = time();
            if ($keepalive !== null && $now - $lastKeepalive >= 10) { $lastKeepalive = $now; $keepalive(); }
            if ($phase === null && $now - $last < 3) return false;
            $last = $now;
            try {
                $q = $this->vault->db->prepare("UPDATE jobs SET updated=?,phase=COALESCE(?,phase),status=CASE status WHEN 'uncertain' THEN 'running' ELSE status END WHERE id=?"); $q->execute([$now, $phase, $id]);
                $q = $this->vault->db->prepare('SELECT status FROM jobs WHERE id=?'); $q->execute([$id]);
                return $q->fetchColumn() === 'cancelling';
            } catch (\PDOException) {
                return false; // A busy database must never abort a paid request.
            }
        };
    }

    private function phase(string $id, string $phase): void
    { $q = $this->vault->db->prepare('UPDATE jobs SET phase=?,updated=? WHERE id=?'); $q->execute([$phase, time(), $id]); }

    // -------------------------------------------------------------------------------------------------------------
    // Videos, made by the GPU worker (Gpu, gpu.php)
    // -------------------------------------------------------------------------------------------------------------

    /**
     * A video request: the prompt, the model and its options, and what it brings (inputs): pictures (a gallery id or a
     * data URL) pinned at a moment of the video (role key, at: seconds or 'end') or taken as references (ref), videos of
     * the library (ref, or the source the video starts from) and sounds (a data URL, key or ref). It waits in line until
     * the GPU worker takes it. The pictures go with the request as data URLs (references); the videos stay in the library
     * and the worker downloads them (videoMedia).
     * @return list<array> the request, as create() returns its jobs
     */
    public function createVideo(array $input, string $key): array
    {
        $id = (string)($input['id'] ?? ''); Vault::validId($id);
        $existing = $this->batch($id, 1, $key); if ($existing) return $existing;
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 8000) throw new HttpError(422, t('Describe the video in up to 8,000 characters.'));
        $raw = $input['inputs'] ?? [];
        if (!is_array($raw) || count($raw) > 16) throw new HttpError(422, t('Invalid picture.'));
        $inputs = [];
        foreach (array_values($raw) as $in) {
            if (!is_array($in) || !in_array($in['kind'] ?? null, ['image','video','audio'], true) || !in_array($in['role'] ?? null, ['key','ref','source'], true)) throw new HttpError(422, t('Invalid picture.'));
            $inputs[] = ['kind'=>$in['kind'], 'role'=>$in['role']] + (array_key_exists('at', $in) ? ['at'=>$in['at']] : []);
        }
        [$options, $inputs] = Gpu::options(is_array($input['options'] ?? null) ? $input['options'] : [], $inputs);
        $engine = Gpu::engine($options['engine']);
        $references = []; $media = []; $parents = [];
        foreach (array_values($raw) as $n => $in) {
            $entry = $inputs[$n];
            if ($entry['kind'] === 'image') {
                if (isset($in['id'])) {
                    $im = $this->vault->still((string)$in['id'], $key); $parents[] = $im['id']; $entry['id'] = $im['id'];
                    $url = 'data:'.$im['mime'].';base64,'.base64_encode($this->vault->bytes($im['id'], 'full', $key));
                } else $url = (string)($in['data'] ?? '');
                $entry['ref'] = count($references); $references[] = OpenAI::imageInput($url)['url'];
            } elseif ($entry['kind'] === 'video') {
                $video = $this->vault->video((string)($in['id'] ?? ''), $key);
                $limits = $engine['limits']['video_seconds'];
                if ($entry['role'] === 'ref' && (float)$video['duration'] < $limits[0]) throw new HttpError(422, t('Reference videos need at least {n} seconds.', ['n'=>$limits[0]]));
                $entry += ['id'=>$video['id'], 'duration'=>(float)$video['duration'], 'width'=>(int)$video['width'], 'height'=>(int)$video['height']];
                $parents[] = $video['id'];
            } else {
                // A sound goes with the pictures, in the request's private file, not in its row.
                $sound = self::audioInput((string)($in['data'] ?? ''), (string)($in['name'] ?? ''), (int)$engine['limits']['audio_mb']);
                $entry += ['ref'=>count($references), 'name'=>$sound['name']]; $references[] = $sound['data'];
            }
            $media[] = $entry;
        }
        $label = trim(preg_replace('/\s+/u', ' ', (string)($input['label'] ?? '')) ?? '');
        $payload = ['kind'=>'video','prompt'=>$prompt,'options'=>$options,'references'=>$references,'media'=>$media,'roles'=>array_map(fn($m) => $m['role'], $media),
            'parent_ids'=>array_values(array_unique($parents)),'mask'=>null,'parent_id'=>null,'operation'=>'video','label'=>$label === '' ? null : mb_substr($label, 0, 200),'image_ids'=>[],'error'=>null];
        if (isset($input['album'])) { Vault::validId((string)$input['album']); if ($this->vault->hasAlbum((string)$input['album'])) $payload['album'] = (string)$input['album']; }
        $this->insert($id, $payload, $key, 'gpu');
        return [$this->publicJob($this->find($id, $key))];
    }

    /** A sound sent with a video request, as a data URL of up to $mb MB (MP3, WAV, OGG, M4A, AAC, FLAC or WebM). */
    private static function audioInput(string $url, string $name, int $mb): array
    {
        if (!preg_match('#\Adata:(audio/(?:mpeg|mp3|wav|x-wav|wave|vnd\.wave|ogg|mp4|x-m4a|m4a|aac|flac|x-flac|webm));base64,([A-Za-z0-9+/=]+)\z#D', $url, $m))
            throw new HttpError(422, t('Use a sound in MP3, WAV, OGG, M4A, AAC or FLAC.'));
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') throw new HttpError(422, t('Use a sound in MP3, WAV, OGG, M4A, AAC or FLAC.'));
        if (strlen($bytes) > $mb * 1048576) throw new HttpError(422, t('Use sounds of up to {n} MB.', ['n'=>$mb]));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        return ['data'=>$url, 'name'=>mb_substr($name, 0, 120)];
    }

    /** A video request the worker is making (or was, when it went silent), or a 409 the worker reads as "drop it". */
    private function videoJob(string $id, string $key, array $statuses = ['running','cancelling','uncertain']): array
    {
        Vault::validId($id); $job = $this->find($id, $key);
        if (!$job || $job['runner'] === null) throw new HttpError(404, t('Request not found.'));
        if (!in_array($job['status'], $statuses, true)) throw new HttpError(409, t('This request is no longer running.'));
        return $job;
    }

    /** A video of a request the worker is making ($index: its place in the request's list), to send it to the worker. */
    public function videoMedia(string $id, int $index, string $key): array
    {
        $m = $this->videoJob($id, $key, ['running','uncertain'])['payload']['media'][$index] ?? null;
        if (!is_array($m) || $m['kind'] !== 'video') throw new HttpError(404, t('Video not found.'));
        return $this->vault->video((string)$m['id'], $key);
    }

    /**
     * The oldest video request of the account that the worker can make ($engines: the models it runs; none: any), taken
     * for it, with the prompt and the pictures. Null when there is none.
     */
    public function claimVideo(array $engines, string $key): ?array
    {
        $this->markStale();
        $q = $this->vault->db->prepare("SELECT id FROM jobs WHERE user_id=? AND runner='gpu' AND status='queued' ORDER BY created, rowid LIMIT 20");
        $q->execute([$this->vault->owner()]); $ids = $q->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $job = $this->find((string)$id, $key); if (!$job) continue;
            if ($engines && !in_array($job['payload']['options']['engine'] ?? '', $engines, true)) continue;
            $u = $this->vault->db->prepare("UPDATE jobs SET status='running',phase='claimed',progress=NULL,updated=:now,started=:now WHERE id=:id AND status='queued' AND runner='gpu'");
            $u->bindValue(':now', time(), \PDO::PARAM_INT); $u->bindValue(':id', $id); $u->execute();
            if ($u->rowCount() !== 1) continue;
            try { $job = $this->withInputs($job, $key); }
            catch (HttpError $e) { $this->endVideo((string)$id, $job['payload'], 'failed', $e->getMessage(), $key); continue; }
            $p = $job['payload'];
            // Pictures and sounds go as data URLs; a video goes as its place in the list and its size, for videoMedia.
            $inputs = [];
            foreach ($p['media'] ?? [] as $n => $m) {
                $entry = ['kind'=>$m['kind'], 'role'=>$m['role']] + (array_key_exists('at', $m) ? ['at'=>$m['at']] : []);
                if ($m['kind'] === 'image') $entry['data'] = $p['references'][$m['ref']] ?? '';
                elseif ($m['kind'] === 'audio') $entry += ['data'=>$p['references'][$m['ref']] ?? '', 'name'=>$m['name'] ?? ''];
                else {
                    try { $video = $this->vault->video((string)$m['id'], $key); }
                    catch (HttpError) { $this->endVideo((string)$id, $p, 'failed', t('A video of this request is no longer in the library.'), $key); continue 2; }
                    $entry += ['index'=>$n, 'size'=>(int)$video['size'], 'mime'=>(string)$video['mime'], 'duration'=>(float)$video['duration']];
                }
                $inputs[] = $entry;
            }
            return ['id'=>(string)$id, 'prompt'=>$p['prompt'], 'options'=>$p['options'], 'inputs'=>$inputs, 'created'=>(int)$job['created']];
        }
        return null;
    }

    /**
     * What the worker reports while it makes a video: its phase (loading, generating, decoding, uploading), the steps done
     * and their total, and a line of its own. Returns true when the owner asked to cancel it.
     */
    public function videoProgress(string $id, array $report, string $key): bool
    {
        $this->videoJob($id, $key);
        $phase = preg_replace('/[^a-z-]/', '', (string)($report['phase'] ?? '')) ?: 'generating';
        $progress = ['message'=>mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($report['message'] ?? '')) ?? ''), 0, 200)];
        foreach (['done','total','eta'] as $name) if (is_int($report[$name] ?? null) && $report[$name] >= 0) $progress[$name] = $report[$name];
        $q = $this->vault->db->prepare("UPDATE jobs SET updated=?,phase=?,progress=?,status=CASE status WHEN 'uncertain' THEN 'running' ELSE status END WHERE id=? AND user_id=? AND runner='gpu' AND status IN ('running','cancelling','uncertain')");
        $q->execute([time(), mb_substr($phase, 0, 30), json_encode($progress, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $id, $this->vault->owner()]);
        $q = $this->vault->db->prepare('SELECT status FROM jobs WHERE id=?'); $q->execute([$id]);
        return $q->fetchColumn() === 'cancelling';
    }

    /** Starts the upload of the finished video; its pieces go through Vault::appendUpload, checked against this request. */
    public function startVideoUpload(string $id, int $size, int $maxBytes, string $key): string
    {
        $job = $this->videoJob($id, $key, ['running','uncertain']);
        $upload = $this->vault->startUpload($size, $maxBytes);
        $job['payload']['upload'] = $upload;
        $this->savePayload($id, $job['payload'], $key); $this->phase($id, 'uploading');
        return $upload;
    }

    /** The upload of a request's video, to receive its pieces (the worker sends the request's id with each one). */
    public function videoUpload(string $id, string $upload, string $key): void
    {
        $job = $this->videoJob($id, $key, ['running','uncertain']);
        if (($job['payload']['upload'] ?? null) !== $upload) throw new HttpError(409, t('This upload belongs to another request.'));
        $q = $this->vault->db->prepare('UPDATE jobs SET updated=? WHERE id=?'); $q->execute([time(), $id]);
    }

    /**
     * The video is complete on the server: it becomes a library item with the request's prompt, the pictures it came from
     * and how it was made ($info: width, height, duration, fps, frames, audio, seed, and the seconds it took). Repeating the
     * call after a lost answer returns the request as it ended.
     */
    public function finishVideo(string $id, string $upload, array $info, string $poster, string $key): array
    {
        Vault::validId($id); $job = $this->find($id, $key);
        if ($job && $job['status'] === 'completed' && !empty($job['payload']['image_ids'])) return $this->publicJob($job);
        $job = $this->videoJob($id, $key, ['running','uncertain','cancelling']);
        $p = $job['payload'];
        if (($p['upload'] ?? null) !== $upload) throw new HttpError(409, t('This upload belongs to another request.'));
        $title = $p['label'] ?? mb_substr(preg_replace('/\s+/u', ' ', $p['prompt']) ?? '', 0, 80);
        $seed = is_int($info['seed'] ?? null) ? $info['seed'] : null;
        $item = $this->vault->finishUpload($upload, ['name'=>$title] + $info, $poster, $key,
            ['title'=>$title, 'prompt'=>$p['prompt'], 'original_prompt'=>$p['prompt'], 'options'=>['seed'=>$seed] + $p['options'], 'parent_ids'=>$p['parent_ids'] ?? [],
             'job_id'=>$id, 'generated'=>true, 'audio'=>($info['audio'] ?? false) === true, 'seed'=>$seed, 'engine'=>$p['options']['engine'] ?? null],
            is_string($p['album'] ?? null) ? $p['album'] : null);
        $p['image_ids'] = [$item['id']];
        $p['made'] = array_filter(['seed'=>$seed, 'seconds'=>is_int($info['took'] ?? null) ? $info['took'] : null, 'gpu'=>is_string($info['gpu'] ?? null) ? mb_substr($info['gpu'], 0, 80) : null], fn($v) => $v !== null);
        unset($p['upload']);
        $q = $this->vault->db->prepare("UPDATE jobs SET payload=CAST(? AS BLOB),status='completed',phase=NULL,progress=NULL,updated=? WHERE id=?");
        $q->execute([$this->sealPayload($id, $p, $key), time(), $id]);
        return $this->publicJob($this->find($id, $key));
    }

    /** The worker could not make the video ($error says why), or stopped it because the owner cancelled it. */
    public function failVideo(string $id, string $error, bool $cancelled, string $key): array
    {
        Vault::validId($id); $job = $this->find($id, $key);
        if (!$job || $job['runner'] === null) throw new HttpError(404, t('Request not found.'));
        if (!in_array($job['status'], ['running','cancelling','uncertain'], true)) return $this->publicJob($job);
        $error = mb_substr(trim(preg_replace('/\s+/u', ' ', $error) ?? ''), 0, 600);
        $cancelled = $cancelled || $job['status'] === 'cancelling';
        $this->endVideo($id, $job['payload'], $cancelled ? 'cancelled' : 'failed',
            $cancelled ? t('Cancelled. The GPU stopped making the video.') : ($error === '' ? t('The GPU could not make the video.') : t('The GPU could not make the video: {error}', ['error'=>$error])), $key);
        return $this->publicJob($this->find($id, $key));
    }

    private function endVideo(string $id, array $p, string $status, string $error, string $key): void
    {
        if (is_string($p['upload'] ?? null)) { try { $this->vault->abortUpload($p['upload']); } catch (\Throwable) { /* already gone */ } }
        unset($p['upload']); $p['error'] = $error;
        $q = $this->vault->db->prepare('UPDATE jobs SET payload=CAST(? AS BLOB),status=?,phase=NULL,progress=NULL,updated=? WHERE id=?');
        $q->execute([$this->sealPayload($id, $p, $key), $status, time(), $id]);
    }

    /** Updates the payload without touching the status, so a pending cancellation is preserved. */
    private function savePayload(string $id, array $payload, string $key): void
    {
        $q = $this->vault->db->prepare('UPDATE jobs SET payload=CAST(? AS BLOB),updated=? WHERE id=?');
        $q->execute([$this->sealPayload($id, $payload, $key), time(), $id]);
    }
}
