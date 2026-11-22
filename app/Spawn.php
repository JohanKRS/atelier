<?php
declare(strict_types=1);
namespace Studio;

/**
 * Starts a command line PHP process that outlives the web request, for work that can take long (a turn of the story
 * writer). The web server may stop its own PHP processes after a while, whatever max_execution_time says: on Hostinger's
 * LiteSpeed the writer was stopped at about two minutes. A command line process started here is in a session of its own
 * and isn't the web server's, so it goes on for as long as the work takes.
 */
final class Spawn
{
    /** Why a separate process can't be started on this server, or null when it can. */
    public static function unavailable(): ?string
    {
        if (!function_exists('proc_open')) return t('the proc_open function is turned off in this host\'s PHP');
        if (!@is_file('/bin/sh')) return t('the server has no /bin/sh');
        if (self::php() === null) return t('the command line PHP was not found');
        return null;
    }

    /**
     * The command line PHP of the same version as the one serving the page, or another one when there is none. On
     * CloudLinux (Hostinger) the page's PHP_BINARY may be the selector's /usr/local/bin/lsphp, whose php next to it is
     * the account's default command line PHP (8.3 there, while the site runs 8.4): versions are checked, not assumed.
     */
    public static function php(): ?string
    {
        static $found = false;
        if ($found !== false) return $found;
        $candidates = [];
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') $candidates[] = PHP_BINARY;
        $candidates[] = '/opt/alt/php'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION.'/usr/bin/php';
        foreach ([PHP_BINARY !== '' ? dirname(PHP_BINARY) : '', PHP_BINDIR] as $dir) if ($dir !== '' && $dir !== '.') $candidates[] = $dir.'/php';
        array_push($candidates, '/usr/local/bin/php', '/usr/bin/php');
        $usable = array_values(array_filter(array_unique($candidates), fn(string $path) => @is_file($path) && @is_executable($path)));
        foreach ($usable as $path) if (self::version($path) === PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION) return $found = $path;
        return $found = $usable[0] ?? null;
    }

