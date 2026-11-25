<?php
declare(strict_types=1);
namespace Studio;

/**
 * The video worker: a notebook on a GPU (Google Colab) that asks this server for the account's video requests, makes
 * them with open models and sends the videos back to the library.
 *
 * The worker only ever calls this server (gpu.php), with short requests, so nothing on the GPU is exposed to the internet
 * and no web request here waits for a video. It signs in with a token of the account, like the MCP one: the server keeps
 * a lookup id and the account key sealed with the token's random secret. The token reads the account's video requests
 * (their prompts and the pictures they start from) and adds videos to its library, nothing else.
 *
 * The worker says how it is (its GPU, what it is doing) on every call. It turns its runtime off by itself after the
 * account's idle minutes without a request, or when the person asks for it here (stop), so the GPU's credits aren't spent
 * on waiting.
 */
final class Gpu
{
    /** Seconds without a sign of life after which the worker counts as off. It speaks every few seconds. */
    public const ALIVE = 45;
    /** How long the page shows that the last notebook stopped because its GPU was too small (seconds). */
    public const WRONG_GPU_SHOWN = 6 * 3600;
    /** Seconds a running video may go without a sign of life before it counts as interrupted (the runtime went away). */
    public const STALE = 180;
    /** The notebook, opened in Colab straight from the public repository. */
    public const NOTEBOOK = 'https://colab.research.google.com/github/JohanKRS/atelier/blob/main/colab/atelier_gpu.ipynb';
    public const IDLE_CHOICES = [5, 10, 15, 20, 30, 45, 60];
    /** The qualities a picture can be saved at as a JPEG (Settings › GPU), and the one it starts at. */
    public const JPEG_QUALITY = [50, 100];
    public const DEFAULT_JPEG_QUALITY = 85;
    private const TOKEN = '/\Aigv_([a-f0-9]{24})_([A-Za-z0-9_-]{43})\z/D';

    public function __construct(private Vault $vault, private array $config = []) {}

    /**
     * The video models the worker can run. Each one is a ComfyUI workflow in the worker (colab/atelier_worker.py), which
     * downloads its files the first time it is used in a session. Two modes: new (from the prompt, with pictures pinned
     * at moments of the video, and pictures, videos and sounds it takes people, places, motion or voices from) and video
     * (from a video of the library: continue it, redo it following its movement, or replace a part of it).
     * limits: the most pictures, videos and sounds of a request; extra_gb: what each operation downloads besides the model.
     */
    public static function engines(): array
    {
        $aspects = ['16:9','9:16','1:1','4:3','3:4','3:2','2:3','21:9'];
        return [
            'h3'=>['label'=>'MiniMax H3','note'=>t('Video with sound in one pass: voices, effects and music. The best open model in the rankings of late 2026.'),
                'audio'=>true,'modes'=>['new','video'],'ops'=>['continue','pose','depth','edges','replace'],
                'limits'=>['images'=>9,'videos'=>3,'audios'=>3,'audio_mb'=>5,'video_seconds'=>[2,15]],
                'seconds'=>[5,15],'default_seconds'=>5,'fps'=>24,'aspects'=>$aspects,
                'qualities'=>['fast'=>t('Fast: Pika H3 Flex, 6 steps'),'balanced'=>t('Balanced: Turbo, 8 steps'),'max'=>t('Maximum: the full model, 20 steps')],'default_quality'=>'fast',
                'resolutions'=>['480p'=>'480p','768p'=>'768p'],'default_resolution'=>'768p','download_gb'=>45,
                'extra_gb'=>['pose'=>4.1,'depth'=>3.4,'edges'=>2.2,'replace'=>3.8],
                'license'=>t('MiniMax H3 Community License: its use is not allowed in the United States, the European Union, the United Kingdom or South Korea.')],
        ];
    }

    public static function engine(string $id): ?array { return self::engines()[$id] ?? null; }

