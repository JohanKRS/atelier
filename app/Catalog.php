<?php
declare(strict_types=1);
namespace Studio;

/**
 * The models the person's keys can use, as Anthropic, OpenAI and Google list them, next to the app's own tables.
 *
 * The app's tables (Claude::MODELS, Gpt::MODELS, Gemini::MODELS, OpenAI::MODELS, GeminiImages::MODELS) have each
 * model's settings and prices, checked by hand. A newer model a key lists that isn't in them is offered too, like the
 * closest one the app knows (same family: its settings, and its prices as an estimate, said so in its name) until its
 * own are added. Older models than the app's are left out, and so are dated copies and models made for other things
 * (speech, live audio, embeddings, search).
 *
 * The lists are fetched with the person's keys (refresh), kept sealed in their account and put to use in each request
 * (use), so the page, the writer, the image requests, the separate process and MCP all accept the same models.
 */
final class Catalog
{
    public const URLS = ['claude'=>'https://api.anthropic.com/v1/models?limit=1000', 'openai'=>'https://api.openai.com/v1/models',
        'gemini'=>'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000'];
    /** A list older than this (seconds) is fetched again when the page opens. */
    public const FRESH = 1800;
    /** The name of a newer model, priced like the closest one the app knows: its name says its price is estimated. */
    public static function estimated(string $name): string { return t('{model} (estimated price)', ['model'=>$name]); }

    /** The lists in use: provider => list<array{id:string,name:string,methods:?list<string>}>. */
    private static array $lists = ['claude'=>[], 'openai'=>[], 'gemini'=>[]];
    private static int $fetched = 0;
    /** What the lists give, worked out once per set of lists. */
    private static ?array $found = null;

    /** Puts an account's kept lists to use in this process (Vault::modelLists). */
    public static function use(?array $stored): void
    {
        self::$lists = ['claude'=>[], 'openai'=>[], 'gemini'=>[]];
        foreach (self::$lists as $p => $_) foreach (is_array($stored['lists'][$p] ?? null) ? $stored['lists'][$p] : [] as $m)
            if (is_array($m) && is_string($m['id'] ?? null)) self::$lists[$p][] = ['id'=>$m['id'], 'name'=>is_string($m['name'] ?? null) ? $m['name'] : '', 'methods'=>is_array($m['methods'] ?? null) ? $m['methods'] : null];
        self::$fetched = is_int($stored['at'] ?? null) ? $stored['at'] : 0;
        self::$found = null;
    }

    public static function fetched(): int { return self::$fetched; }

    // =================================================================================================================
    // What the page and the requests ask
    // =================================================================================================================

    /** The writer models of a provider: the app's, then the newer ones the key lists. */
    public static function writers(string $provider): array
    { return self::known($provider) + (self::found()[$provider] ?? []); }

    /** A writer model's settings and prices (a newer one's borrowed from its closest), or null when there is no such model. */
    public static function writer(string $provider, string $id): ?array
    { return self::known($provider)[$id] ?? self::found()[$provider][$id] ?? null; }

    /** The image models the keys list that the app doesn't know, in the shape Images::models gives, with their provider. */
    public static function images(): array { return self::found()['images']; }

    public static function image(string $id): ?array { return self::found()['images'][$id] ?? null; }

    private static function known(string $provider): array
    { return ['claude'=>Claude::MODELS, 'openai'=>Gpt::MODELS, 'gemini'=>Gemini::MODELS][$provider] ?? []; }

    // =================================================================================================================
    // Fetching
    // =================================================================================================================

