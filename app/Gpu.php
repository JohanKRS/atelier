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
    /** Seconds a running video may go without a sign of life before it counts as interrupted (the runtime went away). */
    public const STALE = 180;
    /** The notebook, opened in Colab straight from the public repository. */
    public const NOTEBOOK = 'https://colab.research.google.com/github/JohanKRS/atelier/blob/main/colab/atelier_video.ipynb';
    public const IDLE_CHOICES = [5, 10, 15, 20, 30, 45, 60];
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

    /** What the page shows: whether there is a token, the worker (when it is on), the idle minutes and the queue. */
    public function status(): array
    {
        $row = $this->row(); $now = time();
        $state = is_string($row['state'] ?? null) ? json_decode((string)$row['state'], true) : null;
        $alive = $row !== null && $row['seen'] !== null && $now - (int)$row['seen'] <= self::ALIVE && is_array($state) && ($state['phase'] ?? '') !== 'off';
        $idle = (int)($row['idle_minutes'] ?? 15);
        $worker = null;
        if ($alive) {
            $worker = array_intersect_key($state, array_flip(['gpu','vram','phase','message','started','idle_since','engines','loaded','version','progress']));
            $worker['seen'] = (int)$row['seen'];
            // When it turns itself off if no request comes, by the idle minutes it was last told.
            $worker['off_at'] = is_int($state['idle_since'] ?? null) ? $state['idle_since'] + $idle * 60 : null;
            $worker['stopping'] = $this->stopWanted($row, $state);
        }
        $q = $this->vault->db->prepare("SELECT status, COUNT(*) AS n FROM jobs WHERE user_id=? AND runner='gpu' AND status IN ('queued','running','cancelling') GROUP BY status");
        $q->execute([$this->vault->owner()]); $counts = array_column($q->fetchAll(), 'n', 'status');
        return ['token'=>$row !== null && $row['token_id'] !== null ? ['created'=>(int)$row['created'], 'used'=>$row['used'] === null ? null : (int)$row['used']] : null,
            'worker'=>$worker, 'idle_minutes'=>$idle, 'idle_choices'=>self::IDLE_CHOICES, 'queued'=>(int)($counts['queued'] ?? 0),
            'running'=>(int)($counts['running'] ?? 0) + (int)($counts['cancelling'] ?? 0), 'notebook'=>(string)($this->config['video_notebook'] ?? self::NOTEBOOK),
            'engines'=>self::engines(), 'now'=>$now];
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
        foreach (['gpu'=>120, 'phase'=>20, 'message'=>300, 'version'=>40, 'loaded'=>40] as $name=>$max)
            if (is_string($state[$name] ?? null)) $clean[$name] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $state[$name]) ?? ''), 0, $max);
        foreach (['vram', 'started', 'idle_since'] as $name) if (is_int($state[$name] ?? null) && $state[$name] >= 0) $clean[$name] = $state[$name];
        if (is_array($state['engines'] ?? null)) $clean['engines'] = array_values(array_filter($state['engines'], fn($e) => is_string($e) && self::engine($e) !== null));
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
    public function bye(string $why): void
    {
        $row = $this->row(); if ($row === null) return;
        $state = is_string($row['state']) ? (json_decode($row['state'], true) ?: []) : [];
        $state['phase'] = 'off'; $state['message'] = mb_substr($why, 0, 300);
        $this->vault->db->prepare('UPDATE gpu_workers SET state=?, stop=0 WHERE user_id=?')->execute([json_encode($state, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $this->vault->owner()]);
    }
}
