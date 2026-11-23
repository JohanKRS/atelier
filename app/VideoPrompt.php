<?php
declare(strict_types=1);
namespace Studio;

/**
 * The prompt helper of the video window: one call to Claude, GPT or Gemini (with the account's own key) that rewrites the
 * person's idea into the prompt MiniMax H3 reads best, in the structure of its official prompt guides (the base format
 * for text and first/last frames, the reference format for everything else), with the request's inputs named the way
 * the worker connects them (labels()).
 */
final class VideoPrompt
{
    /** The model of each provider: fast enough to answer within a web request, good with structure and dialogue. */
    public const MODELS = ['claude'=>'claude-sonnet-5-5', 'gpt'=>'gpt-6.2-sol', 'gemini'=>'gemini-4-neon'];

    public const INSTRUCTIONS = <<<'TXT'
You write prompts for MiniMax H3, an open model that makes a video together with its sound (voices, effects, music) in one pass, from text and optional pictures, videos and sounds. You receive the person's idea (in any language, often rough), the settings of the request and the inputs it brings, and you return the one prompt H3 should get. Output only the prompt, in English, with no commentary, no markdown and no code fences.

KEEP THE IDEA
Keep everything the person asked for: subjects, actions and their order, camera wishes, mood, the exact words of any dialogue or on-screen text, sounds and music. Don't change the idea; make it concrete. Fill the gaps with specific choices that serve it (look, light, lens, staging, motion, sound) instead of generic words such as cinematic, beautiful or epic: describe what those words would look and sound like. Look at the attached pictures and use what they really show. Never write negative lists or bans ("no text", "avoid blur"): H3 runs without a negative prompt, so a named thing tends to appear. Say what is there instead.

TIME
The video lasts exactly the seconds given, at 24 frames per second. Fit the action to that: about one clear beat every one and a half to three seconds. [Shot 1] starts at zero and never gets a timestamp. Every later shot opens with one moment, written like "At 00:03.500, the camera cuts to ...", strictly later than the one before and inside the length; never a range. The cut verbs are: the camera cuts to, the shot cuts to, the shot transitions to, the shot changes to, the shot switches to. Cut only when something new comes (subject, place, state, viewpoint or time); small reframes are camera motion within the shot. No dissolves, fades or wipes unless asked. Inside a shot, beats can be placed with phrases like "At about two seconds, ...".

CAMERA
Plain English inside the shot: the move, plus amplitude (small or large) and speed (slow or fast) when they aren't medium, e.g. "The camera pushes in with small amplitude at slow speed toward the letter in her hands." Moves: zoom in or out, push in or pull out, pan left or right, truck left or right, tilt up or down, pedestal up or down, arc shot, tracking shot, static shot, slight or strong shake, POV, roll clockwise or counterclockwise.

VOICES
Every voice gets a speaker id in the order it first sounds: (S1), (S2); (S1,S2) for two at once; the same id in every shot; people who don't vocalize get none. The first time a speaker appears, describe the voice: who, age, gender, on or off screen, pitch, timbre, pace, accent. Spoken and sung words go inside <d>...</d> with a language tag and the exact words, in their own language; who speaks and how stays outside the tags:
The tired old fisherman (S1) mutters, his voice low and gravelly: <d>[Portuguese] Hoje o mar não quer conversa.</d>
Inside the tags use only the punctuation , . ? ! and end every sentence with one of . ? !; no ellipses, emoji or decorations. A narrator "says in an off-screen voiceover", and you then state that the lips of the person on screen remain closed. When speech goes across a cut, put <scenetrans> on both sides and say the audio continues seamlessly across the cut; use <cutoff> when the end of the video cuts a line. Write each line once, in the shots, never again in the sound fields. Keep the language the person wrote the dialogue in (English, Portuguese, Spanish, French, German, Italian, Russian, Arabic, Chinese, Japanese and Korean work well); when they give no words, write lines in the language that fits the scene. Tie each line to something visible.

TEXT ON SCREEN
In English double quotes, verbatim and in its own language: a neon sign reading "ABERTO" glows above the door.

SOUND FIELDS
overall_soundscape: one to four sentences of ambience, sounds of the actions and non-verbal sounds; no dialogue, no music. "N/A" only when the person wants total silence.
non_diegetic_music: one to three sentences about the score only the audience hears: instruments, tempo, rhythm, dynamics and how it follows the action; no mood words. "N/A" when there is none. Music the characters hear (a radio, a band in the room) goes in the shots.

THE TWO FORMATS
The request says which format to use. Field names, their order and the timing notation must be exactly as below.

[A] Base format: text alone, or a first frame, a last frame, or both.
<alignment line, only when there are frames>

integrated_multimodal_description: [Shot 1] <one or two sentences of style: medium, palette, light, texture> <what happens, in time order: subjects, actions, camera, voices and sounds in the scene> [Shot 2] At 00:04.000, the camera cuts to ...

overall_soundscape: ...

non_diegetic_music: ...

Alignment lines, exactly as written, with the numbers filled in (S.SS is the moment with two decimals, N the number of the last shot):
- first frame only: For the target video, at 0.00 seconds into the target video, <Picture 1> (from [Shot 1]) is fully referenced.
- first and last frame: How the reference pictures align with the target video — Picture 1 (from Shot 1) aligns with the 0.00-second mark of the target video; Picture 2 (from Shot N) aligns with the S.SS-second mark of the target video.
- last frame only: How the reference pictures align with the target video — <Picture 1> (from [Shot N]) aligns with the S.SS-second mark of the target video.
From a first frame, begin from exactly what the picture shows (people, clothes, place, light, framing) and develop the motion: its start, its continuous development, its result. Between a first and a last frame prefer one single shot and describe the path from one state to the other, the differences narrowing until it lands on the last picture. With only a last frame, invent a plausible earlier state and converge onto the picture in the last shot.

[B] Reference format: reference pictures, videos or sounds, pictures pinned in the middle of the video, or a video to continue, follow or edit. Six fields in this order, each starting on its own line:
subject_definitions:
summary:
retention_analysis:
detailed_description:
overall_soundscape:
non_diegetic_music:

subject_definitions: one line per subject and per input, using the labels the request gives. A subject: "<Subject 1> is the woman in <Picture 2>: <the features to follow exactly: face, hair, build, clothes, colors, materials, marks>." Say what not to carry over when it matters (the studio background of a portrait, say). A picture pinned at a moment: "<Picture 3> is the first frame of [Shot 1], showing ...", "the keyframe of [Shot 2], showing ...", "the last frame of [Shot 3], showing ...". A video: "<Video 1> is ..." and its role (the movement, the camera move, the pacing, the style, the video being edited or continued). A sound: "<Audio 1> is ..." and its role (the voice timbre of (S1), the music to reuse, the soundtrack placed from a moment). A picture that only gives a style or a costume is cited inside the subject it dresses, not as an entity of its own. An important element that no input shows can be a new <Subject n>, described in detail.
summary: one short paragraph. It starts with the task types in brackets, joined by " + ", each once: keyframe completion (a picture is an exact frame of the video), reference generation (inputs guide people, places, style, movement or camera), video editing (an existing video is changed; then start with "The target video is an edited version of <Video 1>."), video continuation (the video goes on from the end of <Video 1>), audio reuse (a sound is used as it is, whole or in part), audio reference (only the timbre, rhythm, style or texture of a sound is followed). Then what happens. Use no label that isn't defined above.
retention_analysis: one line per label, e.g. "<Subject 1> (appears in [Shot 1], [Shot 2]): fully_preserved - <what is kept>". For pictures, subjects and videos: fully_preserved (kept as defined), partially_preserved (used, but some defined traits change), attribute_transfer (its traits move onto another subject), weak_reference (only broad style, category, composition or mood). For sounds: fully_copy (the sound is the whole final track), partially_copy (part of it, or with other sounds added), reference (only timbre, rhythm, style or texture, nothing copied), weak_reference. New actions or places are not losses. No speaker ids in this field.
detailed_description: one or two sentences of style first, then the shots: "[Shot 1] ...", "[Shot 2] At 00:02.500, the shot cuts to ...". Name subjects by their labels where they first appear, and speakers as "<Subject 1> (S1)". For pinned pictures, write the shot as starting from, reaching or ending on the picture at its moment ("At 00:03.000, the shot cuts to the beach at dusk, whose keyframe corresponds to <Picture 3>"). About 350 to 500 words for a generated video; more when the dialogue needs it.
overall_soundscape and non_diegetic_music as described above.

SPECIAL CASES
- Continuing a video: the summary includes video continuation; <Video 1> is the video to continue; [Shot 1] picks up exactly where it ends (same people, place, light, movement and sound) and goes on with what the person asked. Add audio reference when its voices or music carry on.
- Following the movement of a video (pose, depth or edges): the model follows that movement frame by frame from a control signal that has no label; don't name it and don't describe the original look. Describe the new video: who or what moves, how they look, the place, the light, the sound, with the same timing as the movement (its first frame is attached so you can see the framing). Treat it as reference generation when there are reference pictures.
- Replacing a part of a video: the summary starts with [video editing]; "The target video is an edited version of <Video 1>."; <Video 1> is fully_preserved except for the replaced part; describe the new part in detail and the rest briefly, as it is.
- Keeping the original sound of a video: say that the audio of <Video 1> is reused (audio reuse, fully_copy) and add no new music unless asked.
TXT;

