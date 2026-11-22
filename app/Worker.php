<?php
declare(strict_types=1);
namespace Studio;

/**
 * The separate process (bin/worker.php, started by Spawn) that runs long work away from the web server, which on
 * Hostinger stops its own PHP processes at 120 s:
 *   story <turn> <user> <library> <word>   a turn of the story writer
 *   job <request> <user> <library> <word>  an image request the page launched (taken now, or left in line)
 *   jobs <user> <library> <request>... <word>  image requests from MCP, one after another, each when there is room
 *   host-test <test> <library>              a process of the hosting test
 * The key comes first on standard input, in hex. The word is how the process answers the request that started it
 * (Spawn::run): whether it took the work ('claimed'), found nothing to take ('idle'), hit an error of the work ('error: …')
 * or couldn't work on this server ('failed: …').
 */
final class Worker
{
    /**
     * @param ?\Closure $adapt changes the writer before it runs (tests give it simulated APIs)
     * @param ?ImageClient $api the image API (tests give a simulated one)
     */
    public static function main(array $argv, mixed $input, ?\Closure $adapt = null, ?ImageClient $api = null): int
    {
        $kind = (string)($argv[1] ?? '');
        if ($kind === 'host-test') {
            $dir = (string)($argv[3] ?? '');
            if (!self::library($dir)) return 2;
            (new HostTest($dir))->run((string)($argv[2] ?? ''), 'separate');
            return 0;
        }
        if ($kind === 'jobs') { [$owner, $dir, $word] = [(string)($argv[2] ?? ''), (string)($argv[3] ?? ''), (string)($argv[count($argv) - 1] ?? '')]; $ids = array_map('strval', array_slice($argv, 4, -1)); }
        elseif ($kind === 'story' || $kind === 'job') { [$id, $owner, $dir, $word] = [(string)($argv[2] ?? ''), (string)($argv[3] ?? ''), (string)($argv[4] ?? ''), (string)($argv[5] ?? '')]; $ids = [$id]; }
        else return 2;
        if (!self::library($dir)) return 2;
        // Answers once: the first thing said is what the web request goes by.
        $said = false;
        $tell = static function (string $what) use (&$said, $dir, $word): void { if ($said || $word === '') return; $said = true; Spawn::tell($dir, $word, $what); };
        if ($missing = Spawn::missing()) { $tell('failed: '.t('the command line PHP is missing extensions ({extensions})', ['extensions'=>implode(', ', $missing)])); return 3; }
        $line = is_resource($input) ? trim((string)fgets($input, 1024)) : '';
        if (!preg_match('/\A(?:[a-f0-9]{2}){16,64}\z/D', $line) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $owner) || !$ids || count($ids) > 64
            || array_filter($ids, fn(string $id) => !preg_match('/\A[a-f0-9]{32}\z/D', $id))) { $tell('failed: '.t('invalid request for the separate process')); return 2; }
        $key = sodium_hex2bin($line); sodium_memzero($line);
        try {
            $config = configuration();
            $vault = new Vault($dir); $vault->useOwner($owner);
            // The newer models the account's keys listed (Catalog), as the page offered them.
            Catalog::use($vault->modelLists($key));
            $jobs = new Jobs($vault, $config);
            if ($kind === 'story') {
                $writer = new Writer($vault, new Stories($vault), $jobs, $config);
                if ($adapt !== null) $writer = $adapt($writer);
                // As the web route does in the background: the illustrations of the turn are made here too.
                $writer->run($ids[0], $key, static function () use ($tell): void { $tell('claimed'); });
            } elseif ($kind === 'job') {
                $jobs->run($ids[0], $key, $api, null, static function () use ($tell): void { $tell('claimed'); });
            } else {
                $tell('claimed');
                foreach ($ids as $id) { try { $jobs->runAndWait($id, $key, $api, null, time() + 3600); } catch (\Throwable $e) { if (!$e instanceof HttpError) logFailure($e); } }
            }
            $tell('idle');
            return 0;
        } catch (\Throwable $e) {
            if (!$e instanceof HttpError) logFailure($e);
            // An error of the work itself (the request is gone, say) isn't the server's: the web request runs it and shows it.
            $tell($e instanceof HttpError ? 'error: '.$e->getMessage() : 'failed: '.t('unexpected error in the separate process'));
            return 1;
        } finally { sodium_memzero($key); }
    }

    private static function library(string $dir): bool
    { return $dir !== '' && str_starts_with($dir, '/') && !str_contains($dir, '..') && @is_dir($dir) && @is_file($dir.'/vault.sqlite'); }
}