    /**
     * Asks each provider the account has a key for which models it offers, and keeps the lists. A provider that fails
     * keeps its last list; one without a key has none. Returns what was kept and, per provider, what failed.
     * @param ?\Closure $transport fn(string $provider, string $url, list<string> $headers): array{status:int,body:string,errno:int}, for tests
     */
    public static function refresh(Vault $vault, string $key, ?\Closure $transport = null): array
    {
        $old = $vault->modelLists($key) ?? [];
        $keys = ['claude'=>$vault->claudeKey($key), 'openai'=>$vault->apiKey($key), 'gemini'=>$vault->geminiKey($key)];
        $lists = []; $errors = [];
        foreach ($keys as $provider => $secret) {
            if ($secret === '') { $lists[$provider] = []; continue; }
            try { $lists[$provider] = self::fetch($provider, $secret, $transport); }
            catch (\Throwable $e) {
                $errors[$provider] = $e instanceof HttpError ? $e->getMessage() : t('Could not list the models.');
                $lists[$provider] = is_array($old['lists'][$provider] ?? null) ? $old['lists'][$provider] : [];
            }
            sodium_memzero($secret);
        }
        $stored = ['at'=>time(), 'lists'=>$lists, 'errors'=>(object)$errors];
        $vault->setModelLists($stored, $key);
        self::use($stored);
        return $stored;
    }

    /**
     * The models one key lists (also the first step of the key tests in Configurações).
     * @return list<array{id:string,name:string,methods:?list<string>}>
     */
    public static function fetch(string $provider, string $secret, ?\Closure $transport = null): array
    {
        $headers = ['claude'=>['x-api-key: '.$secret, 'anthropic-version: '.Claude::VERSION], 'openai'=>['Authorization: Bearer '.$secret], 'gemini'=>Gemini::headers($secret)][$provider];
        $out = []; $next = '';
        for ($page = 0; $page < 8; $page++) {
            $url = self::URLS[$provider].($next === '' ? '' : ($provider === 'claude' ? '&after_id=' : '&pageToken=').rawurlencode($next));
            $r = $transport !== null ? $transport($provider, $url, $headers) : self::get($url, $headers);
            if ((int)$r['errno'] !== 0) throw new HttpError(502, t('No connection to the API (network error {errno}).', ['errno'=>$r['errno']]));
            $status = (int)$r['status'];
            if ($status === 401 || $status === 403 || ($status === 400 && preg_match('/API_KEY_INVALID|API key not valid/i', (string)$r['body']))) throw new HttpError(422, t('The key was not accepted when listing the models.'));
            if ($status >= 400) throw new HttpError(502, t('The API answered HTTP {status} when listing the models.', ['status'=>$status]));
            $json = json_decode((string)$r['body'], true);
            if (!is_array($json)) throw new HttpError(502, t('The list of models came in an unexpected format.'));
            $rows = $provider === 'gemini' ? ($json['models'] ?? []) : ($json['data'] ?? []);
            foreach (is_array($rows) ? $rows : [] as $m) {
                if (!is_array($m)) continue;
                $id = (string)($provider === 'gemini' ? preg_replace('#^models/#', '', (string)($m['name'] ?? '')) : ($m['id'] ?? ''));
                if (!preg_match('/\A[a-z0-9][a-z0-9._\-]{1,80}\z/D', $id) || !preg_match('/claude|gpt|gemini|banana|image/', $id)) continue;
                $methods = $m['supportedGenerationMethods'] ?? $m['supported_actions'] ?? $m['supportedActions'] ?? null;
                $out[] = ['id'=>$id, 'name'=>mb_substr(trim((string)($m['display_name'] ?? $m['displayName'] ?? '')), 0, 80),
                    'methods'=>is_array($methods) ? array_values(array_filter($methods, 'is_string')) : null];
            }
            $next = $provider === 'claude' ? (!empty($json['has_more']) && is_string($json['last_id'] ?? null) ? $json['last_id'] : '')
                : ($provider === 'gemini' && is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : '');
            if ($next === '') break;
        }
        return $out;
    }

    /** @return array{status:int,body:string,errno:int} */
    private static function get(string $url, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $errno = curl_errno($curl); curl_close($curl);
        return ['status'=>$status, 'body'=>is_string($body) ? $body : '', 'errno'=>$body === false ? max(1, $errno) : $errno];
    }

