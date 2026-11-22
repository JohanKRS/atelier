<?php
declare(strict_types=1);
namespace Studio;

/** Raised when the owner cancels a request while it waits for the provider. */
final class Cancelled extends \RuntimeException {}

final class OpenAI implements ImageClient
{
    public const MODELS = [
        'gpt-image-2.6-flare'=>['label'=>'GPT Image 2.6 · Flare','flex'=>true,'complexity'=>true,'extended'=>true],
        'gpt-image-2.6-sunburst'=>['label'=>'GPT Image 2.6 · Sunburst','flex'=>true,'complexity'=>true,'extended'=>true],
        'gpt-image-2.5-flare'=>['label'=>'GPT Image 2.5 · Flare','flex'=>true,'complexity'=>false,'extended'=>true],
        'gpt-image-2.5-sunburst'=>['label'=>'GPT Image 2.5 · Sunburst','flex'=>true,'complexity'=>false,'extended'=>true],
        'gpt-image-2'=>['label'=>'GPT Image 2','flex'=>true,'complexity'=>false,'extended'=>false],
        'gpt-image-1-mini'=>['label'=>'GPT Image 1 mini','flex'=>false,'complexity'=>false,'extended'=>false],
    ];
    /** A model's settings: the app's own, or those of the closest one for a newer model the key lists (Catalog). */
    public static function meta(string $id): ?array
    { return self::MODELS[$id] ?? ((Catalog::image($id)['provider'] ?? null) === 'openai' ? Catalog::image($id) : null); }
    public const SIZES = ['1:1'=>'1024x1024','2:3'=>'1024x1536','3:2'=>'1536x1024','4:3'=>'1536x1152','3:4'=>'1152x1536','16:9'=>'1536x864','9:16'=>'864x1536','4:5'=>'1024x1280','5:4'=>'1280x1024','3:1'=>'1536x512','1:3'=>'512x1536'];
    /** The text model that plans prompts and the directions of a varied batch. */
    public const PLANNER = 'gpt-6-sol';
    private ?\Closure $monitor = null;
    private ?\Closure $preview = null;
    /** The planning calls paid through this object and its copies: one entry per call, with the usage the API reported. */
    private \ArrayObject $paid;
    public function __construct(private string $key, private int $timeout=600, private mixed $transport=null) { $this->paid=new \ArrayObject(); }
    public function paidCount(): int { return count($this->paid); }
    /**
     * Planning is paid apart from the image and also when the image then fails: the request keeps it with its spending.
     * @return list<array{model:string,usage:array,kind:string,effort:?string}> the planning calls made since $from
     */
    public function paid(int $from=0): array { return array_slice($this->paid->getArrayCopy(),$from); }
    private function charge(mixed $usage, string $kind, ?string $effort): void
    { if (is_array($usage)) $this->paid[]=['model'=>self::PLANNER,'usage'=>$usage,'kind'=>$kind,'effort'=>$effort]; }

