<?php
declare(strict_types=1);
namespace Studio;

/**
 * Pictures with Google's Gemini image models (Nano Banana) through the Interactions API, for the same requests the
 * OpenAI client takes (Jobs): a picture from a prompt, an edit with references, an area marked with the brush, an
 * expansion, an upscale. What the app adds to the prompt (the fidelity rules of an edit, the Limpar notice, the
 * direction of an image of a varied batch) is the same as for GPT Image; the planning of the prompt and of the
 * directions is made by a Gemini text model, so a person with only a Gemini key can use all of it.
 *
 * What differs from GPT Image, and how the app makes up for it:
 * - Sizes: the model makes a few aspect ratios at three resolutions (1K, 2K, 4K). The size asked for is matched to the
 *   nearest ratio and to the resolution that covers it; a ratio that came out different is cropped to the one asked for.
 * - Quality: the model's thinking level (Rápida thinks the least). Nano Banana Pro always thinks at its own level.
 * - A marked area (the brush, Expandir): there is no mask, so a copy of the picture with the area painted magenta goes
 *   last, and the prompt says that only that area changes. Transparent areas (Expandir) go as flat gray.
 * - No transparent background, no streamed previews.
 */
final class GeminiImages implements ImageClient
{
    /**
     * The image models. Prices per 1M tokens, US$ (Google's page on 2026-11-20, Standard tier): input (text and images),
     * output text and thinking, output image; and what one image costs at each resolution (1K is about 1 MP, 2K about
     * 4 MP, 4K about 16 MP). qualities: what the app's quality means for the model (its thinking level).
     */
    public const MODELS = [
        'gemini-nano-banana-2.1'=>['label'=>'Nano Banana 2.1 · Gemini', 'flex'=>true, 'complexity'=>false, 'extended'=>false,
            'qualities'=>['low'=>'minimal', 'medium'=>'medium', 'high'=>'high'], 'tiers'=>['1K', '2K', '4K'], 'min_tier'=>'1K', 'max_images'=>14, 'transparent'=>false,
            'ratios'=>['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'],
            'prices'=>['input'=>1.5, 'text'=>7.5, 'image'=>30.0], 'per_image'=>['1K'=>0.0336, '2K'=>0.0504, '4K'=>0.113],
            'note'=>'Fast and cheap. The quality chooses how much it thinks before drawing.'],
        'gemini-3-pro-image'=>['label'=>'Nano Banana Pro · Gemini', 'flex'=>true, 'complexity'=>false, 'extended'=>false,
            'qualities'=>['high'=>null], 'tiers'=>['1K', '2K', '4K'], 'min_tier'=>'2K', 'max_images'=>14, 'transparent'=>false,
            'ratios'=>['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
            'prices'=>['input'=>2.0, 'text'=>12.0, 'image'=>120.0], 'per_image'=>['1K'=>0.134, '2K'=>0.134, '4K'=>0.24],
            'note'=>'Google\'s highest quality; it always thinks before drawing. 1K and 2K cost the same, so it comes out at 2K.'],
    ];
    /** A model's settings: the app's own, or those of the closest one for a newer model the key lists (Catalog). */
    public static function meta(string $id): ?array
    { return self::MODELS[$id] ?? ((Catalog::image($id)['provider'] ?? null) === 'gemini' ? Catalog::image($id) : null); }

    /** The text model that plans prompts and the directions of a varied batch for these models. */
    public const PLANNER = 'gemini-3.8-flash';
    /** The magenta of the copy that marks the area to change. */
    private const MARK = [255, 0, 255];

    private ?\Closure $monitor = null;
    private \ArrayObject $paid;

    /** @param ?\Closure $transport same signature as Stream::post, for tests */
    public function __construct(private string $key, private int $timeout = 600, private ?\Closure $transport = null) { $this->paid = new \ArrayObject(); }

    public function withMonitor(\Closure $monitor): ImageClient { $copy = clone $this; $copy->monitor = $monitor; return $copy; }
    /** Gemini doesn't stream previews of a picture. */
    public function withPreview(\Closure $preview): ImageClient { return $this; }
    public function paidCount(): int { return count($this->paid); }
    public function paid(int $from = 0): array { return array_slice($this->paid->getArrayCopy(), $from); }

    private function signal(?string $phase): bool { return $this->monitor !== null && ($this->monitor)($phase) === true; }
    private function step(string $phase): void { if ($this->signal($phase)) throw new Cancelled(); }

    /**
     * The options of a request for a Gemini model, valid and complete. They are checked as GPT Image's are (sizes,
     * counts, format), and then: no transparent background, and the quality is one the model has (the nearest one).
     */
    public static function options(array $input): array
    {
        $model = (string)$input['model']; $m = self::meta($model);
        if (($input['background'] ?? 'auto') === 'transparent') throw new HttpError(422, t('Nano Banana does not make transparent backgrounds. Choose an automatic or opaque background, or a GPT Image model.'));
        $o = OpenAI::options(['model'=>'gpt-image-2.6-flare'] + $input);
        $o['model'] = $model;
        $qualities = array_keys($m['qualities']);
        if (!in_array($o['quality'], $qualities, true)) $o['quality'] = in_array($o['quality'], ['low'], true) ? $qualities[0] : end($qualities);
        $o['previews'] = 0; $o['moderation'] = 'low'; $o['complexity'] = 'auto';
        return $o;
    }

    /** The aspect ratio of the model nearest to a width and height. */
    public static function ratio(string $model, int $width, int $height): string
    {
        $best = '1:1'; $distance = INF; $want = log($width / max(1, $height));
        foreach (self::meta($model)['ratios'] as $r) {
            [$a, $b] = array_map('intval', explode(':', $r));
            $d = abs(log($a / $b) - $want);
            if ($d < $distance) { $distance = $d; $best = $r; }
        }
        return $best;
    }

    /** The resolution that covers a size: about 1 MP is 1K, about 4 MP 2K, more 4K (never below the model's least). */
    public static function tier(string $model, ?int $width, ?int $height): string
    {
        $m = self::meta($model);
        $tier = $width === null ? '1K' : ($width * $height <= 1700000 ? '1K' : ($width * $height <= 5000000 ? '2K' : '4K'));
        $order = ['1K'=>0, '2K'=>1, '4K'=>2];
        if ($order[$tier] < $order[$m['min_tier']]) $tier = $m['min_tier'];
        return in_array($tier, $m['tiers'], true) ? $tier : end($m['tiers']);
    }

    /** What one picture costs (the output, by its resolution), US$, before the input is counted. */
    public static function perImage(string $model, string $tier): float { return (float)(self::meta($model)['per_image'][$tier] ?? 0); }

    public function generate(array $job): array
    {
        $s = $job['options']; $model = $s['model']; $m = self::meta($model);
        $edit = ($job['operation'] ?? 'generate') !== 'generate';
        $prompt = OpenAI::resolveMentions($job['prompt'], count($job['references']), $edit);
        $planned = null; $skipped = null; $planUsage = null;
        if ($s['thinking'] !== 'off') {
            $this->step('planning');
            try { [$planUsage, $planned, $skipped] = $this->planPrompt($prompt, $job['references'], $s['thinking'], !empty($job['cleaned'])); }
            catch (Cancelled $e) { throw $e; }
            catch (HttpError $e) { $skipped = 'failed'; error_log('Atelier: Gemini prompt planning failed (HTTP '.$e->status.'), generating from the prompt as written'); }
            if ($planned !== null) $prompt = $planned;
        }
        $prompt = OpenAI::finishPrompt($job, $prompt);
        // The images, in the order of the request (so @1, @2… name the same ones), and the copy that marks the area.
        $images = []; $flatten = !empty($job['mask']);
        foreach ($job['references'] as $n => $url) $images[] = self::inputImage($url, $n === 0 && $flatten);
        if (!empty($job['mask']) && $job['references']) {
            $images[] = self::markedImage($job['references'][0], $job['mask']);
            $prompt = self::markNote(count($images), ($job['operation'] ?? '') === 'expand')."\n\n".$prompt;
        }
        if (count($images) > $m['max_images']) throw new HttpError(422, t('{model} reads up to {most} images per request (references, the edited image and the copy that marks the area). Remove some references.', ['model'=>$m['label'], 'most'=>$m['max_images']]));
        [$width, $height] = $s['size'] === 'auto' ? [null, null] : array_map('intval', explode('x', $s['size']));
        $tier = self::tier($model, $width, $height);
        $format = ['type'=>'image', 'mime_type'=>$s['format'] === 'jpeg' ? 'image/jpeg' : 'image/png', 'image_size'=>$tier];
        if ($width !== null) $format['aspect_ratio'] = self::ratio($model, $width, $height);
        $content = [['type'=>'text', 'text'=>$prompt]];
        foreach ($images as $im) $content[] = ['type'=>'image', 'mime_type'=>$im['mime'], 'data'=>$im['data']];
        $body = ['model'=>$model, 'store'=>false, 'input'=>[['type'=>'user_input', 'content'=>$content]], 'response_format'=>$format, 'safety_settings'=>Gemini::safety()];
        $level = $m['qualities'][$s['quality']] ?? null;
        if ($level !== null) $body['generation_config'] = ['thinking_level'=>$level];
        $this->step('generating');
        $json = Gemini::call($this->key, $body, $this->timeout, $this->transport, fn() => $this->signal(null));
        $found = self::outputImage($json);
        $usage = self::usage($json['usage'] ?? null, $model, $tier);
        if ($found === null) {
            $state = (string)($json['status'] ?? '');
            $code = is_string($json['errors'][0]['code'] ?? null) ? strtolower(preg_replace('/^.*\//', '', $json['errors'][0]['code'])) : null;
            error_log('Atelier: Gemini image without an image'.($state !== '' ? ' status='.preg_replace('/[^a-z_]/', '', $state) : '').($code ? ' code='.preg_replace('/[^a-z_]/', '', $code) : ''));
            throw Gemini::codeError($code ?? 'no_image');
        }
        $bytes = self::finish(base64_decode($found['data'], true) ?: '', $width, $height, $s);
        return ['data'=>[['b64_json'=>base64_encode($bytes)]], 'planned_prompt'=>$planned, 'planning_skipped'=>$skipped, 'usage'=>$usage, 'planning_usage'=>$planUsage, 'sent_prompt'=>$prompt];
    }

    /** What the prompt says about the copy that marks the area, which goes as the last image. */
    private static function markNote(int $last, bool $expand): string
    {
        return 'About the attached images: the last one (image '.$last.') is a copy of image 1 with the area to '.($expand ? 'fill' : 'change').' painted in flat magenta. '
            .($expand ? 'In image 1 that area is flat gray: it is empty canvas around the original picture, to be generated. ' : '')
            .'That magenta area is what the mask means below: change only that area of image 1, and keep everything outside it exactly as in image 1. '
            .'Return the whole picture at the size and framing of image 1, with no magenta, no gray placeholder and no outline of the area left in it.';
    }

    /**
     * An image as it goes to the model: up to 2048 px on its longest side (the model doesn't read more), JPEG, or PNG
     * when it keeps transparency. $flatten puts transparent areas on flat gray (the empty canvas of Expandir).
     * @return array{mime:string,data:string}
     */
    public static function inputImage(string $url, bool $flatten = false): array
    {
        if (!preg_match('#\Adata:(image/(?:png|jpeg|webp));base64,(.+)\z#s', $url, $m)) throw new HttpError(422, t('Invalid reference image.'));
        $bytes = base64_decode($m[2], true);
        $src = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (!$src) throw new HttpError(422, t('Invalid reference image.'));
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, 2048 / max($w, $h));
        $alpha = !$flatten && $m[1] !== 'image/jpeg' && self::hasAlpha($src);
        // Small enough and with nothing to flatten: as it is.
        if ($scale >= 1 && !$flatten && $m[1] !== 'image/webp' && strlen($bytes) <= 7 * 1024 * 1024) { imagedestroy($src); return ['mime'=>$m[1], 'data'=>base64_encode($bytes)]; }
        $out = imagecreatetruecolor(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
        if ($alpha) { imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127)); }
        else imagefill($out, 0, 0, $flatten ? imagecolorallocate($out, 128, 128, 128) : imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start(); $alpha ? imagepng($out, null, 6) : imagejpeg($out, null, 92); $data = (string)ob_get_clean();
        imagedestroy($src); imagedestroy($out);
        return ['mime'=>$alpha ? 'image/png' : 'image/jpeg', 'data'=>base64_encode($data)];
    }