    // =================================================================================================================
    // From the lists to models
    // =================================================================================================================

    private static function found(): array
    {
        if (self::$found !== null) return self::$found;
        $images = [];
        foreach (self::openaiImages() + self::geminiImages() as $id => $m) $images[$id] = $m;
        return self::$found = ['claude'=>self::claudeWriters(), 'openai'=>self::gptWriters(), 'gemini'=>self::geminiWriters(), 'images'=>$images];
    }

    /** A version as a number to compare: 5.5 → 505, 6 → 600. */
    private static function version(string $major, ?string $minor): int { return (int)$major * 100 + (int)($minor ?? 0); }

    /** The lowest version among the app's own models of a pattern: newer ones are offered, older ones aren't. */
    private static function floor(array $ids, string $pattern): int
    {
        $lowest = PHP_INT_MAX;
        foreach ($ids as $id) if (preg_match($pattern, $id, $m)) $lowest = min($lowest, self::version($m[1], ($m[2] ?? '') === '' ? null : $m[2]));
        return $lowest === PHP_INT_MAX ? 0 : $lowest;
    }

    /** The ids of a list that aren't a dated or numbered copy of another one in it (claude-x-5-6-20261201 next to claude-x-5-6). */
    private static function undated(array $list): array
    {
        $ids = array_column($list, 'id');
        return array_values(array_filter($list, function (array $m) use ($ids) {
            if (!preg_match('/\A(.+?)-(?:\d{8}|\d{4}-\d{2}-\d{2}|\d{2}-\d{2}|\d{2}-\d{4}|\d{3}|latest)\z/D', $m['id'], $x)) return true;
            return !in_array($x[1], $ids, true);
        }));
    }

    /** A model borrowed from the closest one known: its settings and prices, the new name, and where they came from. */
    private static function like(array $known, string $like, string $name): array
    { return ['label'=>self::estimated($name), 'like'=>$like, 'estimated'=>true] + $known[$like]; }

    private static function claudeWriters(): array
    {
        $known = Claude::MODELS; $out = [];
        $floor = self::floor(array_keys($known), '/\Aclaude-[a-z]+-(\d+)(?:-(\d))?\z/');
        foreach (self::undated(self::$lists['claude']) as $m) {
            if (isset($known[$m['id']]) || !preg_match('/\Aclaude-([a-z]+)-(\d+)(?:-(\d))?(?:-(\d{8}))?\z/D', $m['id'], $x)) continue;
            if (self::version($x[2], ($x[3] ?? '') === '' ? null : $x[3]) < $floor) continue;
            // An alias of a model the app knows (its dated id): already offered.
            if (($x[4] ?? '') !== '' && isset($known[substr($m['id'], 0, -9)])) continue;
            $family = array_values(array_filter(array_keys($known), fn($k) => str_starts_with($k, 'claude-'.$x[1].'-')));
            $label = $m['name'] !== '' ? $m['name'] : 'Claude '.ucfirst($x[1]).' '.$x[2].(($x[3] ?? '') !== '' ? '.'.$x[3] : '');
            $out[$m['id']] = self::like($known, $family[0] ?? array_key_first($known), $label);
        }
        return $out;
    }

    private static function gptWriters(): array
    {
        $known = Gpt::MODELS; $out = [];
        $floor = self::floor(array_keys($known), '/\Agpt-(\d+)(?:\.(\d+))?(?:-[a-z]+)?\z/');
        foreach (self::$lists['openai'] as $m) {
            if (isset($known[$m['id']]) || !preg_match('/\Agpt-(\d+)(?:\.(\d+))?(?:-([a-z]+))?\z/D', $m['id'], $x)) continue;
            $suffix = $x[3] ?? '';
            if (preg_match('/\A(audio|realtime|transcribe|tts|search|image|codex|chat|instruct|preview|latest|embedding|moderation|oss)\z/', $suffix)) continue;
            if (self::version($x[1], ($x[2] ?? '') === '' ? null : $x[2]) < $floor) continue;
            $family = $suffix === '' ? [] : array_values(array_filter(array_keys($known), fn($k) => str_ends_with($k, '-'.$suffix)));
            $out[$m['id']] = self::like($known, $family[0] ?? array_key_first($known), 'GPT-'.$x[1].(($x[2] ?? '') !== '' ? '.'.$x[2] : '').($suffix !== '' ? ' '.ucfirst($suffix) : ''));
        }
        return $out;
    }