    /** The monitor receives a phase name when a step starts and null while waiting; returning true cancels. */
    public function withMonitor(\Closure $monitor): ImageClient { $copy=clone $this; $copy->monitor=$monitor; return $copy; }
    /** Receives (string $imageBytes, int $index) for each streamed partial image. */
    public function withPreview(\Closure $preview): ImageClient { $copy=clone $this; $copy->preview=$preview; return $copy; }
    /**
     * Appended to every request that changes an existing image (edit, expand, upscale, and so Fotorrealista and
     * Melhorar too). Each pass through the model recreates the whole image and drifts a little: skin gets darker,
     * oilier and covered in pore-like dots, hair gets light specks, and photographs slide toward a glossy
     * illustration. The next edit starts from that and copies it, so over a chain of edits it adds up. Every pass is
     * told to keep the input's skin and finish and not to copy such damage, unless the request itself asks for a
     * change: the block opens by saying that a change the request asks for is made in full, because a plain "keep the
     * body exactly" was strong enough to cancel a requested change to it. It describes the clean surface it wants rather than asking for more detail or sharpness, which is
     * what makes the model invent texture. Bodies are kept as they are too: over a chain of edits the model otherwise
     * pulls unusual proportions toward ordinary ones. And one person keeps one skin: the body drifting to another tone or
     * shine than the face made the face look pasted onto another body.
     */
    public const FIDELITY = "The rules below apply only to what the request above does not ask to change. Where the request asks for a change (to a body, a face, the skin, the colors, the style or anything else), "
        ."make that change fully and visibly: nothing below may weaken, shrink or undo it. For everything else, stay faithful to the input image and do not degrade it:\n"
        ."- Skin tone: keep every person's skin exactly the same color as in the input image (same hue, lightness and undertone). Do not darken, tan, redden, yellow or gray it. "
        ."One person has one skin: the body, neck and limbs keep the same tone, finish and quality as the face and never drift apart from it, so the face never looks pasted onto another body.\n"
        ."- Skin finish: lean matte: the same amount of shine as in the input or less (when in doubt, less), so skin looks clean, freshly washed and dry. Never make skin oilier, sweatier, wetter or glossier, and no plastic or waxy sheen.\n"
        ."- Skin surface: smooth, even and natural. Pores only where the view is close enough for real pores to show (a close-up of a face or of a detail), and then fine and natural; from farther away, none. Never add or enlarge pores, light or dark dots, speckles, bumps, pits, crinkles, crepey wrinkles or grainy noise on the skin.\n"
        ."- Hair: clean, natural strands. Never add light specks, white dots, flakes or dandruff-like speckles in the hair.\n"
        ."- Bodies: apart from changes the request asks for, keep every person's build, proportions and musculature exactly as in the input (the size, shape and definition of each muscle, and the veins that show). Do not slim, soften, bulk up, idealize or correct them on your own.\n"
        ."- Colors: keep the input's white balance, exposure and contrast, unless the request asks to change how the image is exposed or recorded (a photo made from an illustration, for example). No added warm or orange grading, darkening, vignette or extra contrast.\n"
        ."- Whole image: no added noise, grain (beyond a faint, even grain the request asks for), scattered dots, webs of fine lines, oversharpening halos or compression artifacts anywhere (skin, hair, clothes, objects or background). Where the input already has them, render those areas clean instead of copying them.\n"
        ."- No style drift: never make the image glossier, more illustrated or more 3D-rendered than the input; a photograph stays a natural photograph.";

    /**
     * When the image being changed went through Limpar (on the device right before sending, or as the Limpar version it
     * is), dots and fine lines were averaged out so the model has nothing to copy them from, which also leaves smooth
     * surfaces soft and a little out of focus. Told only to "render in clean focus" at the end of the prompt, the model
     * could still read that softness as the look of the picture and keep it. So the prompt opens by saying the blur was
     * put there on purpose and must be undone, and ends with a reminder (CLEANED_REMINDER). It asks for focus, never for
     * more sharpness, texture or detail, which is what makes the model invent dots and pores, and it leaves alone a depth
     * of field the picture already has and any blur the request asks for. %s is the strength of the pass (cleanedNotice).
     */
    public const CLEANED = "About the input image, before anything else: it was blurred on purpose. Before sending it, we ran %s smoothing filter over it to take out scattered dots, noise and web-like lines that were damage, not content. "
        ."That filter is the only reason the image looks soft or slightly out of focus, above all on skin and other smooth surfaces. The blur is not part of the picture, not a camera or lens effect and not a style to keep: undo it. "
        ."The result must be in full, clean focus, the way the same picture looks with nothing blurred, with every surface showing the natural finish of its real material. "
        ."Restore focus, not texture: the dots and lines the filter took out stay out, and do not add grain, noise, pores that read as dots, a plastic or waxy look, or oversharpening halos. "
        ."Shapes, contours, edges, muscle definition and veins are intact in the input: they are content, not damage, and stay as they are. "
        ."A depth of field the picture already has (a background out of focus behind the subject, for example) stays as it is, and blur or any other change the request asks for is made as asked.";
    /** Closes the prompt when CLEANED opened it. */
    public const CLEANED_REMINDER = "Reminder about the input image: its softness comes from the smoothing filter we ran before sending it, not from the picture. "
        ."The result is in full, clean focus, without that blur and without the dots and lines the filter took out.";
    /**
     * Goes to the planning model with the request when the image was cleaned. It sees the soft image and would
     * otherwise describe it as soft focus, or ask for extra sharpness and detail to make up for it; the image prompt
     * gets CLEANED instead.
     */
    public const CLEANED_PLANNING = "Note about the first attached image (the one being changed): it was blurred on purpose before it was sent, with a smoothing filter that took out dots and web-like lines that were damage. "
        ."That softness is not part of the picture. Do not describe it, ask for it or try to make up for it (no soft focus, no extra sharpness, texture or detail): an instruction about it is added to your prompt separately.";
    /** How strong the pass of Limpar was, in the words of CLEANED. */
    private const CLEANED_STRENGTH = ['leve'=>'a light','media'=>'a medium','forte'=>'a strong'];