    public function __construct(private Vault $vault, private ?\Closure $claudeTransport = null, private ?\Closure $gptTransport = null, private ?\Closure $geminiTransport = null) {}

    /** The providers the account can use here, the first being the default. */
    public function providers(string $key): array
    {
        $out = [];
        if ($this->vault->claudeKey($key) !== '') $out[] = 'claude';
        if ($this->vault->apiKey($key) !== '') $out[] = 'gpt';
        if ($this->vault->geminiKey($key) !== '') $out[] = 'gemini';
        return $out;
    }

    /**
     * The labels H3 knows the inputs by, as the worker connects them: reference pictures first, in order, then the
     * pinned ones by moment (<Picture n>); videos and sounds in order (<Video n>, <Audio n>). The base format (text,
     * first and last frames alone) only has the pinned pictures: first, then last. The source video of a request that
     * follows a movement has no label (it only drives the ControlNet).
     * @param list<array> $inputs kind, role, at
     * @return array{format:string,labels:array<int,string>}
     */
    public static function labels(array $options, array $inputs): array
    {
        $base = ($options['mode'] ?? 'new') === 'new' && !array_filter($inputs, fn($i) => $i['kind'] !== 'image' || $i['role'] !== 'key' || !in_array($i['at'] ?? null, [0, 0.0, 'end'], true));
        $order = fn($i) => ($i['at'] ?? null) === 'end' ? INF : (float)($i['at'] ?? 0);
        $pictures = array_keys(array_filter($inputs, fn($i) => $i['kind'] === 'image' && $i['role'] === 'ref'));
        $keys = array_keys(array_filter($inputs, fn($i) => $i['kind'] === 'image' && $i['role'] === 'key'));
        usort($keys, fn($a, $b) => $order($inputs[$a]) <=> $order($inputs[$b]));
        $labels = [];
        foreach (array_merge($pictures, $keys) as $n => $index) $labels[$index] = '<Picture '.($n + 1).'>';
        $video = 0; $audio = 0;
        $control = in_array($options['op'] ?? null, ['pose','depth','edges'], true);
        foreach ($inputs as $index => $i) {
            if ($i['kind'] === 'video' && !($control && $i['role'] === 'source')) $labels[$index] = '<Video '.(++$video).'>';
            if ($i['kind'] === 'audio') $labels[$index] = '<Audio '.(++$audio).'>';
        }
        return ['format'=>$base ? 'base' : 'reference', 'labels'=>$labels];
    }

