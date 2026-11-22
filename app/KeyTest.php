<?php
declare(strict_types=1);
namespace Studio;

/**
 * The Testar button of the OpenAI and Claude keys in Configurações (the Gemini key has its own, Gemini::selfTest, since
 * its writer was built without a real key). Two steps, each saying what it found: the key and the models the app uses
 * (free: the provider's list of models), then one tiny request to the cheapest model the app uses there, which shows a
 * key without credit (a fraction of a cent).
 */
final class KeyTest
{
    public const PROMPT = 'This is a connection test. Reply with the single word OK.';
    public const CLAUDE_MODEL = 'claude-sonnet-5-5';

    /**
     * @param ?\Closure $list fn(string $provider, string $url, list<string> $headers): array{status:int,body:string,errno:int}, for tests
     * @param ?\Closure $post fn(string $url, list<string> $headers, array $body): array{status:int,body:string,errno:int}, for tests
     * @return array{ok:bool,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function openai(string $key, ?\Closure $list = null, ?\Closure $post = null): array
    {
        $wanted = array_merge(array_keys(OpenAI::MODELS), [OpenAI::PLANNER], array_keys(Gpt::MODELS));
        return self::run('openai', $key, $wanted, $list, function () use ($key, $post): string {
            $model = OpenAI::PLANNER;
            $r = self::send('https://api.openai.com/v1/responses', ['Authorization: Bearer '.$key],
                ['model'=>$model, 'store'=>false, 'reasoning'=>['effort'=>'low'], 'input'=>self::PROMPT, 'max_output_tokens'=>400], $post);
            if ($r['status'] >= 400) throw OpenAI::failure($r['status'], $r['body'], '/responses');
            $usage = json_decode($r['body'], true)['usage'] ?? [];
            return t('OpenAI answered with {model}: the key has access and credit.', ['model'=>$model]).' '.t('Test cost: about US$ {cost}.', ['cost'=>self::money(Gpt::cost($model, is_array($usage) ? $usage : []))]);
        });
    }

    /** @see openai() */
    public static function claude(string $key, ?\Closure $list = null, ?\Closure $post = null): array
    {
        return self::run('claude', $key, array_keys(Claude::MODELS), $list, function () use ($key, $post): string {
            $model = self::CLAUDE_MODEL;
            $r = self::send(Claude::URL, ['x-api-key: '.$key, 'anthropic-version: '.Claude::VERSION],
                ['model'=>$model, 'max_tokens'=>16, 'messages'=>[['role'=>'user', 'content'=>self::PROMPT]]], $post);
            if ($r['status'] >= 400) throw Claude::failure($r['status'], $r['body']);
            $usage = json_decode($r['body'], true)['usage'] ?? [];
            return t('Anthropic answered with {model}: the key has access and credit.', ['model'=>$model]).' '.t('Test cost: about US$ {cost}.', ['cost'=>self::money(Claude::cost($model, is_array($usage) ? $usage : []))]);
        });
    }

    /** @param list<string> $wanted the models the app uses with this key */
    private static function run(string $provider, string $key, array $wanted, ?\Closure $list, \Closure $request): array
    {
        $steps = [];
        $add = static function (string $name, bool $ok, string $detail) use (&$steps): void { $steps[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail]; };
        try {
            $ids = array_column(Catalog::fetch($provider, $key, $list), 'id');
            $missing = array_values(array_filter(array_unique($wanted), fn($m) => !self::listed($ids, $m)));
            $models = ['models'=>implode(', ', $missing)];
            $add(t('Key and models'), true, $missing
                ? (count($missing) > 2 ? t('Key accepted. This key does not list {models}: those will not work with it (check that the key\'s project or organization has access to them).', $models)
                    : t('Key accepted. This key does not list {models}: those will not work with it.', $models))
                : t('Key accepted, with every model the app uses.'));
        } catch (HttpError $e) { $add(t('Key and models'), false, $e->getMessage()); return ['ok'=>false, 'steps'=>$steps]; }
        try { $add(t('Test request'), true, $request()); }
        catch (HttpError $e) { $add(t('Test request'), false, $e->getMessage()); }
        return ['ok'=>!array_filter($steps, fn($s) => !$s['ok']), 'steps'=>$steps];
    }

    /** A model the app uses is in a list under its own id or a dated copy of it (claude-x-5-5-20261001, gpt-x-2026-11-01). */
    private static function listed(array $ids, string $model): bool
    {
        foreach ($ids as $id) if ($id === $model || preg_match('/\A'.preg_quote($model, '/').'-(?:\d{8}|\d{4}-\d{2}-\d{2})\z/D', $id)) return true;
        return false;
    }

    private static function money(float $value): string { return I18n::number(max($value, 0.0001), 4); }

    /** @return array{status:int,body:string,errno:int} */
    private static function send(string $url, array $headers, array $body, ?\Closure $post): array
    {
        if ($post !== null) $r = $post($url, $headers, $body);
        else {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'], $headers), CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>60, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
            $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $errno = curl_errno($curl); curl_close($curl);
            $r = ['status'=>$status, 'body'=>is_string($raw) ? $raw : '', 'errno'=>$raw === false ? max(1, $errno) : $errno];
        }
        if ((int)$r['errno'] !== 0) throw new HttpError(502, t('No connection to the API (network error {errno}).', ['errno'=>(int)$r['errno']]));
        return ['status'=>(int)$r['status'], 'body'=>(string)$r['body'], 'errno'=>0];
    }
}