    /** CLEANED for a Limpar level (leve, media, forte); an unknown level reads as medium. */
    public static function cleanedNotice(string $level): string
    { return sprintf(self::CLEANED, self::CLEANED_STRENGTH[$level] ?? self::CLEANED_STRENGTH['media']); }

    /** The account of the API key has no credit left or reached its spending limit (insufficient_quota, billing_*). */
    public static function noCreditMessage(): string
    { return t('No credit at OpenAI: the account of this API key ran out of balance or reached its spending limit, and OpenAI refused the request without making the image. Top up at platform.openai.com, under Settings → Billing (or raise the spending limit), and try again.'); }
    public static function rateLimitMessage(): string
    { return t('OpenAI limited the number of requests per minute for this key (rate limit). It is not a lack of credit: wait a little and try again, or send fewer images at the same time.'); }
    public static function moderationMessage(): string
    { return t('OpenAI\'s moderation blocked this request. The app already sends the most permissive level the API accepts (moderation: low), which cannot be turned off. Rephrase the part that may have been misread and try again.'); }

    /**
     * Turns a provider error into a message for the owner. Only the error code, type and parameter name are read:
     * provider messages can quote the prompt, so they are neither shown nor logged.
     */
    public static function failure(int $status, string $body, string $path=''): HttpError
    {
        $error=json_decode($body,true)['error']??null; $error=is_array($error)?$error:[];
        $code=self::identifier($error['code']??null); $type=self::identifier($error['type']??null); $param=self::identifier($error['param']??null);
        error_log('Atelier: OpenAI HTTP '.$status.($path!==''?' on '.$path:'').($code?' code='.$code:'').($type?' type='.$type:'').($param?' param='.$param:''));
        if (self::isModeration($code,$type)) return new HttpError(502,self::moderationMessage());
        if (self::isNoCredit($code,$type)) return new HttpError(402,self::noCreditMessage(),'credit');
        if ($status===429 && preg_match('/rate_limit/i',(string)$code.' '.(string)$type)) return new HttpError(429,self::rateLimitMessage());
        $known=[401=>t('The API key was not accepted.'),403=>t('Your organization has no access to the model or needs verification.'),404=>t('The model is not available for this key.'),429=>t('Usage limit or API balance reached. Check your OpenAI account.')];
        if (isset($known[$status])) return new HttpError(502,$known[$status]);
        $reference=$code??$type;
        if ($status===400 && $param!==null) return new HttpError(502,t('OpenAI did not accept the option “{param}” with this model{detail}. Choose another value or another model. It was not a content refusal.', ['param'=>$param, 'detail'=>($reference?' ('.$reference.')':'')]));
        if ($status===400) return new HttpError(502,$reference
            ? t('OpenAI refused the request ({reference}). It was not a moderation block; check the model and the options.', ['reference'=>$reference])
            : t('OpenAI refused the request (HTTP 400) without giving a reason. Check the model, the prompt and the options.'));
        return new HttpError(502,t('OpenAI could not complete the request (HTTP {status}{detail}).', ['status'=>$status, 'detail'=>($reference?', '.$reference:'')]));
    }
    /** No credit left: OpenAI answers insufficient_quota (HTTP 429), or a billing_* code such as billing_hard_limit_reached. */
    private static function isNoCredit(?string $code, ?string $type): bool
    {
        foreach ([$code,$type] as $value) if ($value!==null && preg_match('/insufficient_quota|billing/i',$value)) return true;
        return false;
    }
    private static function isModeration(?string $code, ?string $type): bool
    {
        foreach ([$code,$type] as $value) if ($value!==null && preg_match('/moderation|safety|content_policy|content_filter/i',$value)) return true;
        return false;
    }
    /** Provider identifiers (codes, types, parameter names) are safe to show; anything else is dropped. */
    private static function identifier(mixed $value): ?string
    { return is_string($value) && preg_match('/\A[A-Za-z0-9_.\-\[\]]{1,80}\z/D',$value) ? $value : null; }

