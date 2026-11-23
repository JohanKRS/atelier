<?php
declare(strict_types=1);
namespace Studio;

/**
 * The hosting test in Configurações: processes that only wait, for up to ten minutes, each the way some work of the app
 * runs, writing every two seconds how long they have lived. The page shows at which second each one stopped and how, and
 * what the server allows. No API is called.
 *
 * web:       like the writer's turn in the web server: answered at once, then on in the background (route covered by the
 *            .htaccess rule that asks LiteSpeed not to stop it).
 * web-plain: the same on a route the .htaccess rule doesn't cover.
 * separate:  a command line process of its own (Spawn), as the writer and the generations now run where the server
 *            allows it.
 */
final class HostTest
{
    public const KINDS = ['web', 'web-plain', 'separate'];
    /** A process silent for longer than this, with no word of its end, was killed from outside. */
    private const SILENT = 12;

    public function __construct(private string $dir) {}

    private function folder(): string
    {
        $dir = $this->dir.'/host-test';
        if (!@is_dir($dir) && !@mkdir($dir, 0700, true) && !@is_dir($dir)) throw new HttpError(500, t('Could not create the test folder.'));
        return $dir;
    }

    /** What this PHP and server allow, as seen by the web request. */
    public static function info(): array
    {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        $watch = ['proc_open', 'exec', 'set_time_limit', 'ignore_user_abort', 'litespeed_finish_request', 'fastcgi_finish_request'];
        $before = (string)ini_get('max_execution_time');
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $noabort = null;
        foreach (['noabort', 'REDIRECT_noabort'] as $name) if (isset($_SERVER[$name]) || getenv($name) !== false) $noabort = true;
        return ['server'=>mb_substr((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 0, 60), 'sapi'=>PHP_SAPI, 'php'=>PHP_VERSION,
            'max_execution_time'=>$before, 'after_set_time_limit'=>(string)ini_get('max_execution_time'), 'memory_limit'=>(string)ini_get('memory_limit'),
            'noabort'=>$noabort ?? false, 'disabled'=>array_values(array_intersect($watch, $disabled)),
            'missing'=>array_values(array_filter($watch, fn($f) => !function_exists($f))), 'separate'=>Spawn::unavailable(), 'setsid'=>Spawn::setsid() !== null,
            'limit'=>Spawn::webLimit()];
    }

    /** Starts a new test (the previous one's processes notice and end). */
    public function begin(int $seconds): array
    {
        $dir = $this->folder();
        foreach (glob($dir.'/*.json') ?: [] as $file) @unlink($file);
        $run = ['id'=>bin2hex(random_bytes(8)), 'started'=>time(), 'seconds'=>max(5, min(600, $seconds)), 'info'=>self::info() + ['separate_failed'=>Spawn::lastFailure($this->dir)]];
        $this->write($dir.'/run.json', $run);
        return $run;
    }

    private function current(): ?array
    {
        $raw = @file_get_contents($this->folder().'/run.json');
        $run = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($run) ? $run : null;
    }

    private function write(string $file, array $data): void
    {
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false && !@rename($temporary, $file)) @unlink($temporary);
    }

    /** A kind of process this server can't start, and why. */
    public function unavailable(string $runId, string $kind, string $why): void
    {
        $run = $this->current();
        if (!$run || !hash_equals((string)$run['id'], $runId) || !in_array($kind, self::KINDS, true)) return;
        $this->write($this->folder().'/'.$kind.'.json', ['kind'=>$kind, 'beat'=>0, 'end'=>['how'=>'unavailable', 'at'=>0, 'why'=>$why]]);
    }

    /** One of the test's processes: waits, writing how long it has lived, until the end or until it is stopped. */
    public function run(string $runId, string $kind): void
    {
        $run = $this->current();
        if (!$run || !hash_equals((string)$run['id'], $runId) || !in_array($kind, self::KINDS, true)) return;
        $file = $this->folder().'/'.$kind.'.json';
        $start = microtime(true);
        $state = ['kind'=>$kind, 'pid'=>getmypid(), 'started'=>time(), 'beat'=>0.0, 'end'=>null];
        if ($kind !== 'separate') $state['noabort'] = self::info()['noabort'];
        // The command line PHP may not be the one serving the page, nor have the same extensions.
        else $state += ['php'=>PHP_VERSION, 'extensions'=>Spawn::missing()];
        $state['max_execution_time'] = (string)ini_get('max_execution_time');
        $finished = false;
        register_shutdown_function(function () use (&$state, &$finished, $file, $start): void {
            if ($finished) return;
            $error = error_get_last();
            $fatal = $error !== null && in_array($error['type'] ?? 0, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) ? mb_substr((string)$error['message'], 0, 160) : null;
            $state['beat'] = round(microtime(true) - $start, 1);
            $state['end'] = ['how'=>'shutdown', 'at'=>$state['beat'], 'error'=>$fatal, 'connection'=>connection_status()];
            $this->write($file, $state);
        });
        $this->write($file, $state);
        while (true) {
            sleep(2);
            $state['beat'] = round(microtime(true) - $start, 1);
            $now = $this->current();
            if (!$now || $now['id'] !== $runId) { $finished = true; return; }
            if ($state['beat'] >= (int)$run['seconds']) break;
            $this->write($file, $state);
        }
        $finished = true;
        $state['end'] = ['how'=>'completed', 'at'=>$state['beat']];
        $this->write($file, $state);
    }

    /** The current test, with each process's state: running, completed, stopped (PHP was told to stop) or killed. */
    public function status(): ?array
    {
        $run = $this->current(); if (!$run) return null;
        $out = $run + ['now'=>time(), 'kinds'=>[]];
        foreach (self::KINDS as $kind) {
            $raw = @file_get_contents($this->folder().'/'.$kind.'.json');
            $s = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($s)) { $out['kinds'][$kind] = ['state'=>'waiting']; continue; }
            $age = time() - (int)@filemtime($this->folder().'/'.$kind.'.json');
            $state = $s['end'] === null ? ($age > self::SILENT ? 'killed' : 'running') : (['completed'=>'completed', 'unavailable'=>'unavailable'][$s['end']['how']] ?? 'stopped');
            $out['kinds'][$kind] = ['state'=>$state, 'seconds'=>(float)($s['end']['at'] ?? $s['beat']), 'error'=>$s['end']['error'] ?? null, 'why'=>isset($s['end']['why']) ? t((string)$s['end']['why']) : null]
                + array_intersect_key($s, ['noabort'=>1, 'max_execution_time'=>1, 'php'=>1, 'extensions'=>1]);
        }
        return $out;
    }
}