    private static function hasAlpha(\GdImage $im): bool
    {
        $w = imagesx($im); $h = imagesy($im); $step = max(1, (int)(max($w, $h) / 64));
        for ($y = 0; $y < $h; $y += $step) for ($x = 0; $x < $w; $x += $step) if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 8) return true;
        return false;
    }

    /**
     * The copy of the picture that marks the area to change: the mask's transparent pixels (where the image API would
     * draw) painted magenta over the picture, at up to 1024 px, as JPEG.
     * @return array{mime:string,data:string}
     */
    public static function markedImage(string $imageUrl, string $maskUrl): array
    {
        $read = static function (string $url): \GdImage {
            $bytes = preg_match('#\Adata:image/[a-z]+;base64,(.+)\z#s', $url, $m) ? base64_decode($m[1], true) : false;
            $im = $bytes === false ? false : @imagecreatefromstring($bytes);
            if (!$im) throw new HttpError(422, t('Invalid image or mask.'));
            return $im;
        };
        $src = $read($imageUrl); $mask = $read($maskUrl);
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, 1024 / max($w, $h));
        $ow = max(1, (int)round($w * $scale)); $oh = max(1, (int)round($h * $scale));
        $out = imagecreatetruecolor($ow, $oh); imagefill($out, 0, 0, imagecolorallocate($out, 128, 128, 128));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $ow, $oh, $w, $h);
        // The mask at the same size, keeping its transparency.
        $small = imagecreatetruecolor($ow, $oh); imagealphablending($small, false); imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $mask, 0, 0, 0, 0, $ow, $oh, imagesx($mask), imagesy($mask));
        [$r, $g, $b] = self::MARK;
        for ($y = 0; $y < $oh; $y++) for ($x = 0; $x < $ow; $x++) {
            if (((imagecolorat($small, $x, $y) >> 24) & 0x7F) < 64) continue;
            $c = imagecolorat($out, $x, $y);
            // Mostly magenta, with a little of the picture showing through, so the area is clear and still readable.
            imagesetpixel($out, $x, $y, (int)((int)round(0.8 * $r + 0.2 * (($c >> 16) & 0xFF)) << 16 | (int)round(0.8 * $g + 0.2 * (($c >> 8) & 0xFF)) << 8 | (int)round(0.8 * $b + 0.2 * ($c & 0xFF))));
        }
        ob_start(); imagejpeg($out, null, 90); $data = (string)ob_get_clean();
        imagedestroy($src); imagedestroy($mask); imagedestroy($small); imagedestroy($out);
        return ['mime'=>'image/jpeg', 'data'=>base64_encode($data)];
    }

    /** The picture in an answer: the last image of the model's output (thinking can show drafts before it). */
    public static function outputImage(array $json): ?array
    {
        $found = null;
        $look = static function (mixed $items) use (&$found): void {
            foreach (is_array($items) ? $items : [] as $c) if (is_array($c) && ($c['type'] ?? '') === 'image' && is_string($c['data'] ?? null) && $c['data'] !== '') $found = $c;
        };
        foreach (is_array($json['steps'] ?? null) ? $json['steps'] : [] as $step) {
            if (!is_array($step) || ($step['type'] ?? 'model_output') !== 'model_output') continue;
            $look($step['content'] ?? null);
        }
        // The shape of the API before May 2026, and the shortcut some answers carry.
        if ($found === null) $look($json['outputs'] ?? null);
        if ($found === null && is_string($json['output_image']['data'] ?? null)) $found = $json['output_image'] + ['type'=>'image'];
        return $found;
    }

    /**
     * The usage of a picture in the shape the app keeps for OpenAI's (input with its text and image parts, output with
     * its image and text parts, thinking counted with the text), with Google's own numbers kept apart.
     */
    public static function usage(mixed $usage, string $model, string $tier): ?array
    {
        if (!is_array($usage)) return null;
        $t = Gemini::tokens($usage);
        $modality = static function (mixed $list, string $kind): ?int {
            if (!is_array($list)) return null;
            $sum = null;
            foreach ($list as $e) if (is_array($e) && strtolower((string)($e['modality'] ?? '')) === $kind) $sum = (int)$sum + (int)($e['tokens'] ?? $e['token_count'] ?? 0);
            return $sum;
        };
        $input = $t['input'] + $t['read'];
        $imageIn = $modality($usage['input_tokens_by_modality'] ?? null, 'image') ?? 0;
        $imageOut = $modality($usage['output_tokens_by_modality'] ?? null, 'image') ?? $t['output'];
        return ['provider'=>'gemini', 'tier'=>$tier, 'input_tokens'=>$input, 'output_tokens'=>$t['output'] + $t['thinking'], 'total_tokens'=>$input + $t['output'] + $t['thinking'],
            'input_tokens_details'=>['text_tokens'=>max(0, $input - $imageIn), 'image_tokens'=>min($input, $imageIn), 'cached_tokens'=>$t['read']],
            'output_tokens_details'=>['image_tokens'=>min($t['output'], $imageOut), 'text_tokens'=>max(0, $t['output'] - $imageOut) + $t['thinking'], 'reasoning_tokens'=>$t['thinking']],
            'gemini'=>array_filter($usage, fn($v) => is_int($v) || is_array($v))];
    }

    /** What a picture cost, US$, from its usage (or from its resolution, when the usage is missing). */
    public static function cost(string $model, ?array $usage, string $tier): float
    {
        $p = self::meta($model)['prices'];
        if ($usage === null) return self::perImage($model, $tier);
        $in = (int)($usage['input_tokens'] ?? 0); $cached = (int)($usage['input_tokens_details']['cached_tokens'] ?? 0);
        $image = (int)($usage['output_tokens_details']['image_tokens'] ?? 0); $text = (int)($usage['output_tokens_details']['text_tokens'] ?? 0);
        return (($in - $cached) * $p['input'] + $cached * $p['input'] * 0.1 + $image * $p['image'] + $text * $p['text']) / 1e6;
    }

    /**
     * The picture as the request asked for it: cropped to the ratio asked for when the model's nearest one differs, and
     * in WebP when that was the format.
     */
    private static function finish(string $bytes, ?int $width, ?int $height, array $s): string
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info) throw new HttpError(502, t('Gemini returned an invalid image.'));
        $crop = null;
        if ($width !== null && $height !== null) {
            $want = $width / $height; $got = $info[0] / $info[1];
            if (abs($got / $want - 1) > 0.02) $crop = $got > $want ? [(int)round($info[1] * $want), $info[1]] : [$info[0], (int)round($info[0] / $want)];
        }
        $webp = $s['format'] === 'webp';
        if ($crop === null && !$webp) return $bytes;
        $src = @imagecreatefromstring($bytes); if (!$src) return $bytes;
        if ($crop !== null) {
            $out = imagecreatetruecolor($crop[0], $crop[1]);
            imagecopy($out, $src, 0, 0, intdiv($info[0] - $crop[0], 2), intdiv($info[1] - $crop[1], 2), $crop[0], $crop[1]);
            imagedestroy($src); $src = $out;
        }
        ob_start();
        if ($webp && function_exists('imagewebp')) imagewebp($src, null, (int)$s['compression']);
        elseif ($info['mime'] === 'image/jpeg') imagejpeg($src, null, 95);
        else imagepng($src, null, 6);
        $data = (string)ob_get_clean(); imagedestroy($src);
        return $data !== '' ? $data : $bytes;
    }

    // =================================================================================================================
    // Planning, with Gemini's text model
    // =================================================================================================================

    /** A request to the planning model; returns its text and its usage (kept with what the request paid). */
    private function plan(string $instructions, array $content, string $effort, string $kind, ?string $paidEffort): array
    {
        $body = ['model'=>self::PLANNER, 'store'=>false, 'system_instruction'=>$instructions, 'input'=>[['type'=>'user_input', 'content'=>$content]], 'safety_settings'=>Gemini::safety(),
            'generation_config'=>['thinking_level'=>Gemini::thinking($effort), 'max_output_tokens'=>16000]];
        $json = Gemini::call($this->key, $body, $this->timeout, $this->transport, fn() => $this->signal(null));
        $usage = is_array($json['usage'] ?? null) ? self::textUsage($json['usage']) : null;
        if ($usage !== null) $this->paid[] = ['model'=>self::PLANNER, 'usage'=>$usage, 'kind'=>$kind, 'effort'=>$paidEffort];
        $text = '';
        foreach (is_array($json['steps'] ?? null) ? $json['steps'] : (is_array($json['outputs'] ?? null) ? [['type'=>'model_output', 'content'=>$json['outputs']]] : []) as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') continue;
            foreach (is_array($step['content'] ?? null) ? $step['content'] : [] as $c) if (is_array($c) && ($c['type'] ?? '') === 'text') $text .= (string)($c['text'] ?? '');
        }
        return [trim($text), $usage, (string)($json['status'] ?? 'completed')];
    }

    /** A planning call's usage in the shape the app keeps for OpenAI's (thinking counted with the output), Google's numbers apart. */
    public static function textUsage(array $usage): array
    {
        $t = Gemini::tokens($usage);
        return ['model'=>self::PLANNER, 'input_tokens'=>$t['input'] + $t['read'], 'output_tokens'=>$t['output'] + $t['thinking'], 'total_tokens'=>$t['input'] + $t['read'] + $t['output'] + $t['thinking'],
            'input_tokens_details'=>['cached_tokens'=>$t['read']], 'output_tokens_details'=>['reasoning_tokens'=>$t['thinking']], 'gemini'=>array_filter($usage, fn($v) => is_int($v) || is_array($v))];
    }

    /** The references as the planning model sees them: small, as it only needs to know what they show. */
    private static function planningImages(array $references): array
    {
        $out = [];
        foreach ($references as $url) {
            try { $im = self::smallImage($url); } catch (HttpError) { continue; }
            $out[] = ['type'=>'image', 'mime_type'=>$im['mime'], 'data'=>$im['data']];
        }
        return $out;
    }

    private static function smallImage(string $url): array
    {
        if (!preg_match('#\Adata:(image/(?:png|jpeg|webp));base64,(.+)\z#s', $url, $m)) throw new HttpError(422, t('Invalid image.'));
        $src = @imagecreatefromstring((string)base64_decode($m[2], true)); if (!$src) throw new HttpError(422, t('Invalid image.'));
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, 768 / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start(); imagejpeg($out, null, 85); $data = (string)ob_get_clean(); imagedestroy($src); imagedestroy($out);
        return ['mime'=>'image/jpeg', 'data'=>base64_encode($data)];
    }

    /** @return array{0:?array,1:?string,2:?string} the usage, the planned prompt, and why there is none */
    private function planPrompt(string $prompt, array $references, string $effort, bool $cleaned): array
    {
        $content = array_merge([['type'=>'text', 'text'=>$cleaned && $references ? $prompt."\n\n".OpenAI::CLEANED_PLANNING : $prompt]], self::planningImages($references));
        [$text, $usage, $state] = $this->plan(OpenAI::PLAN_INSTRUCTIONS, $content, $effort, 'planning', $effort);
        if ($state !== 'completed' && $state !== 'requires_action') return [$usage, null, 'incomplete'];
        if ($text === '' || mb_strlen($text) > 32000) return [$usage, null, 'incomplete'];
        return [$usage, $text, null];
    }

    public function planVariations(string $prompt, array $references, int $count, bool $edit): array
    {
        $this->step('varying');
        $content = array_merge([['type'=>'text', 'text'=>"Request:\n".OpenAI::resolveMentions($prompt, count($references), $edit)]], self::planningImages($references));
        $instructions = OpenAI::variationInstructions($count, $edit, (bool)$references)
            .' Answer with only a JSON object, with no other text and no code fence: {"variations": ["…", "…"]}.';
        [$text, $usage] = $this->plan($instructions, $content, 'low', 'variation', 'low');
        $text = preg_replace('/\A\s*```(?:json)?\s*|\s*```\s*\z/i', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $m)) $json = json_decode($m[0], true);
        $variations = OpenAI::cleanVariations(is_array($json) ? ($json['variations'] ?? (array_is_list($json) ? $json : null)) : null);
        if (!$variations) throw new HttpError(502, t('The planning of the variations returned no directions.'));
        return ['variations'=>array_slice($variations, 0, $count), 'usage'=>$usage];
    }
}