    /**
     * The image models the worker can run (GpuImages has what the page and the requests know of them). download_gb: what
     * the first picture of a session downloads; planner_gb: the prompt expander, the first time planning is on.
     */
    public static function imageEngines(): array
    {
        return [
            'h3-image'=>['label'=>'MiniMax H3-Image', 'download_gb'=>47, 'planner_gb'=>6.8,
                'license'=>t('MiniMax-H3-Image Community License: its use is not allowed in the United States, the European Union, the United Kingdom or South Korea, and offering it to other people as a service needs MiniMax\'s authorization.')],
        ];
    }

    /** Every model the worker may say it runs: the video ones and the image ones. */
    public static function workerEngines(): array { return array_merge(array_keys(self::engines()), array_keys(self::imageEngines())); }

    public static function defaultEngine(): string { return array_key_first(self::engines()); }

    /**
     * The options of a video request, checked against its model and against what it brings ($inputs: kind, role and, for
     * the pictures and sounds pinned at a moment, at: seconds or 'end'). The inputs come back with their moments set.
     * @return array{0:array,1:list<array>} the options and the inputs
     */
    public static function options(array $input, array $inputs): array
    {
        $engine = (string)($input['engine'] ?? self::defaultEngine());
        $e = self::engine($engine) ?? throw new HttpError(422, t('Choose a video model.'));
        $mode = (string)($input['mode'] ?? 'new');
        if (!in_array($mode, $e['modes'], true)) throw new HttpError(422, t('{model} cannot make this kind of video.', ['model'=>$e['label']]));
        $seconds = filter_var($input['seconds'] ?? $e['default_seconds'], FILTER_VALIDATE_FLOAT);
        if ($seconds === false || $seconds < $e['seconds'][0] || $seconds > $e['seconds'][1])
            throw new HttpError(422, t('{model} makes videos from {min} to {max} seconds.', ['model'=>$e['label'], 'min'=>$e['seconds'][0], 'max'=>$e['seconds'][1]]));
        $seconds = round((float)$seconds, 2);
        $count = fn(string $kind, ?string $role = null) => count(array_filter($inputs, fn($i) => $i['kind'] === $kind && ($role === null || $i['role'] === $role)));
        $allowed = $mode === 'new' ? ['image'=>['key','ref'], 'video'=>['ref'], 'audio'=>['key','ref']] : ['image'=>['ref'], 'video'=>['source']];
        foreach ($inputs as $i) if (!in_array($i['role'], $allowed[$i['kind']] ?? [], true)) throw new HttpError(422, t('The pictures don\'t match the kind of video.'));
        if ($count('image') > $e['limits']['images']) throw new HttpError(422, t('Use up to {n} pictures.', ['n'=>$e['limits']['images']]));
        if ($count('video') > $e['limits']['videos']) throw new HttpError(422, t('Use up to {n} videos.', ['n'=>$e['limits']['videos']]));
        if ($count('audio') > $e['limits']['audios']) throw new HttpError(422, t('Use up to {n} sounds.', ['n'=>$e['limits']['audios']]));
        // A moment of the video for each pinned picture or sound: seconds from the start, or its end. Two pictures can't
        // share a moment.
        $moments = [];
        foreach ($inputs as &$i) {
            if ($i['role'] !== 'key') { unset($i['at']); continue; }
            $at = $i['at'] ?? null;
            if ($at !== 'end') {
                $at = filter_var($at, FILTER_VALIDATE_FLOAT);
                if ($at === false || $at < 0 || $at > $seconds) throw new HttpError(422, t('Choose a moment between 0 and {max} seconds.', ['max'=>$seconds]));
                $at = $at >= $seconds - 0.05 ? 'end' : round((float)$at, 2);
            }
            $i['at'] = $at;
            if ($i['kind'] === 'image') {
                if (in_array($at, $moments, true)) throw new HttpError(422, t('Two pictures can\'t be at the same moment.'));
                $moments[] = $at;
            }
        }
        unset($i);
        $options = ['engine'=>$engine, 'mode'=>$mode, 'seconds'=>$seconds];
        if ($mode === 'video') {
            if ($count('video', 'source') !== 1) throw new HttpError(422, t('Choose the video to start from.'));
            $op = (string)($input['op'] ?? '');
            if (!in_array($op, $e['ops'], true)) throw new HttpError(422, t('Choose what to do with the video.'));
            $options['op'] = $op;
            if ($op === 'replace') {
                $target = trim(preg_replace('/\s+/u', ' ', (string)($input['target'] ?? '')) ?? '');
                if ($target === '' || mb_strlen($target) > 120) throw new HttpError(422, t('Say what to replace in the video, in up to 120 characters.'));
                $options['target'] = $target;
            }
            if ($op === 'continue') $options['join'] = ($input['join'] ?? true) !== false;
            else $options['keep_audio'] = ($input['keep_audio'] ?? true) !== false;
        }
        $aspect = (string)($input['aspect'] ?? $e['aspects'][0]);
        if (!in_array($aspect, $e['aspects'], true)) throw new HttpError(422, t('Invalid aspect ratio.'));
        $quality = (string)($input['quality'] ?? $e['default_quality']);
        if (!isset($e['qualities'][$quality])) throw new HttpError(422, t('Invalid quality.'));
        $resolution = (string)($input['resolution'] ?? $e['default_resolution']);
        if (!isset($e['resolutions'][$resolution])) throw new HttpError(422, t('Invalid resolution.'));
        $seed = $input['seed'] ?? null;
        if ($seed !== null && (!is_int($seed) || $seed < 0 || $seed > 2 ** 53)) throw new HttpError(422, t('Invalid seed.'));
        return [$options + ['aspect'=>$aspect, 'quality'=>$quality, 'resolution'=>$resolution, 'seed'=>$seed, 'n'=>1], $inputs];
    }