    /** The major.minor version of a command line PHP, from its path where it says it, otherwise asked. */
    private static function version(string $path): ?string
    {
        if (PHP_SAPI === 'cli' && $path === PHP_BINARY) return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if (preg_match('#\A/opt/alt/php(\d)(\d+)/#', $path, $m)) return $m[1].'.'.$m[2];
        if (!function_exists('proc_open')) return null;
        try { $process = @proc_open([$path, '-n', '-r', 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'], [0=>['file', '/dev/null', 'r'], 1=>['pipe', 'w'], 2=>['file', '/dev/null', 'w']], $pipes); }
        catch (\Throwable) { return null; }
        if (!is_resource($process)) return null;
        $out = trim((string)stream_get_contents($pipes[1], 16)); fclose($pipes[1]); proc_close($process);
        return preg_match('/\A\d+\.\d+\z/D', $out) ? $out : null;
    }

    /** setsid, which puts the process in a session of its own: then nothing sent to the web server's group reaches it. */
    public static function setsid(): ?string
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) if (@is_file($path) && @is_executable($path)) return $path;
        return null;
    }

    /** The PHP extensions the separate process needs: missing ones mean the work stays in the web request. */
    public const EXTENSIONS = ['curl', 'pdo_sqlite', 'sodium', 'mbstring', 'gd'];

    /** The extensions this PHP lacks, of those the separate process needs. */
    public static function missing(): array
    { return array_values(array_filter(self::EXTENSIONS, fn(string $name) => !extension_loaded($name))); }

    /** The script of the separate process. */
    public static function worker(): string { return dirname(__DIR__).'/bin/worker.php'; }

    /**
     * How long LiteSpeed lets each of its PHP processes live (LSAPI_MAX_PROCESS_TIME), in seconds, or null when this
     * isn't LiteSpeed or the limit isn't set. Hostinger sets 120: past it the process is stopped, however the request
     * is going. getenv() doesn't see it; the process's own environment does.
     */
    public static function webLimit(): ?int
    {
        if (PHP_SAPI !== 'litespeed') return null;
        $value = getenv('LSAPI_MAX_PROCESS_TIME');
        if ($value === false) {
            $environment = @file_get_contents('/proc/self/environ');
            $value = is_string($environment) && preg_match('/(?:\A|\0)LSAPI_MAX_PROCESS_TIME=(\d{1,6})(?:\0|\z)/', $environment, $m) ? $m[1] : false;
        }
        return is_string($value) && ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * Starts $script as start() does, with one more argument, a word the process answers to through tell(), and waits
     * up to $seconds for its answer: 'claimed' (it took the work), 'idle' (there was nothing for it to take) or
     * 'failed: why'. Null when it said nothing in time.
     */
    public static function run(string $dir, string $script, array $args, string $secret, float $seconds = 6.0): ?string
    {
        $box = self::box($dir); if ($box === null) return 'failed: '.t('no folder for the answer of the process');
        $word = bin2hex(random_bytes(8));
        if (!self::start($script, [...$args, $word], $secret)) return 'failed: '.t('the process could not be started');
        $end = microtime(true) + $seconds;
        do {
            usleep(100000);
            $said = @file_get_contents("$box/$word");
            if (is_string($said) && $said !== '') { @unlink("$box/$word"); return $said; }
        } while (microtime(true) < $end);
        return null;
    }

    /** The separate process's answer to the request that started it (see run()). */
    public static function tell(string $dir, string $word, string $what): void
    {
        if (!preg_match('/\A[a-f0-9]{16}\z/D', $word) || ($box = self::box($dir)) === null) return;
        $temporary = "$box/$word.".bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($temporary, $what) !== false && !@rename($temporary, "$box/$word")) @unlink($temporary);
    }

    private static function box(string $dir): ?string
    {
        $box = $dir.'/spawn';
        if (!@is_dir($box) && !@mkdir($box, 0700, true) && !@is_dir($box)) return null;
        // Answers that came after the request had stopped waiting.
        if (random_int(1, 20) === 1) foreach (glob($box.'/*') ?: [] as $old) if (@filemtime($old) < time() - 3600) @unlink($old);
        return $box;
    }

    /** Whether a separate process failed here in the last hour: until then the work stays in the web request. */
    public static function failedRecently(string $dir): bool { return @filemtime($dir.'/separate-failed') > time() - 3600; }

    /** Why the last separate process failed here, or null. */
    public static function lastFailure(string $dir): ?string
    {
        if (!self::failedRecently($dir)) return null;
        $why = @file_get_contents($dir.'/separate-failed');
        return is_string($why) && $why !== '' ? mb_substr($why, 0, 300) : t('did not answer in time');
    }

    /** Notes how a separate process went: null when it worked, otherwise why not. */
    public static function outcome(string $dir, ?string $failure): void
    {
        if ($failure === null) { @unlink($dir.'/separate-failed'); return; }
        error_log('Atelier: separate process failed ('.$failure.'); running the work in the web request');
        @file_put_contents($dir.'/separate-failed', $failure);
    }

    /**
     * Starts $script with $args in the background and returns at once. $secret reaches the process through a pipe it
     * reads first, never through the command line or the environment, which other processes could read. False when
     * nothing could be started.
     */
    public static function start(string $script, array $args, string $secret = ''): bool
    {
        if (self::unavailable() !== null) return false;
        $command = [self::php(), '-d', 'max_execution_time=0', '-d', 'display_errors=0', '-d', 'log_errors=1'];
        $memory = (string)ini_get('memory_limit'); if ($memory !== '') array_push($command, '-d', 'memory_limit='.$memory);
        // Its errors go to the same log as the web's.
        $log = (string)ini_get('error_log'); if ($log !== '') array_push($command, '-d', 'error_log='.$log);
        array_push($command, $script, ...array_map('strval', $args));
        $setsid = self::setsid(); if ($setsid !== null) array_unshift($command, $setsid);
        // The shell starts it in the background and ends: the process is nobody's child here, so this one, when the web
        // server stops it, takes nothing along. Its input is the pipe on descriptor 3 (a background command's own input
        // would be /dev/null).
        // It speaks the language of the page that started it (I18n::detect reads ATELIER_LANG on the command line).
        $line = 'ATELIER_LANG='.escapeshellarg(I18n::language()).' '.implode(' ', array_map('escapeshellarg', $command)).' <&3 3<&- >/dev/null 2>&1 &';
        try {
            $process = @proc_open(['/bin/sh', '-c', $line], [0=>['file', '/dev/null', 'r'], 1=>['file', '/dev/null', 'w'], 2=>['file', '/dev/null', 'w'], 3=>['pipe', 'r']], $pipes, dirname(__DIR__));
        } catch (\Throwable) { return false; }
        if (!is_resource($process)) return false;
        if ($secret !== '') @fwrite($pipes[3], $secret."\n");
        fclose($pipes[3]);
        return @proc_close($process) === 0;
    }
}
