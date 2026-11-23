<?php
declare(strict_types=1);
namespace Studio;

/**
 * What Jobs needs of an image API: OpenAI (GPT Image) and GeminiImages (Nano Banana) both answer it. generate() takes a
 * request's payload and returns ['data'=>[['b64_json'=>…]], 'planned_prompt', 'planning_skipped', 'usage', 'planning_usage',
 * 'sent_prompt']; paid() lists the planning calls made for it, which are paid apart from the image.
 */
interface ImageClient
{
    public function withMonitor(\Closure $monitor): ImageClient;
    public function withPreview(\Closure $preview): ImageClient;
    public function paidCount(): int;
    /** @return list<array{model:string,usage:array,kind:string,effort:?string}> */
    public function paid(int $from = 0): array;
    public function generate(array $job): array;
    /** @return array{variations:list<string>,usage:?array} */
    public function planVariations(string $prompt, array $references, int $count, bool $edit): array;
}

/**
 * The image models of every provider, as the page and the requests know them, and what goes with each provider: the
 * options it takes, how many images it reads, the key it needs and its client.
 */
final class Images
{
    /** Every image model: OpenAI's, then Gemini's, each with its provider. */
    public static function models(): array
    {
        $out = [];
        foreach (OpenAI::MODELS as $id => $m) $out[$id] = $m + ['provider'=>'openai'];
        foreach (GeminiImages::MODELS as $id => $m) $out[$id] = self::shown($m) + ['provider'=>'gemini'];
        // Newer models the keys list (Catalog): like the closest one known, with its prices as an estimate.
        foreach (Catalog::images() as $id => $m) $out[$id] = $m['provider'] === 'gemini' ? self::shown($m) + ['like'=>$m['like'], 'estimated'=>true, 'provider'=>'gemini'] : $m;
        // Open models on the account's GPU worker (Colab), last.
        foreach (GpuImages::MODELS as $id => $m) $out[$id] = GpuImages::shown($m) + ['provider'=>'gpu'];
        return $out;
    }

    /** What the page gets of a Gemini model (not its token prices), with its note in the interface's language. */
    private static function shown(array $m): array
    {
        if (isset($m['note'])) $m['note'] = t($m['note']);
        return array_intersect_key($m, array_flip(['label', 'flex', 'complexity', 'extended', 'qualities', 'ratios', 'max_images', 'tiers', 'transparent', 'per_image', 'note']));
    }

    public static function model(string $id): ?array { return self::models()[$id] ?? null; }

    /** openai, gemini, or gpu: an open model on the account's GPU worker. */
    public static function provider(string $model): string
    {
        if (isset(GpuImages::MODELS[$model])) return 'gpu';
        return isset(GeminiImages::MODELS[$model]) || (Catalog::image($model)['provider'] ?? null) === 'gemini' ? 'gemini' : 'openai';
    }

    public static function options(array $input): array
    {
        $model = $input['model'] ?? null;
        if (!is_string($model)) return OpenAI::options($input);
        return match (self::provider($model)) { 'gemini'=>GeminiImages::options($input), 'gpu'=>GpuImages::options($input), default=>OpenAI::options($input) };
    }

    /** How many images (references, the image being changed, the copy that marks an area) one request of the model reads. */
    public static function maxImages(string $model): int { return (int)((GpuImages::meta($model) ?? GeminiImages::meta($model))['max_images'] ?? 16); }

    /** Whether the account can ask the model for pictures: the provider's key, or for the GPU, the worker's token. */
    public static function hasKey(Vault $vault, string $key, string $model): bool
    {
        return match (self::provider($model)) {
            'gpu'=>(new Gpu($vault))->hasToken(), 'gemini'=>$vault->geminiKey($key) !== '', default=>$vault->apiKey($key) !== '',
        };
    }

    public static function requireKey(Vault $vault, string $key, string $model): void
    {
        if (self::hasKey($vault, $key, $model)) return;
        throw new HttpError(422, match (self::provider($model)) {
            'gpu'=>t('To make pictures with {model}, make the notebook\'s token in Settings › GPU and start the notebook in Colab.', ['model'=>self::model($model)['label'] ?? $model]),
            'gemini'=>t('Add the Gemini API key in Settings to generate with Nano Banana.'), default=>t('Add your OpenAI API key in Settings.'),
        });
    }

    /** The client that makes the pictures of a model on this server (the GPU's are made by its worker, never here). */
    public static function client(string $model, Vault $vault, string $key, array $config): ImageClient
    {
        if (self::provider($model) === 'gpu') throw new HttpError(422, t('Pictures of {model} are made by the GPU worker.', ['model'=>self::model($model)['label'] ?? $model]));
        return self::provider($model) === 'gemini'
            ? new GeminiImages($vault->geminiKey($key), (int)$config['api_timeout'])
            : new OpenAI($vault->apiKey($key), (int)$config['api_timeout']);
    }

    /** The error kinds of a request with no credit left, by provider. */
    public const CREDIT_KINDS = ['credit', 'gemini-credit'];
}