    // -------------------------------------------------------------------------------------------------------------
    // The token
    // -------------------------------------------------------------------------------------------------------------

    /** A new worker token for the account, replacing any earlier one. It is shown once. */
    public function issueToken(string $userId, string $key): string
    {
        $lookup = bin2hex(random_bytes(12)); $secret = random_bytes(32);
        $sealed = Crypto::seal($key, $secret, "gpu:$lookup");
        $this->vault->db->prepare('INSERT INTO gpu_workers(user_id,token_id,token_key,created,used) VALUES(?,?,CAST(? AS BLOB),?,NULL)
            ON CONFLICT(user_id) DO UPDATE SET token_id=excluded.token_id,token_key=excluded.token_key,created=excluded.created,used=NULL')
            ->execute([$userId, $lookup, $sealed, time()]);
        return 'igv_'.$lookup.'_'.sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** Whether the account has a worker token: without one, no GPU will ever take its requests. */
    public function hasToken(): bool
    {
        $row = $this->row();
        return $row !== null && $row['token_id'] !== null;
    }

    /** The token stops working at once; a worker that is on finds out on its next call and turns off. */
    public function revokeToken(string $userId): void
    {
        $this->vault->db->prepare('UPDATE gpu_workers SET token_id=NULL,token_key=NULL,created=NULL,used=NULL,seen=NULL,state=NULL WHERE user_id=?')->execute([$userId]);
    }

    /** @return ?array{0:array,1:string} the account and its key, or null for an unknown or revoked token */
    public function resolveToken(string $token): ?array
    {
        if (!preg_match(self::TOKEN, $token, $m)) return null;
        $q = $this->vault->db->prepare('SELECT w.token_key, w.used, u.* FROM gpu_workers w JOIN users u ON u.id=w.user_id WHERE w.token_id=?');
        $q->execute([$m[1]]); $row = $q->fetch(); $q->closeCursor();
        if (!$row) return null;
        try { $key = Crypto::open((string)$row['token_key'], sodium_base642bin($m[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 'gpu:'.$m[1]); }
        catch (\Throwable) { return null; }
        if ((int)$row['used'] < time() - 60) {
            try { $this->vault->db->prepare('UPDATE gpu_workers SET used=? WHERE user_id=?')->execute([time(), $row['id']]); } catch (\PDOException) { /* only a statistic */ }
        }
        unset($row['token_key'], $row['used']);
        return [$row, $key];
    }

    // -------------------------------------------------------------------------------------------------------------
    // The worker's state, as the page shows it
    // -------------------------------------------------------------------------------------------------------------

    private function row(): ?array
    {
        $q = $this->vault->db->prepare('SELECT * FROM gpu_workers WHERE user_id=?'); $q->execute([$this->vault->owner()]);
        $row = $q->fetch(); $q->closeCursor();
        return $row ?: null;
    }

    /**
     * What the page shows: whether there is a token, the worker (when it is on), the idle minutes, how the pictures are
     * saved and the queue.
     */
    public function status(): array
    {
        $row = $this->row(); $now = time();
        $state = is_string($row['state'] ?? null) ? json_decode((string)$row['state'], true) : null;
        $alive = $row !== null && $row['seen'] !== null && $now - (int)$row['seen'] <= self::ALIVE && is_array($state) && ($state['phase'] ?? '') !== 'off';
        $idle = (int)($row['idle_minutes'] ?? 15);
        $worker = null;
        if ($alive) {
            $worker = array_intersect_key($state, array_flip(['gpu','vram','phase','message','started','idle_since','engines','loaded','version','progress','making']));
            $worker['seen'] = (int)$row['seen'];
            // When it turns itself off if no request comes, by the idle minutes it was last told.
            $worker['off_at'] = is_int($state['idle_since'] ?? null) ? $state['idle_since'] + $idle * 60 : null;
            $worker['stopping'] = $this->stopWanted($row, $state);
        }
        // The last notebook turned itself off because Colab gave it a GPU too small for the models: the page says so
        // for a few hours, so whoever started it learns what to change.
        $last = null;
        if (!$alive && is_array($state) && ($state['ended'] ?? null) === 'gpu' && $row['seen'] !== null && $now - (int)$row['seen'] <= self::WRONG_GPU_SHOWN)
            $last = ['why'=>'gpu', 'gpu'=>(string)($state['gpu'] ?? ''), 'vram'=>is_int($state['vram'] ?? null) ? $state['vram'] : null, 'at'=>(int)$row['seen']];
        $q = $this->vault->db->prepare("SELECT status, COUNT(*) AS n FROM jobs WHERE user_id=? AND runner='gpu' AND status IN ('queued','running','cancelling') GROUP BY status");
        $q->execute([$this->vault->owner()]); $counts = array_column($q->fetchAll(), 'n', 'status');
        return ['token'=>$row !== null && $row['token_id'] !== null ? ['created'=>(int)$row['created'], 'used'=>$row['used'] === null ? null : (int)$row['used']] : null,
            'worker'=>$worker, 'last'=>$last, 'idle_minutes'=>$idle, 'idle_choices'=>self::IDLE_CHOICES,
            'pictures'=>$this->pictureFormat() + ['range'=>self::JPEG_QUALITY], 'queued'=>(int)($counts['queued'] ?? 0),
            'running'=>(int)($counts['running'] ?? 0) + (int)($counts['cancelling'] ?? 0), 'notebook'=>(string)($this->config['video_notebook'] ?? self::NOTEBOOK),
            'engines'=>self::engines(), 'image_engines'=>self::imageEngines(), 'now'=>$now];
    }

    /**
     * How the worker saves the pictures it makes, before sending them: as each request asks (PNG unless it asks for JPEG
     * or WebP), or, with jpeg on, a picture that would be a PNG as a JPEG at this quality. A PNG of 8K or more is several
     * times the size of a JPEG at 85, with no difference to see.
     * @return array{jpeg:bool,quality:int}
     */
    public function pictureFormat(): array
    {
        $row = $this->row();
        $quality = (int)($row['picture_quality'] ?? 0);
        return ['jpeg'=>(int)($row['picture_jpeg'] ?? 0) === 1, 'quality'=>$quality >= self::JPEG_QUALITY[0] && $quality <= self::JPEG_QUALITY[1] ? $quality : self::DEFAULT_JPEG_QUALITY];
    }

    public function setPictureFormat(bool $jpeg, mixed $quality = null): void
    {
        $quality = $quality === null ? $this->pictureFormat()['quality'] : filter_var($quality, FILTER_VALIDATE_INT);
        if ($quality === false || $quality < self::JPEG_QUALITY[0] || $quality > self::JPEG_QUALITY[1])
            throw new HttpError(422, t('Choose a JPEG quality from {min} to {max}.', ['min'=>self::JPEG_QUALITY[0], 'max'=>self::JPEG_QUALITY[1]]));
        $this->ensureRow();
        $this->vault->db->prepare('UPDATE gpu_workers SET picture_jpeg=?, picture_quality=? WHERE user_id=?')->execute([$jpeg ? 1 : 0, $quality, $this->vault->owner()]);
    }

    /**
     * The options of a GPU picture request in the format it is saved in: a PNG becomes a JPEG at the account's quality
     * when Settings › GPU says so. A request that asks for JPEG or WebP keeps its own format and quality.
     */
    public function pictureOptions(array $options): array
    {
        $f = $this->pictureFormat();
        if ($f['jpeg'] && ($options['format'] ?? 'png') === 'png') { $options['format'] = 'jpeg'; $options['compression'] = $f['quality']; }
        return $options;
    }

    public function setIdleMinutes(int $minutes): void
    {
        if (!in_array($minutes, self::IDLE_CHOICES, true)) throw new HttpError(422, t('Choose how many minutes without requests turn the GPU off.'));
        $this->ensureRow();
        $this->vault->db->prepare('UPDATE gpu_workers SET idle_minutes=? WHERE user_id=?')->execute([$minutes, $this->vault->owner()]);
    }

    /** Asks the worker that is on to turn its runtime off now (it finishes the video it is making first, unless cancelled). */
    public function requestStop(): void
    {
        $this->ensureRow();
        $this->vault->db->prepare('UPDATE gpu_workers SET stop=? WHERE user_id=?')->execute([time(), $this->vault->owner()]);
    }

    private function ensureRow(): void
    {
        $this->vault->db->prepare('INSERT OR IGNORE INTO gpu_workers(user_id) VALUES(?)')->execute([$this->vault->owner()]);
    }

    /** A stop asked for after this session of the worker started (an older one must not turn off the next session). */
    private function stopWanted(array $row, ?array $state): bool
    {
        $stop = (int)($row['stop'] ?? 0);
        return $stop > 0 && $stop >= (int)($state['started'] ?? 0);
    }

    // -------------------------------------------------------------------------------------------------------------
    // LoRAs: the account's list of small add-on files for the GPU's image models (a style, a character, a technique),
    // each with a link the worker downloads it from the first time a session uses it. Kept sealed with the account key.
    // -------------------------------------------------------------------------------------------------------------

    public const MAX_LORAS = 60;

    /** @return list<array{id:string,name:string,url:string,trigger:string,strength:float,engine:string,added:int}> */
    public function loras(string $key): array
    {
        $row = $this->row(); $sealed = $row['loras'] ?? null;
        if (!is_string($sealed) || $sealed === '') return [];
        try { $list = json_decode(Crypto::open($sealed, $key, 'gpu:loras:'.$this->vault->owner()), true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { logFailure($e); return []; }
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** Adds a LoRA to the list, or changes the one with this id. Returns the list. */
    public function saveLora(array $input, string $key): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($input['name'] ?? '')) ?? '');
        $trigger = trim(preg_replace('/\s+/u', ' ', (string)($input['trigger'] ?? '')) ?? '');
        $url = self::loraUrl((string)($input['url'] ?? ''));
        $strength = filter_var($input['strength'] ?? 1, FILTER_VALIDATE_FLOAT);
        if ($strength === false || $strength < GpuImages::LORA_STRENGTH[0] || $strength > GpuImages::LORA_STRENGTH[1])
            throw new HttpError(422, t('Choose a LoRA strength from {min} to {max}.', ['min'=>GpuImages::LORA_STRENGTH[0], 'max'=>GpuImages::LORA_STRENGTH[1]]));
        $engine = (string)($input['engine'] ?? 'h3-image');
        if (!isset(self::imageEngines()[$engine])) throw new HttpError(422, t('Choose the model the LoRA was made for.'));
        if ($name === '') $name = self::nameFromUrl($url);
        $list = $this->loras($key);
        $id = is_string($input['id'] ?? null) && $input['id'] !== '' ? $input['id'] : null;
        if ($id !== null && !preg_match('/\A[a-f0-9]{16}\z/D', $id)) throw new HttpError(422, t('Invalid LoRA.'));
        $entry = ['id'=>$id ?? bin2hex(random_bytes(8)), 'name'=>mb_substr($name, 0, 80), 'url'=>$url, 'trigger'=>mb_substr($trigger, 0, 200),
            'strength'=>round((float)$strength, 2), 'engine'=>$engine, 'added'=>time()];
        $found = false;
        foreach ($list as $n => $l) if (($l['id'] ?? null) === $entry['id']) { $list[$n] = ['added'=>$l['added'] ?? time()] + $entry; $found = true; }
        if ($id !== null && !$found) throw new HttpError(404, t('This LoRA is no longer in your list.'));
        if (!$found) {
            if (count($list) >= self::MAX_LORAS) throw new HttpError(422, t('Keep up to {n} LoRAs in the list.', ['n'=>self::MAX_LORAS]));
            $list[] = $entry;
        }
        $this->storeLoras($list, $key);
        return $list;
    }

    public function deleteLora(string $id, string $key): array
    {
        $list = array_values(array_filter($this->loras($key), fn($l) => ($l['id'] ?? null) !== $id));
        $this->storeLoras($list, $key);
        return $list;
    }

    private function storeLoras(array $list, string $key): void
    {
        $this->ensureRow();
        $sealed = Crypto::seal(json_encode(array_values($list), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $key, 'gpu:loras:'.$this->vault->owner());
        $this->vault->db->prepare('UPDATE gpu_workers SET loras=CAST(? AS BLOB) WHERE user_id=?')->execute([$sealed, $this->vault->owner()]);
    }

    /**
     * The LoRAs a request chose ($chosen: id and strength), with what the worker needs of each: its name, link and
     * trigger words. One the list no longer has keeps what the request had ($before, when it is repeated).
     * @return list<array{id:string,name:string,url:string,trigger:string,strength:float}>
     */
    public function resolveLoras(array $chosen, string $engine, string $key, array $before = []): array
    {
        if (!$chosen) return [];
        $list = array_column($this->loras($key), null, 'id'); $old = array_column(array_filter($before, 'is_array'), null, 'id');
        $out = [];
        foreach ($chosen as $c) {
            $l = $list[$c['id']] ?? $old[$c['id']] ?? null;
            if ($l === null || !is_string($l['url'] ?? null)) throw new HttpError(422, t('A LoRA of this request is no longer in your list (Settings › GPU).'));
            if (($l['engine'] ?? 'h3-image') !== $engine) throw new HttpError(422, t('The LoRA {name} is for another model.', ['name'=>$l['name'] ?? '']));
            $out[] = ['id'=>$c['id'], 'name'=>(string)($l['name'] ?? ''), 'url'=>(string)$l['url'], 'trigger'=>(string)($l['trigger'] ?? ''), 'strength'=>(float)$c['strength']];
        }
        return $out;
    }

    /**
     * A LoRA's link as the worker downloads it: https only; a Hugging Face file page becomes its download link, and a
     * Civitai page with its version (?modelVersionId=) the version's download link.
     */
    public static function loraUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 1000 || !preg_match('~\Ahttps://[^\s/?#@]+(?:[/?#]\S*)?\z~i', $url) || !filter_var($url, FILTER_VALIDATE_URL))
            throw new HttpError(422, t('Paste the LoRA\'s link: an https address of a .safetensors file on Hugging Face or Civitai.'));
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (in_array($host, ['huggingface.co', 'www.huggingface.co', 'hf.co'], true)) {
            $url = preg_replace('#\Ahttps://(?:www\.)?(?:huggingface\.co|hf\.co)/#i', 'https://huggingface.co/', $url);
            $url = preg_replace('#\A(https://huggingface\.co/(?:[^/]+/){1,2})blob/#', '$1resolve/', $url);
            $path = (string)parse_url($url, PHP_URL_PATH);
            if (!str_contains($path, '/resolve/') || !preg_match('/\.safetensors\z/i', $path))
                throw new HttpError(422, t('On Hugging Face, use the link of the .safetensors file itself (open the file and copy the address).'));
        } elseif (in_array($host, ['civitai.com', 'www.civitai.com', 'civitai.green', 'www.civitai.green'], true)) {
            $path = (string)parse_url($url, PHP_URL_PATH);
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            if (preg_match('#\A/api/download/models/(\d+)#', $path, $m)) $url = 'https://civitai.com/api/download/models/'.$m[1];
            elseif (preg_match('#\A/models/\d+#', $path) && preg_match('/\A\d+\z/', (string)($query['modelVersionId'] ?? ''))) $url = 'https://civitai.com/api/download/models/'.$query['modelVersionId'];
            else throw new HttpError(422, t('On Civitai, choose the version and copy the link of its Download button, or the page\'s address with ?modelVersionId= in it.'));
        }
        return $url;
    }

    /** A name from a link: its file name, without the extension. */
    private static function nameFromUrl(string $url): string
    {
        $base = basename((string)parse_url($url, PHP_URL_PATH));
        $name = trim(preg_replace('/\.safetensors\z/i', '', rawurldecode($base)) ?? '');
        return $name === '' || ctype_digit($name) ? 'LoRA' : mb_substr(str_replace(['_', '-'], ' ', $name), 0, 80);
    }

    // -------------------------------------------------------------------------------------------------------------
    // Calls from the worker
    // -------------------------------------------------------------------------------------------------------------

    /**
     * The worker's sign of life, with how it is: its GPU, its phase (setup, ready, busy, off), a line about what it is
     * doing, when its session started, since when it waits for requests and which models it has ready. Answers what it
     * must know: its idle minutes and whether to turn off.
     */
    public function heartbeat(array $state): array
    {
        $clean = [];
        foreach (['gpu'=>120, 'phase'=>20, 'message'=>300, 'version'=>40, 'loaded'=>40, 'making'=>10] as $name=>$max)
            if (is_string($state[$name] ?? null)) $clean[$name] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $state[$name]) ?? ''), 0, $max);
        foreach (['vram', 'started', 'idle_since'] as $name) if (is_int($state[$name] ?? null) && $state[$name] >= 0) $clean[$name] = $state[$name];
        if (is_array($state['engines'] ?? null)) $clean['engines'] = array_values(array_filter($state['engines'], fn($e) => is_string($e) && in_array($e, self::workerEngines(), true)));
        if (is_array($state['progress'] ?? null)) $clean['progress'] = array_intersect_key(array_filter($state['progress'], fn($v) => is_int($v) || is_float($v)), array_flip(['done','total']));
        $clean['phase'] ??= 'ready';
        $this->ensureRow();
        $this->vault->db->prepare('UPDATE gpu_workers SET seen=?, state=? WHERE user_id=?')->execute([time(), json_encode($clean, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $this->vault->owner()]);
        $row = $this->row();
        return ['idle_minutes'=>(int)$row['idle_minutes'], 'stop'=>$this->stopWanted($row, $clean), 'now'=>time()];
    }

    /** What the worker must know, read without writing: its idle minutes and whether to turn off. */
    public function directives(): array
    {
        $row = $this->row();
        $state = is_string($row['state'] ?? null) ? json_decode((string)$row['state'], true) : null;
        return ['idle_minutes'=>(int)($row['idle_minutes'] ?? 15), 'stop'=>$row !== null && $this->stopWanted($row, is_array($state) ? $state : null), 'now'=>time()];
    }

    /** The worker turned its runtime off (idle, asked to, or an error it can't recover from). */
    public function bye(string $why, string $code = ''): void
    {
        $row = $this->row(); if ($row === null) return;
        $state = is_string($row['state']) ? (json_decode($row['state'], true) ?: []) : [];
        $state['phase'] = 'off'; $state['message'] = mb_substr($why, 0, 300);
        // Why it stopped, as a word the page can say in its language (gpu: the runtime's GPU was too small).
        $state['ended'] = preg_match('/\A[a-z]{1,20}\z/D', $code) ? $code : null;
        $this->vault->db->prepare('UPDATE gpu_workers SET state=?, stop=0 WHERE user_id=?')->execute([json_encode($state, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $this->vault->owner()]);
    }
}