    /**
     * Rewrites the idea. $input: prompt, options (as the video window sends them), inputs (kind, role, at, and id for
     * library pictures and videos or data for a picture from the device; name for a sound), provider.
     * @return array{prompt:string,provider:string,model:string,cost:float}
     */
    public function improve(array $input, string $key): array
    {
        $idea = trim((string)($input['prompt'] ?? ''));
        if ($idea === '' || mb_strlen($idea) > 8000) throw new HttpError(422, t('Describe the video in up to 8,000 characters.'));
        $providers = $this->providers($key);
        $provider = (string)($input['provider'] ?? ($providers[0] ?? ''));
        if (!in_array($provider, $providers, true)) throw new HttpError(422, t('Add a Claude, OpenAI or Gemini key in Settings to improve prompts.'));
        $raw = is_array($input['inputs'] ?? null) ? array_slice(array_values($input['inputs']), 0, 16) : [];
        $inputs = [];
        foreach ($raw as $in) if (is_array($in) && in_array($in['kind'] ?? null, ['image','video','audio'], true) && in_array($in['role'] ?? null, ['key','ref','source'], true))
            $inputs[] = ['kind'=>$in['kind'], 'role'=>$in['role']] + (array_key_exists('at', $in) ? ['at'=>$in['at']] : []) + ['id'=>$in['id'] ?? null, 'data'=>$in['data'] ?? null, 'name'=>$in['name'] ?? null];
        [$options, $checked] = Gpu::options(is_array($input['options'] ?? null) ? $input['options'] : [], array_map(fn($i) => array_intersect_key($i, array_flip(['kind','role','at'])), $inputs));
        foreach ($checked as $n => $c) $inputs[$n]['at'] = $c['at'] ?? null;
        ['format'=>$format, 'labels'=>$labels] = self::labels($options, $inputs);

        // What the request is, line by line, with a small picture of each input the model can see.
        $lines = []; $pictures = [];
        $seconds = (float)$options['seconds'];
        $moment = fn($at) => $at === 'end' ? number_format($seconds, 2, '.', '').' s (the end)' : number_format((float)$at, 2, '.', '').' s';
        foreach ($inputs as $n => $i) {
            $label = $labels[$n] ?? null;
            $picture = null;
            if ($i['kind'] === 'image') {
                $role = $i['role'] === 'ref' ? 'reference picture' : (($i['at'] ?? null) === 0.0 || ($i['at'] ?? null) === 0 ? 'the first frame, pinned at 0.00 s' : (($i['at'] ?? null) === 'end' ? 'the last frame, pinned at '.number_format($seconds, 2, '.', '').' s' : 'a keyframe pinned at '.number_format((float)$i['at'], 2, '.', '').' s'));
                $picture = $this->picture($i, $key);
                $lines[] = "$label: $role (attached picture ".(count($pictures) + 1).')';
            } elseif ($i['kind'] === 'video') {
                $video = $this->vault->video((string)$i['id'], $key);
                $length = number_format((float)$video['duration'], 1, '.', '').' s';
                $what = match (true) {
                    $i['role'] === 'ref' => "reference video, $length",
                    ($options['op'] ?? '') === 'continue' => "the video to continue, $length; the new part starts from its last frames",
                    ($options['op'] ?? '') === 'replace' => "the video to edit, $length; replace in it: \"".($options['target'] ?? '').'"',
                    default => 'the source of the movement ('.['pose'=>'the poses of its people','depth'=>'its depth: shapes, layout and motion','edges'=>'its edges: outlines and motion'][$options['op']].'), '.$length.'; it has no label',
                };
                $picture = $this->vault->modelPreview($video, $key, 768);
                $lines[] = ($label ?? 'Control video').": $what (its first frame is attached picture ".(count($pictures) + 1).')';
            } else {
                $name = trim((string)($i['name'] ?? '')) ?: 'sound';
                $lines[] = "$label: ".($i['role'] === 'ref' ? 'reference sound' : 'a soundtrack placed from '.$moment($i['at'] ?? 0)).' ("'.mb_substr($name, 0, 80).'"; you can\'t hear it: infer its role from the idea)';
            }
            if ($picture !== null) $pictures[] = $picture;
        }
        $kind = ($options['mode'] ?? 'new') === 'new' ? 'a new video' : match ($options['op']) {
            'continue' => 'continue a video', 'pose' => 'a new video that follows the poses of a video', 'depth' => 'a new video that follows the depth of a video',
            'edges' => 'a new video that follows the edges of a video', default => 'edit a video by replacing a part of it',
        };
        $keep = ($options['keep_audio'] ?? false) ? "\n- The original sound of the video is kept as it is." : '';
        $shape = $format === 'base' && array_filter($inputs, fn($i) => $i['kind'] === 'image') ? "the frames' shape" : (($options['mode'] ?? 'new') === 'video' ? "the source video's shape" : $options['aspect']);
        $text = "Request:\n- What: $kind.\n- Length: ".number_format($seconds, 2, '.', '')." seconds at 24 fps. Shape: $shape.$keep\n- Format: ".($format === 'base' ? '[A] base' : '[B] reference')
            ."\n- Inputs:".($lines ? "\n  ".implode("\n  ", $lines) : ' none')."\n\nThe person's idea (they may have written @1, @2 for <Picture 1>, <Picture 2>, @v1 for <Video 1> and @s1 for <Audio 1>):\n\"\"\"\n$idea\n\"\"\"";

        [$prompt, $model, $usage, $cost] = match ($provider) {
            'claude' => $this->claude($text, $pictures, $key),
            'gemini' => $this->gemini($text, $pictures, $key),
            default => $this->gpt($text, $pictures, $key),
        };
        $prompt = trim(preg_replace('/\A```[a-z]*\s*|\s*```\z/', '', trim($prompt)) ?? '');
        if ($prompt === '') throw new HttpError(502, t('The model gave back an empty prompt. Try again.'));
        if (mb_strlen($prompt) > 8000) $prompt = mb_substr($prompt, 0, 8000);
        try { $this->vault->recordSpend(bin2hex(random_bytes(16)), [['model'=>$model, 'usage'=>$usage, 'kind'=>'video-prompt', 'cost'=>$cost]], $key); }
        catch (\Throwable $e) { logFailure($e); }
        return ['prompt'=>$prompt, 'provider'=>$provider, 'model'=>$model, 'cost'=>$cost];
    }