    private function signal(?string $phase): bool { return $this->monitor!==null && ($this->monitor)($phase)===true; }
    private function step(string $phase): void { if ($this->signal($phase)) throw new Cancelled(); }

    public static function options(array $input): array
    {
        $s=array_merge(['model'=>'gpt-image-2.6-flare','size'=>'1024x1024','quality'=>'high','complexity'=>'high','format'=>'png','background'=>'auto','compression'=>90,'n'=>1,'moderation'=>'low','thinking'=>'off','previews'=>2,'vary'=>false], $input);
        if (!is_string($s['model']) || self::meta($s['model'])===null) throw new HttpError(422,t('Model not supported.'));
        $m=self::meta($s['model']);
        foreach (['quality'=>($m['extended']?['auto','low','medium','high','xhigh','max']:['auto','low','medium','high']), 'format'=>['png','jpeg','webp'],'background'=>['auto','opaque','transparent'],'moderation'=>['low','auto'],'complexity'=>['auto','low','medium','high'],'thinking'=>['off','none','low','medium','high','xhigh','max']] as $field=>$allowed) {
            if (!in_array($s[$field],$allowed,true)) throw new HttpError(422,t('Invalid option: {field}.', ['field'=>$field]));
        }
        if (!is_bool($s['vary'])) throw new HttpError(422,t('Invalid option: vary.'));
        if ($s['background']==='transparent' && $s['format']==='jpeg') throw new HttpError(422,t('Transparency requires PNG or WebP.'));
        $s['n']=filter_var($s['n'],FILTER_VALIDATE_INT); $s['compression']=filter_var($s['compression'],FILTER_VALIDATE_INT); $s['previews']=filter_var($s['previews'],FILTER_VALIDATE_INT);
        if ($s['previews']===false || $s['previews']<0 || $s['previews']>3) throw new HttpError(422,t('Invalid number of previews.'));
        if ($s['n']===false || $s['n']<1 || $s['n']>10 || $s['compression']===false || $s['compression']<0 || $s['compression']>100) throw new HttpError(422,t('Invalid quantity or compression.'));
        if (!is_string($s['size'])) throw new HttpError(422,t('Invalid size.'));
        if ($s['size']!=='auto') {
            if (!preg_match('/\A([0-9]{3,4})x([0-9]{3,4})\z/D',$s['size'],$match)) throw new HttpError(422,t('Invalid size.'));
            $w=(int)$match[1]; $h=(int)$match[2];
            if (!$m['flex'] && !in_array($s['size'],['1024x1024','1024x1536','1536x1024'],true)) throw new HttpError(422,t('This model only accepts 1:1, 2:3, 3:2 or automatic.'));
            if ($w%16 || $h%16 || max($w,$h)>3840 || max($w,$h)/min($w,$h)>3 || $w*$h<655360 || $w*$h>8294400) throw new HttpError(422,t('Use multiples of 16, a ratio up to 3:1 and between 655,360 and 8,294,400 pixels (longest side 3,840).'));
        }
        return array_intersect_key($s,array_flip(['model','size','quality','complexity','format','background','compression','n','moderation','thinking','previews','vary']));
    }
    /**
     * Fallback directions for a varied batch, used when its planning fails: the first image keeps the prompt as written
     * and each of the others asks for a different take on what the prompt leaves open.
     * [instruction for the model, what the details panel shows]
     */
    private const VARIATIONS = [
        ['Choose a different composition and camera angle than the most obvious one.', 'composition and angle'],
        ['Choose a different lighting setup and color palette than the most obvious one.', 'lighting and palette'],
        ['Choose a different framing: wider if the obvious choice is close, closer if it is wide.', 'framing'],
        ['Choose a different pose or arrangement for the subjects and a different background.', 'pose and background'],
        ['Choose a different time of day, weather or mood.', 'time of day and weather'],
        ['Choose a different visual treatment, such as another medium or rendering approach, unless the request sets the style.', 'visual treatment'],
        ['Take a bolder, less conventional interpretation of the request.', 'bolder interpretation'],
        ['Choose a different composition and a different color palette than the most obvious ones.', 'composition and palette'],
        ['Make a simpler, more minimal version, with nothing beyond what the request asks for.', 'simpler version'],
    ];