    private static function geminiWriters(): array
    {
        $known = Gemini::MODELS; $out = [];
        foreach (self::undated(self::$lists['gemini']) as $m) {
            if (isset($known[$m['id']]) || !Gemini::isNewWriter($m['id'])) continue;
            if ($m['methods'] !== null && !array_intersect($m['methods'], ['generateContent', 'streamGenerateContent', 'createInteraction', 'interactions'])) continue;
            $like = preg_match('/flash|lite/', $m['id']) ? 'gemini-3.8-flash' : array_key_first($known);
            $out[$m['id']] = self::like($known, isset($known[$like]) ? $like : array_key_first($known), $m['name'] !== '' ? $m['name'] : $m['id']);
        }
        return $out;
    }

    private static function openaiImages(): array
    {
        $known = OpenAI::MODELS; $out = [];
        $floor = self::floor(array_keys(array_filter($known, fn($m) => $m['flex'])), '/\Agpt-image-(\d+)(?:\.(\d+))?(?:-[a-z]+)?\z/');
        foreach (self::$lists['openai'] as $m) {
            if (isset($known[$m['id']]) || !preg_match('/\Agpt-image-(\d+)(?:\.(\d+))?(?:-([a-z]+))?\z/D', $m['id'], $x)) continue;
            $suffix = $x[3] ?? '';
            if ($suffix === 'mini' || preg_match('/\A(latest|preview)\z/', $suffix) || self::version($x[1], ($x[2] ?? '') === '' ? null : $x[2]) < $floor) continue;
            $family = $suffix === '' ? [] : array_values(array_filter(array_keys($known), fn($k) => str_ends_with($k, '-'.$suffix)));
            $like = $family[0] ?? array_key_first($known);
            $label = 'GPT Image '.$x[1].(($x[2] ?? '') !== '' ? '.'.$x[2] : '').($suffix !== '' ? ' · '.ucfirst($suffix) : '');
            $out[$m['id']] = self::like($known, $like, $label) + ['provider'=>'openai'];
        }
        return $out;
    }

    private static function geminiImages(): array
    {
        $known = GeminiImages::MODELS; $out = [];
        foreach (self::undated(self::$lists['gemini']) as $m) {
            $id = $m['id'];
            if (isset($known[$id]) || preg_match('/tts|live|audio|embed|veo|imagen|edit-only/', $id)) continue;
            if (preg_match('/nano-banana-(\d+)(?:\.(\d+))?/', $id, $x)) { if (self::version($x[1], ($x[2] ?? '') === '' ? null : $x[2]) <= 201) continue; }
            elseif (preg_match('/\Agemini-(\d+)(?:\.(\d+))?-.*image/', $id, $x)) { if (self::version($x[1], ($x[2] ?? '') === '' ? null : $x[2]) < 308) continue; }
            else continue;
            if ($m['methods'] !== null && !array_intersect($m['methods'], ['generateContent', 'streamGenerateContent', 'createInteraction', 'interactions'])) continue;
            $like = str_contains($id, 'pro') ? 'gemini-3-pro-image' : 'gemini-nano-banana-2.1';
            $out[$id] = self::like($known, $like, ($m['name'] !== '' ? $m['name'] : $id).' · Gemini') + ['provider'=>'gemini'];
        }
        return $out;
    }
}
