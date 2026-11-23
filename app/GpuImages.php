<?php
declare(strict_types=1);
namespace Studio;

/**
 * Pictures with open models on the account's GPU worker (Gpu: a notebook on Google Colab), for the same requests the
 * image APIs take (Jobs): from a prompt, with references (@1 is <Picture 1>), an edit, an area marked with the brush, an
 * expansion, an upscale, a varied batch. Nothing here calls the model: the request waits in line (runner 'gpu') until
 * the worker takes it (Jobs::claimGpu) with the prompt as prompt() finishes it, and the worker sends the picture back.
 *
 * What differs from the image APIs, and how the app makes up for it:
 * - Size: MiniMax H3-Image draws at about 16 MP natively (4 to 20 MP). The quality sets the megapixels; the size asked
 *   for gives the aspect ratio, and the area when it is bigger (Ampliar).
 * - Prompt planning: the H3 prompt expander of ComfyUI's official templates, on the GPU itself, at no cost.
 * - A marked area (the brush, Expandir): the model has no mask, so a copy with the area painted magenta goes last (as for
 *   Nano Banana), and the worker pastes the result into the original only inside the area, so nothing else changes.
 * - LoRAs from the account's list (Gpu::loras), each at the strength chosen.
 * - No transparent background and no previews. No cost per picture: the GPU's time is paid to whoever rents it.
 */
final class GpuImages
{
    /**
     * The image models of the GPU worker. engine: the worker's model (Gpu::imageEngines); qualities: the megapixels of
     * each quality.
     */
    public const MODELS = [
        'minimax-h3-image'=>['label'=>'MiniMax H3-Image · GPU', 'engine'=>'h3-image', 'flex'=>true, 'complexity'=>false, 'extended'=>false,
            'qualities'=>['low'=>4, 'medium'=>8, 'high'=>16], 'max_images'=>16, 'max_loras'=>4, 'transparent'=>false, 'gpu'=>true,
            'note'=>'An open model on your own GPU (Colab), with no cost per picture: about 4, 8 or 16 MP depending on the quality.'],
    ];
    /** The most megapixels a picture is made at (the model's effective range ends at about 20 MP). */
    public const MAX_MEGAPIXELS = 20;
    /** The largest picture a request may ask for (an upscale or an expansion is brought to it after the model). */
    public const MAX_SIDE = 12000;
    public const MAX_PIXELS = 40000000;
    /** The strength a LoRA can be applied at. */
    public const LORA_STRENGTH = [-2.0, 2.0];

    public static function meta(string $id): ?array { return self::MODELS[$id] ?? null; }

    /** What the page gets of a model: its settings, with its note in the interface's language. */
    public static function shown(array $m): array
    {
        $m['note'] = t($m['note']);
        return array_intersect_key($m, array_flip(['label', 'flex', 'complexity', 'extended', 'qualities', 'max_images', 'max_loras', 'transparent', 'gpu', 'note']));
    }

    /**
     * The options of a request for a GPU model, valid and complete. They are checked as GPT Image's are (sizes, counts,
     * format), and then: no transparent background, a quality the model has, an optional seed and the LoRAs chosen
     * (ids and strengths; Jobs adds what each one is from the account's list).
     */
    public static function options(array $input): array
    {
        $model = (string)$input['model']; $m = self::meta($model);
        if (($input['background'] ?? 'auto') === 'transparent') throw new HttpError(422, t('{model} does not make transparent backgrounds. Choose an automatic or opaque background, or a GPT Image model.', ['model'=>$m['label']]));
        // Any size it can draw in: the model works at its own megapixels, and the worker brings the result to this size
        // where it must (an expansion, an upscale). Up to the size of a picture the library imports.
        $size = $input['size'] ?? 'auto';
        if (!is_string($size)) throw new HttpError(422, t('Invalid size.'));
        if ($size !== 'auto') {
            if (!preg_match('/\A([0-9]{2,5})x([0-9]{2,5})\z/D', $size, $match)) throw new HttpError(422, t('Invalid size.'));
            [$w, $h] = [(int)$match[1], (int)$match[2]];
            if (min($w, $h) < 64 || max($w, $h) > self::MAX_SIDE || max($w, $h) / min($w, $h) > 5 || $w * $h > self::MAX_PIXELS)
                throw new HttpError(422, t('Use a size from 64 to {side} pixels a side, up to {mp} megapixels and a ratio up to 5:1.', ['side'=>self::MAX_SIDE, 'mp'=>self::MAX_PIXELS / 1000000]));
        }
        $o = OpenAI::options(['model'=>'gpt-image-2.6-flare', 'size'=>'auto'] + $input);
        $o['model'] = $model; $o['size'] = $size;
        // Automatic, Extra high and Maximum are the highest it has.
        if (!isset($m['qualities'][$o['quality']])) $o['quality'] = array_key_last($m['qualities']);
        $o['previews'] = 0; $o['moderation'] = 'low'; $o['complexity'] = 'auto'; $o['background'] = 'auto';
        $seed = $input['seed'] ?? null;
        if ($seed !== null && (!is_int($seed) || $seed < 0 || $seed > 2 ** 53)) throw new HttpError(422, t('Invalid seed.'));
        $o['seed'] = $seed;
        $loras = $input['loras'] ?? [];
        if (!is_array($loras) || !array_is_list($loras)) throw new HttpError(422, t('Invalid LoRA.'));
        if (count($loras) > $m['max_loras']) throw new HttpError(422, t('Use up to {n} LoRAs.', ['n'=>$m['max_loras']]));
        $chosen = [];
        foreach ($loras as $l) {
            if (!is_array($l) || !is_string($l['id'] ?? null) || !preg_match('/\A[a-f0-9]{16}\z/D', $l['id'])) throw new HttpError(422, t('Invalid LoRA.'));
            $strength = filter_var($l['strength'] ?? 1, FILTER_VALIDATE_FLOAT);
            if ($strength === false || $strength < self::LORA_STRENGTH[0] || $strength > self::LORA_STRENGTH[1]) throw new HttpError(422, t('Choose a LoRA strength from {min} to {max}.', ['min'=>self::LORA_STRENGTH[0], 'max'=>self::LORA_STRENGTH[1]]));
            if (isset($chosen[$l['id']])) continue;
            $chosen[$l['id']] = ['id'=>$l['id'], 'strength'=>round((float)$strength, 2)];
        }
        $o['loras'] = array_values($chosen);
        $o['engine'] = $m['engine'];
        return $o;
    }