    /** A small JPEG or PNG of a picture of the request: from the library by id, or sent from the device as a data URL. */
    private function picture(array $i, string $key): array
    {
        if (is_string($i['id'] ?? null)) return $this->vault->modelPreview($this->vault->still($i['id'], $key), $key, 768);
        $url = OpenAI::imageInput((string)($i['data'] ?? ''))['url'];
        if (!preg_match('#\Adata:(image/[a-z+.-]+);base64,(.+)\z#s', $url, $m)) throw new HttpError(422, t('Invalid picture.'));
        return ['mime'=>$m[1], 'data'=>$m[2]];
    }

    private function claude(string $text, array $pictures, string $key): array
    {
        $model = self::MODELS['claude'];
        $content = array_map(fn($p) => ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>$p['mime'], 'data'=>$p['data']]], $pictures);
        $content[] = ['type'=>'text', 'text'=>$text];
        $body = ['model'=>$model, 'max_tokens'=>16000, 'system'=>self::INSTRUCTIONS, 'messages'=>[['role'=>'user', 'content'=>$content]],
            'thinking'=>['type'=>'adaptive'], 'output_config'=>['effort'=>'medium']];
        $answer = (new Claude($this->vault->claudeKey($key), 110, $this->claudeTransport))->send($body, fn() => null, fn() => false);
        $out = '';
        foreach ($answer['content'] as $block) if (($block['type'] ?? '') === 'text') $out .= (string)($block['text'] ?? '');
        $used = (string)($answer['model'] ?: $model);
        return [$out, $used, $answer['usage'], Claude::cost(isset(Claude::MODELS[$used]) ? $used : $model, $answer['usage'])];
    }

    private function gpt(string $text, array $pictures, string $key): array
    {
        $model = self::MODELS['gpt'];
        $content = array_map(fn($p) => ['type'=>'input_image', 'image_url'=>'data:'.$p['mime'].';base64,'.$p['data']], $pictures);
        $content[] = ['type'=>'input_text', 'text'=>$text];
        $body = ['model'=>$model, 'store'=>false, 'reasoning'=>['effort'=>'medium'], 'max_output_tokens'=>16000, 'instructions'=>self::INSTRUCTIONS,
            'input'=>[['role'=>'user', 'content'=>$content]]];
        $answer = (new Gpt($this->vault->apiKey($key), 110, $this->gptTransport))->send($body, fn() => null, fn() => false);
        $out = '';
        // The answer's items come as objects (they can be sent back as they came): read as arrays.
        $items = json_decode(json_encode($answer['output'], JSON_THROW_ON_ERROR), true);
        foreach (is_array($items) ? $items : [] as $item) foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $c) if (($c['type'] ?? '') === 'output_text') $out .= (string)($c['text'] ?? '');
        $usage = json_decode(json_encode($answer['usage'] ?? [], JSON_THROW_ON_ERROR), true);
        return [$out, $model, is_array($usage) ? $usage : [], Gpt::cost($model, is_array($usage) ? $usage : [])];
    }

    private function gemini(string $text, array $pictures, string $key): array
    {
        $model = self::MODELS['gemini'];
        $content = array_merge([['type'=>'text', 'text'=>$text]], array_map(fn($p) => ['type'=>'image', 'mime_type'=>$p['mime'], 'data'=>$p['data']], $pictures));
        $body = ['model'=>$model, 'store'=>false, 'system_instruction'=>self::INSTRUCTIONS, 'input'=>[['type'=>'user_input', 'content'=>$content]], 'safety_settings'=>Gemini::safety(),
            'generation_config'=>['thinking_level'=>Gemini::thinking('medium', $model), 'max_output_tokens'=>16000]];
        $json = Gemini::call($this->vault->geminiKey($key), $body, 110, $this->geminiTransport);
        $out = '';
        foreach (is_array($json['steps'] ?? null) ? $json['steps'] : (is_array($json['outputs'] ?? null) ? [['type'=>'model_output', 'content'=>$json['outputs']]] : []) as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') continue;
            foreach (is_array($step['content'] ?? null) ? $step['content'] : [] as $c) if (is_array($c) && ($c['type'] ?? '') === 'text') $out .= (string)($c['text'] ?? '');
        }
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        return [$out, $model, $usage, Gemini::cost($model, $usage)];
    }
}