    /**
     * Added to every image of a request for several. A request such as "this character in 6 different poses" reached
     * each image as written, and the model often answered with a grid of small poses inside one picture. Each image is
     * one of the set and shows one pose or scene, whole.
     */
    public static function singleImage(int $count): string
    {
        return "This is one image of a set of $count separate images that are generated one at a time. Make exactly one single picture "
            ."that fills the whole canvas: never a grid, collage, contact sheet, character sheet, comic panels, split screen, side-by-side "
            ."or before-and-after layout, and never several views, poses or copies of the subject in one picture. If the request mentions "
            ."several poses, scenes or variations, they are spread across the set: this image shows only one of them.";
    }

    /** @return array{direction:?string,label:string} */
    public static function fixedVariation(int $index): array
    {
        if ($index <= 1) return ['direction'=>null,'label'=>t('prompt as written')];
        [$instruction, $label] = self::VARIATIONS[($index - 2) % count(self::VARIATIONS)];
        return ['direction'=>"Direction for this image: $instruction Keep everything the request specifies exactly as specified; vary only what it leaves open.",'label'=>t($label)];
    }

    /**
     * The API has no seed and the same prompt tends to give nearly the same picture, so a varied batch asks the
     * planning model once for one distinct direction per image (for example a different scene or pose for each).
     * @param list<string> $references data URLs of the input images
     * @return array{variations:list<string>,usage:?array}
     */
    public function planVariations(string $prompt, array $references, int $count, bool $edit): array
    {
        $this->step('varying');
        $content=[['type'=>'input_text','text'=>"Request:\n".self::resolveMentions($prompt,count($references),$edit)]];
        foreach ($references as $ref) $content[]=['type'=>'input_image','image_url'=>$ref,'detail'=>'low'];
        $plan=$this->request('/responses',[
            'model'=>self::PLANNER,'store'=>false,'reasoning'=>['effort'=>'low'],
            'instructions'=>self::variationInstructions($count,$edit,(bool)$references),
            'input'=>[['role'=>'user','content'=>$content]],'max_output_tokens'=>8000,
            'text'=>['format'=>['type'=>'json_schema','name'=>'variations','strict'=>true,'schema'=>[
                'type'=>'object','properties'=>['variations'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['variations'],'additionalProperties'=>false]]],
        ]);
        $this->charge($plan['usage']??null,'variation','low');
        if (($plan['status']??'completed')!=='completed') throw new HttpError(502,t('The planning of the variations did not finish.'));
        $text='';
        foreach ($plan['output']??[] as $o) foreach ($o['content']??[] as $c) if (($c['type']??'')==='output_text') $text.=$c['text'];
        $json=json_decode($text,true);
        $variations=self::cleanVariations($json['variations']??null);
        if (!$variations) throw new HttpError(502,t('The planning of the variations returned no directions.'));
        return ['variations'=>array_slice($variations,0,$count),'usage'=>$plan['usage']??null];
    }

    /** The instructions of the planning model for the directions of a varied batch, for OpenAI's and Gemini's. */
    public static function variationInstructions(int $count, bool $edit, bool $references): string
    {
        $keep=$edit
            ? 'The images are edits of the attached image: keep the people, characters and objects in it recognisable, with the same identity, features and style, unless the request changes them.'
            : ($references ? 'Keep the people, characters and objects from the attached reference images recognisable.' : '');
        return "You plan a batch of $count images that are all made from the same request. Write $count directions, one per image, so that the images come out clearly different from one another. "
            ."If the request asks for several or different scenes, poses, outfits, expressions, angles, styles or similar, give each image its own distinct one. "
            ."Otherwise vary what the request leaves open, such as composition, camera angle, framing, setting, lighting and mood. "
            ."Never contradict or drop anything the request specifies. $keep "
            ."The images are generated one at a time and each one is a single, whole picture: when the request asks for several poses, scenes or variations, "
            ."spread them across the images, one each, and never put several poses, views or panels into one image (no grid, collage, contact sheet or character sheet). "
            ."Each direction describes exactly one picture in one or two concrete, visual sentences in the language of the request and does not repeat the request. Return exactly $count directions.";
    }

    /** Directions from the planning model's answer, cleaned. @return list<string> */
    public static function cleanVariations(mixed $list): array
    {
        $variations=[];
        foreach (is_array($list) ? $list : [] as $v) {
            $v=trim(preg_replace('/\s+/u',' ',preg_replace('/[\x00-\x1F\x7F]+/u',' ',is_string($v)?$v:'') ?? '') ?? '');
            if ($v!=='') $variations[]=mb_substr($v,0,600);
        }
        return $variations;
    }

    public static function imageInput(string $url, bool $mask=false): array
    {
        if (strlen($url)>20*1024*1024 || !preg_match('#\Adata:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)\z#D',$url,$m)) throw new HttpError(422,t('Use PNG, JPEG or WebP images of up to 14 MB.'));
        $bytes=base64_decode($m[2],true); $info=$bytes!==false?@getimagesizefromstring($bytes):false;
        if (!$info || $info['mime']!==$m[1] || $info[0]*$info[1]>40000000 || max($info[0],$info[1])>12000) throw new HttpError(422,t('Invalid image, or larger than 40 megapixels.'));
        if ($mask && ($m[1]!=='image/png' || strlen($bytes)>=4*1024*1024)) throw new HttpError(422,t('The mask must be a PNG smaller than 4 MB.'));
        return ['url'=>'data:'.$m[1].';base64,'.base64_encode($bytes),'width'=>$info[0],'height'=>$info[1]];
    }
    /**
     * "@2" in a prompt names the second attached image (in an edit, @1 is the image being edited). The model only gets
     * the images in order, so each mention becomes "[image 2]" and a line above the prompt says which attached image
     * that is. Numbers without an image, e-mail addresses and prompts without mentions are sent as written.
     */
    public static function resolveMentions(string $prompt, int $images, bool $edit=false): string
    {
        if ($images<1 || !str_contains($prompt,'@')) return $prompt;
        $used=[];
        $resolved=preg_replace_callback('/(?<![\p{L}\p{N}_@])@([0-9]{1,2})(?![\p{L}\p{N}_])/u',function(array $m) use ($images,&$used): string {
            $n=(int)$m[1];
            if ($n<1 || $n>$images) return $m[0];
            $used[$n]=true; return "[image $n]";
        },$prompt);
        if ($resolved===null || !$used) return $prompt;
        ksort($used);
        $names=[];
        foreach (array_keys($used) as $n) $names[]="[image $n] is the ".self::ordinal($n).' attached image'.($edit && $n===1 ? ' (the image being edited)' : '');
        $full='The attached images are numbered in the order they were sent: '.implode('; ',$names).".\n\n".$resolved;
        return mb_strlen($full)<=32000 ? $full : $resolved;
    }
    private static function ordinal(int $n): string
    { return $n.(in_array($n%100,[11,12,13],true) ? 'th' : (['th','st','nd','rd'][$n%10] ?? 'th')); }

    public function generate(array $job): array
    {
        $s=$job['options']; $planned=null; $plan=null; $skipped=null;
        $prompt=self::resolveMentions($job['prompt'],count($job['references']),($job['operation']??'generate')!=='generate');
        if ($s['thinking']!=='off') {
            $this->step('planning');
            // Planning only improves the prompt. When it refuses, stops early or fails, the image is made from the
            // prompt as written (the image API applies its own moderation either way) and the details say so.
            try { [$plan,$planned,$skipped]=$this->planPrompt($prompt,$job['references'],$s['thinking'],!empty($job['cleaned'])); }
            catch (HttpError $e) { $skipped='failed'; error_log('Atelier: prompt planning failed (HTTP '.$e->status.'), generating from the prompt as written'); }
            if ($planned!==null) $prompt=$planned;
        }
        $prompt=self::finishPrompt($job,$prompt);
        $body=['model'=>$s['model'],'prompt'=>$prompt,'size'=>$s['size'],'quality'=>$s['quality'],'output_format'=>$s['format'],'background'=>$s['background'],'n'=>$s['n'],'moderation'=>$s['moderation']];
        if ($s['format']!=='png') $body['output_compression']=$s['compression'];
        if ((self::meta($s['model'])['complexity'] ?? false) && $s['complexity']!=='auto') $body['complexity']=$s['complexity'];
        $endpoint='/images/generations';
        if ($job['references']) {
            $endpoint='/images/edits'; $body['images']=array_map(fn($url)=>['image_url'=>$url],$job['references']);
            if (!empty($job['mask'])) $body['mask']=['image_url'=>$job['mask']];
        }
        // Partial images only identify their order, not which of N outputs they belong to: stream single images only.
        $stream=$this->preview!==null && $s['n']===1 && ($s['previews']??0)>0;
        if ($stream) { $body['stream']=true; $body['partial_images']=$s['previews']; }
        $this->step('generating');
        $response=$this->request($endpoint,$body,$stream?$this->preview:null);
        if (empty($response['data'])) throw new HttpError(502,t('The API returned no images.'));
        return ['data'=>$response['data'],'planned_prompt'=>$planned,'planning_skipped'=>$skipped,'usage'=>$response['usage']??null,'planning_usage'=>$plan['usage']??null,'sent_prompt'=>$prompt];
    }

    /**
     * What the server adds to the prompt of a request, after its planning (the same for every image API): the direction
     * of its image in a varied batch, that it is one image of a set, the fidelity rules of an edit, and the Limpar notice
     * around it all.
     */
    public static function finishPrompt(array $job, string $prompt): string
    {
        $direction=$job['variation']['direction']??null;
        if (is_string($direction) && $direction!=='' && mb_strlen($prompt)+mb_strlen($direction)+2<=32000) $prompt.="\n\n".$direction;
        $of=(int)($job['variation']['count'] ?? $job['batch_of'] ?? 1);
        if ($of>1 && mb_strlen($prompt)+mb_strlen(self::singleImage($of))+2<=32000) $prompt.="\n\n".self::singleImage($of);
        if (in_array($job['operation']??'generate',['edit','expand','upscale'],true) && mb_strlen($prompt)+mb_strlen(self::FIDELITY)+2<=32000) $prompt.="\n\n".self::FIDELITY;
        // The image being changed was blurred on purpose (Limpar): the prompt opens by saying so and ends with a reminder.
        if (!empty($job['cleaned']) && $job['references']) {
            $notice=self::cleanedNotice(is_string($job['cleaned']) ? $job['cleaned'] : 'media');
            if (mb_strlen($prompt)+mb_strlen($notice)+mb_strlen(self::CLEANED_REMINDER)+4<=32000) $prompt=$notice."\n\n".$prompt."\n\n".self::CLEANED_REMINDER;
            elseif (mb_strlen($prompt)+mb_strlen($notice)+2<=32000) $prompt=$notice."\n\n".$prompt;
        }
        return $prompt;
    }

    /** The instructions of the planning model, for OpenAI's and Gemini's. */
    public const PLAN_INSTRUCTIONS='Prepare a precise image generation or image editing prompt from the user request and optional reference images. Preserve all requested subjects, details, constraints, language and text literally. Do not invent a new concept. For edits, state what changes and what stays. Output only the final visual prompt, no commentary.';

    /**
     * Asks the planning model to rewrite the request as a precise visual prompt.
     * @param list<string> $references data URLs of the input images
     * @param bool $cleaned the first image was blurred on purpose by Limpar: the planner is told (CLEANED_PLANNING)
     * @return array{0:array,1:?string,2:?string} the raw response, the planned prompt, and why there is none
     *                                            ('refused' or 'incomplete') when it could not be used
     */
    private function planPrompt(string $prompt, array $references, string $effort, bool $cleaned=false): array
    {
        $content=[['type'=>'input_text','text'=>$cleaned && $references ? $prompt."\n\n".self::CLEANED_PLANNING : $prompt]];
        foreach ($references as $ref) $content[]=['type'=>'input_image','image_url'=>$ref,'detail'=>'auto'];
        $plan=$this->request('/responses',[
            'model'=>self::PLANNER,'store'=>false,'reasoning'=>['effort'=>$effort],
            'instructions'=>self::PLAN_INSTRUCTIONS,
            'input'=>[['role'=>'user','content'=>$content]],'max_output_tokens'=>16000,
        ]);
        $this->charge($plan['usage']??null,'planning',$effort);
        if (($plan['status']??'completed')!=='completed') return [$plan,null,'incomplete'];
        $parts=[]; $refused=false;
        foreach ($plan['output']??[] as $o) foreach ($o['content']??[] as $c) {
            if (($c['type']??'')==='output_text') $parts[]=$c['text'];
            if (($c['type']??'')==='refusal') $refused=true;
        }
        $planned=trim(implode("\n",$parts));
        if ($planned==='' || mb_strlen($planned)>32000) return [$plan,null,$refused?'refused':'incomplete'];
        return [$plan,$planned,null];
    }

    /**
     * @param ?\Closure $onPartial receives (string $imageBytes, int $index) for streamed partial images.
     *                             When given, the body requests a server-sent event stream.
     */
    private function request(string $path, array $body, ?\Closure $onPartial=null): array
    {
        // Test transports get the same hooks as cURL: partial images and a periodic heartbeat that may cancel.
        if ($this->transport!==null) return ($this->transport)($path,$body,$onPartial,function(): void { if ($this->signal(null)) throw new Cancelled(); });
        $curl=curl_init('https://api.openai.com/v1'.$path);
        $raw=''; $buffer=''; $max=160*1024*1024; $final=null; $usage=null; $streamError=null;
        $consume=function(string $event) use (&$final,&$usage,&$streamError,$onPartial): void {
            $data='';
            foreach (preg_split('/\r?\n/',$event) as $line) if (str_starts_with($line,'data:')) $data.=ltrim(substr($line,5));
            if ($data==='' || $data==='[DONE]') return;
            $json=json_decode($data,true);
            if (!is_array($json)) return;
            $type=(string)($json['type']??'');
            if (str_ends_with($type,'.partial_image')) {
                $bytes=base64_decode((string)($json['b64_json']??''),true);
                if ($bytes!==false && $bytes!=='') { try { $onPartial($bytes,(int)($json['partial_image_index']??0)); } catch (\Throwable) { /* A preview must never break the paid request. */ } }
            } elseif (str_ends_with($type,'.completed')) {
                $final=(string)($json['b64_json']??''); $usage=$json['usage']??null;
            } elseif ($type==='error' || isset($json['error'])) {
                $streamError=self::identifier($json['error']['code']??$json['code']??null) ?? 'error';
            }
        };
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$this->key],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$raw,&$buffer,$max,$onPartial,$consume): int {
                if ($onPartial===null || curl_getinfo($ch,CURLINFO_RESPONSE_CODE)>=400) {
                    if (strlen($raw)+strlen($chunk)>$max) return 0;
                    $raw.=$chunk; return strlen($chunk);
                }
                $buffer.=$chunk;
                if (strlen($buffer)>$max) return 0;
                while (preg_match('/\r?\n\r?\n/',$buffer,$m,PREG_OFFSET_CAPTURE)) {
                    $consume(substr($buffer,0,$m[0][1])); $buffer=substr($buffer,$m[0][1]+strlen($m[0][0]));
                }
                return strlen($chunk);
            },
            // Called about once per second, also while the provider is still working: heartbeat and cancellation.
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>function(): int { return $this->signal(null) ? 1 : 0; }]);
        $ok=curl_exec($curl); $code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $errno=curl_errno($curl); $sent=(float)curl_getinfo($curl,CURLINFO_PRETRANSFER_TIME)>0; curl_close($curl);
        if ($errno===CURLE_ABORTED_BY_CALLBACK) throw new Cancelled();
        if ($ok===false || $errno!==0) {
            // Log only the transport error number: never request bodies, prompts or keys.
            error_log('Atelier: cURL '.$errno.' on '.$path.($sent?' after sending':' before sending'));
            // Nothing was transferred: the request never reached OpenAI, so it can't have been charged.
            if (!$sent) throw new HttpError(502,t('Could not connect to OpenAI (network error {errno} on the server). The request was not sent; it is safe to try again.', ['errno'=>$errno]));
            if ($errno===28) throw new HttpError(504,t('OpenAI did not answer within {minutes} min. The request may have been processed and charged; check before trying again. The limit is api_timeout in config.php.', ['minutes'=>intdiv($this->timeout,60)]));
            throw new HttpError(502,t('The connection to OpenAI was interrupted (error {errno}). The request may have been processed and charged; check before trying again.', ['errno'=>$errno]));
        }
        if ($code>=400) throw self::failure($code,$raw,$path);
        if ($onPartial!==null) {
            if (trim($buffer)!=='') $consume($buffer);
            if ($final===null || $final==='') {
                if ($streamError!==null) {
                    error_log('Atelier: OpenAI stream error '.$streamError.' on '.$path);
                    if (self::isModeration($streamError,null)) throw new HttpError(502,self::moderationMessage());
                    if (self::isNoCredit($streamError,null)) throw new HttpError(402,self::noCreditMessage(),'credit');
                }
                throw new HttpError(502,t('OpenAI ended the generation without delivering the final image.'));
            }
            return ['data'=>[['b64_json'=>$final]],'usage'=>$usage];
        }
        $json=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($json)) throw new HttpError(502,t('Invalid response from OpenAI.'));
        return $json;
    }
}