    /** The megapixels a request is made at: its quality's, or the size it asks for when that is bigger (Ampliar). */
    public static function megapixels(array $options): float
    {
        $m = self::meta((string)$options['model']);
        $mp = (float)($m['qualities'][$options['quality']] ?? end($m['qualities']));
        if (preg_match('/\A(\d+)x(\d+)\z/', (string)($options['size'] ?? ''), $s)) $mp = max($mp, (int)$s[1] * (int)$s[2] / 1048576);
        return min(self::MAX_MEGAPIXELS, round($mp, 2));
    }

    /**
     * The prompt as the worker gets it: @1, @2… as the model reads the pictures (<Picture 1>…), then what the server adds
     * for every image model (the direction of a varied batch, that it is one image of a set, the fidelity rules of an
     * edit, the Limpar notice), and what the copy that marks an area means.
     */
    public static function prompt(array $p): string
    {
        $count = count($p['references'] ?? []);
        $operation = (string)($p['operation'] ?? 'generate');
        $prompt = OpenAI::finishPrompt($p, self::mentions((string)$p['prompt'], $count));
        // The fidelity rules and the Limpar notice speak of "the input image".
        if ($operation !== 'generate' && $count) $prompt = "<Picture 1> is the input image, the one being changed.\n\n".$prompt;
        if (!empty($p['mask']) && $count) $prompt = self::markNote($count + 1, $operation === 'expand')."\n\n".$prompt;
        return mb_strlen($prompt) <= 32000 ? $prompt : mb_substr($prompt, 0, 32000);
    }

    /**
     * What the prompt expander (Planejar) gets and what goes around its caption: typed, the request as written (with
     * <Picture n>, and the note on the copy that marks the area, since the expander sees that copy too); before and
     * after, what prompt() adds around it, so the caption gets the same fidelity rules and notices.
     * @return array{typed:string,before:string,after:string}
     */
    public static function planParts(array $p): array
    {
        $marker = "\u{E000}";
        $parts = explode($marker, self::prompt(['prompt'=>$marker] + $p), 2);
        $count = count($p['references'] ?? []);
        $typed = self::mentions((string)$p['prompt'], $count);
        if (!empty($p['mask']) && $count) $typed = self::markNote($count + 1, ($p['operation'] ?? '') === 'expand')."\n\n".$typed;
        return ['typed'=>mb_substr($typed, 0, 32000), 'before'=>$parts[0], 'after'=>$parts[1] ?? ''];
    }

    /** @1…@16 (of the pictures sent) as the model names them: <Picture 1>… */
    public static function mentions(string $prompt, int $images): string
    {
        if ($images < 1 || !str_contains($prompt, '@')) return $prompt;
        return preg_replace_callback('/(?<![\p{L}\p{N}_@])@([0-9]{1,2})(?![\p{L}\p{N}_])/u',
            fn(array $m) => (int)$m[1] >= 1 && (int)$m[1] <= $images ? '<Picture '.(int)$m[1].'>' : $m[0], $prompt) ?? $prompt;
    }

    /** What the prompt says about the copy that marks the area, which goes as the last picture. */
    public static function markNote(int $last, bool $expand): string
    {
        return "About the pictures: the last one (<Picture $last>) is a copy of <Picture 1> with the area to ".($expand ? 'fill' : 'change').' painted in flat magenta. '
            .($expand ? 'In <Picture 1> that area is flat gray: it is empty canvas around the original picture, to be generated. ' : '')
            .'Change only that area of <Picture 1>, and keep everything outside it exactly as in <Picture 1>. '
            .'Return the whole picture at the framing of <Picture 1>, with no magenta, no gray placeholder and no outline of the area left in it.';
    }
}
