<?php
declare(strict_types=1);
namespace Studio;

/**
 * The rest of the studio over MCP (Mcp uses this trait): Limpar, Recortar, Expandir, a painted area and Cobrir,
 * Fotorrealista and Melhorar, the library (albums, stacks, hidden items, the trash), the requests (cancel, repeat), the
 * GPU on Colab (its state, its LoRAs, turning it off) with its pictures and videos, and what the page does to a story as
 * a whole (duplicate, mark, delete). Each does what the page's own button does, with the same instructions and rules;
 * what the page does on a canvas is done here with GD (Picture).
 */
trait McpStudio
{
    /** The tools of this trait that wait for a request or a model, so their answer is streamed (Mcp::isLong). */
    private const STUDIO_LONG = ['clean_image','expand_image','photoreal_image','enhance_image','retry_request','improve_video_prompt'];
    private const CLEAN_NAMES = ['light'=>'leve','medium'=>'media','média'=>'media','strong'=>'forte'];
    private const POSITIONS = ['center','top','bottom','left','right','top-left','top-right','bottom-left','bottom-right'];

    /** Schema pieces shared by the image tools. */
    private static function fields(): array
    {
        $all = Images::models(); $models = array_keys($all);
        $gpu = array_keys(array_filter($all, fn($m) => ($m['provider'] ?? '') === 'gpu'));
        $gpuNote = $gpu ? ' '.implode(', ', $gpu).' is an open model on the account\'s own GPU (the Colab notebook of Settings › GPU), with no cost per picture, but it makes pictures only while the notebook is on: '
            .'until then the request waits in line (gpu_status says whether it is on; it can\'t be turned on from here, only in Colab). It takes loras and seed, and no transparent background.' : '';
        return [
            'size'=>['type'=>'string','description'=>'Aspect ratio ('.implode(', ', array_keys(OpenAI::SIZES)).'), an exact WIDTHxHEIGHT in multiples of 16 '
                .'(up to 3840 per side, at most 3:1, 0.65 to 8.3 megapixels, e.g. 2048x1152), or auto. On the GPU model, any WIDTHxHEIGHT from 64 to 12000 px a side, up to 40 megapixels and 5:1.'],
            'quality'=>['type'=>'string','enum'=>['low','medium','high','xhigh','max','auto'],'description'=>'Default high. xhigh and max only on GPT Image 2.5 and 2.6. On Nano Banana (gemini-*) it sets how much the model thinks first: low, medium or high (Nano Banana Pro: always high). On the GPU model, low, medium or high make about 4, 8 or 16 megapixels. Higher costs more.'],
            'model'=>['type'=>'string','enum'=>$models,'description'=>'Default '.$models[0].'. The gemini-* models (Nano Banana) need a Gemini key in the app\'s settings, read up to 14 images and have no transparent background.'.$gpuNote],
            'refs'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>15,'description'=>'Ids of library images to use as visual references (characters, products, style).'],
            'n'=>['type'=>'integer','minimum'=>1,'maximum'=>4,'description'=>'How many images, 1 to 4 (default 1). They run one after another.'],
            'vary'=>['type'=>'boolean','description'=>'With n > 1, first plan a distinct direction for each image (scene, pose, composition), since the API has no seed. One small extra planning call. Default true.'],
            'lines'=>['type'=>'array','items'=>['type'=>'string','maxLength'=>1000],'minItems'=>2,'maxItems'=>4,
                'description'=>'One line per image (2 to 4), each completing the prompt for that image, e.g. ["sitting on the beach","running","seen from behind"]. Replaces n and vary: no planning call.'],
            'title'=>['type'=>'string','maxLength'=>200,'description'=>'Short title saved with the image.'],
            'imageId'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Image id from this library.'],
            'planning'=>['type'=>'string','enum'=>['off','none','low','medium','high','xhigh','max'],'description'=>'Default off: the prompt goes to the image model as written. Otherwise a planning model first turns it into a detailed prompt, thinking this much (none: without thinking), as the app\'s "Planning" setting does; it costs a little more and takes longer.'],
            'complexity'=>['type'=>'string','enum'=>['auto','low','medium','high'],'description'=>'GPT Image 2.6 only: how much detail and how many elements the picture may hold. Default high.'],
            'loras'=>['type'=>'array','maxItems'=>4,'items'=>['type'=>'object','properties'=>[
                    'lora_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{16}$'],'strength'=>['type'=>'number','minimum'=>-2,'maximum'=>2,'description'=>'Default: the strength saved with it.']],'required'=>['lora_id']],
                'description'=>'GPU model only: LoRAs from list_loras (up to 4), each at a strength. Put each one\'s trigger words in the prompt.'],
            'seed'=>['type'=>'integer','minimum'=>0,'description'=>'GPU model only: a seed, to make the same picture again.'],
            'album'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Also put the result into this album (list_albums).'],
            'cleanFirst'=>['type'=>'string','enum'=>['off','leve','media','forte'],'description'=>'Limpar antes de enviar: clean the image of dots and fine lines, as clean_image does, right before it is sent (leve light, media medium, forte strong). '
                .'The image in the library stays as it is, and the model is told that the softness is on purpose and to bring the focus back. Only for the image APIs (for the GPU model, clean_image first) and not with an area. Default off.'],
        ];
    }

    /** @return list<array> the tools of this trait, after the image tools and before the stories' */
    private static function studioTools(): array
    {
        $f = self::fields();
        $write = ['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>true];
        $local = ['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>false];
        $same = ['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false];
        $read = ['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false];
        $gone = ['readOnlyHint'=>false,'destructiveHint'=>true,'idempotentHint'=>true,'openWorldHint'=>false];
        $ids = fn(int $max, string $what) => ['type'=>'array','items'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],'minItems'=>1,'maxItems'=>$max,'description'=>$what];
        $albumId = ['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'The album\'s id, from list_albums.'];
        $requestId = ['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'A request id, as the image tools and check_requests give it.'];
        $loraId = ['type'=>'string','pattern'=>'^[a-f0-9]{16}$','description'=>'The LoRA\'s id, from list_loras.'];
        $storyId = ['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'The story\'s id, from list_stories.'];
        $unit = ['type'=>'string','enum'=>['px','fraction'],'description'=>'px (default): pixels of the image as stored (get_image gives its width and height). fraction: 0 to 1 of its width and height.'];
        $position = ['type'=>'string','enum'=>self::POSITIONS];
        $gpuEngine = Gpu::engines()['h3'];
        $notes = ['type'=>'string','maxLength'=>32000,'description'=>'Optional: what the person asks for on top of the ready-made instruction (it has priority over it), e.g. "keep the freckles".'];
        $preset = fn(string $what) => ['type'=>'object','properties'=>['image_id'=>$f['imageId'],'notes'=>$notes,'model'=>$f['model'],'quality'=>$f['quality'],'complexity'=>$f['complexity'],
            'n'=>$f['n'],'loras'=>$f['loras'],'seed'=>$f['seed'],'clean_first'=>$f['cleanFirst'],'album_id'=>$f['album'],'title'=>$f['title']],'required'=>['image_id']];
        $videoInputs = [
            'reference_image_ids'=>['type'=>'array','items'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],'maxItems'=>9,'description'=>'Library pictures the video takes people, places or style from (they are <Picture 1>, <Picture 2>… in that order).'],
            'keyframes'=>['type'=>'array','maxItems'=>9,'items'=>['type'=>'object','properties'=>[
                    'image_id'=>$f['imageId'],'at'=>['description'=>'Seconds from the start (0 is the first frame), or "end" for the last frame.','oneOf'=>[['type'=>'number','minimum'=>0],['type'=>'string','enum'=>['start','end']]]]],'required'=>['image_id','at']],
                'description'=>'Library pictures pinned at moments of a new video: the first frame, the last, or any second between. They come after the reference pictures in the <Picture n> numbers, by time.'],
            'reference_video_ids'=>['type'=>'array','items'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],'maxItems'=>3,'description'=>'Library videos (2 s or longer) a new video takes motion, people or voices from (<Video 1>…).'],
            'sounds'=>['type'=>'array','maxItems'=>3,'items'=>['type'=>'object','properties'=>[
                    'data'=>['type'=>'string','description'=>'The sound as a data URL (data:audio/mpeg;base64,...): MP3, WAV, OGG, M4A, AAC or FLAC, up to '.$gpuEngine['limits']['audio_mb'].' MB.'],
                    'name'=>['type'=>'string','maxLength'=>120],'at'=>['type'=>'number','minimum'=>0,'description'=>'Seconds: place it as the soundtrack from this moment. Without it, it is a reference sound (a voice, a style).']],'required'=>['data']],
                'description'=>'Sounds for a new video (<Audio 1>…).'],
            'source_video_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Start from this library video instead of making a new one; operation says what to do with it.'],
            'operation'=>['type'=>'string','enum'=>$gpuEngine['ops'],'description'=>'With source_video_id: continue it, make a new video that follows its poses, depth or edges, or replace a part of it (target).'],
            'target'=>['type'=>'string','maxLength'=>120,'description'=>'With operation replace: what to replace in the video, e.g. "the red car".'],
            'join'=>['type'=>'boolean','description'=>'With operation continue: join the original video to the new part (default true).'],
            'keep_audio'=>['type'=>'boolean','description'=>'With pose, depth, edges or replace: keep the original sound (default true).'],
            'seconds'=>['type'=>'number','minimum'=>$gpuEngine['seconds'][0],'maximum'=>$gpuEngine['seconds'][1],'description'=>'Length, '.$gpuEngine['seconds'][0].' to '.$gpuEngine['seconds'][1].' seconds (default '.$gpuEngine['default_seconds'].').'],
            'aspect'=>['type'=>'string','enum'=>$gpuEngine['aspects'],'description'=>'Default '.$gpuEngine['aspects'][0].'. Pictures pinned at the start or the end give their own shape.'],
            'quality'=>['type'=>'string','enum'=>array_keys($gpuEngine['qualities']),'description'=>'Default '.$gpuEngine['default_quality'].': '.implode('; ', $gpuEngine['qualities']).'.'],
            'resolution'=>['type'=>'string','enum'=>array_keys($gpuEngine['resolutions']),'description'=>'Default '.$gpuEngine['default_resolution'].'.'],
            'seed'=>['type'=>'integer','minimum'=>0],
        ];
        return [
            ['name'=>'clean_image','title'=>'Clean image','annotations'=>$local,
                'description'=>'Limpar: remove the dots, specks and fine web of lines from a library image (noise of the kind image models copy from their inputs), the same filter as the app\'s Limpar, run on the server. '
                    .'Saved as a new version; the original stays. Costs nothing (no API). Takes from seconds to a few minutes for a large image. Clean an image before editing, upscaling or using it on the GPU model, or use clean_first on the image APIs.',
                'inputSchema'=>['type'=>'object','properties'=>['image_id'=>$f['imageId'],
                    'level'=>['type'=>'string','enum'=>['leve','media','forte'],'description'=>'leve (light), media (medium, default) or forte (strong): stronger removes more and softens more.'],
                    'album_id'=>$f['album']],'required'=>['image_id']]],
            ['name'=>'crop_image','title'=>'Crop image','annotations'=>$local,
                'description'=>'Recortar: keep only a part of a library image, saved as a new version (the original stays). Done on the server, without the API and at no cost. Give a rectangle, or an aspect ratio for the largest crop of that shape.',
                'inputSchema'=>['type'=>'object','properties'=>['image_id'=>$f['imageId'],
                    'x'=>['type'=>'number','minimum'=>0],'y'=>['type'=>'number','minimum'=>0],'width'=>['type'=>'number','exclusiveMinimum'=>0],'height'=>['type'=>'number','exclusiveMinimum'=>0],'unit'=>$unit,
                    'aspect'=>['type'=>'string','pattern'=>'^[0-9]{1,3}:[0-9]{1,3}$','description'=>'Instead of the rectangle: the largest crop of this shape, e.g. 4:5, 16:9.'],
                    'position'=>$position + ['description'=>'With aspect: where the crop sits in the image (default center).'],
                    'album_id'=>$f['album']],'required'=>['image_id']]],
            ['name'=>'expand_image','title'=>'Expand image','annotations'=>$write,
                'description'=>'Expandir (outpaint): enlarge the canvas of a library image and have the model fill the new areas, continuing the scene; the original area comes back as it is. A negative margin crops that side at the same time. '
                    .'Saved as a new version. Give margins in pixels, or an aspect ratio to reach (the image sits at position). With the image APIs the new size is rounded to multiples of 16 and must stay within 3840 px a side, 8.3 MP and 3:1; the GPU model goes to 12000 px, 40 MP and 5:1.',
                'inputSchema'=>['type'=>'object','properties'=>['image_id'=>$f['imageId'],
                    'left'=>['type'=>'integer'],'top'=>['type'=>'integer'],'right'=>['type'=>'integer'],'bottom'=>['type'=>'integer'],
                    'aspect'=>['type'=>'string','pattern'=>'^[0-9]{1,3}:[0-9]{1,3}$','description'=>'Instead of margins: the smallest canvas of this shape that holds the whole image, e.g. 16:9.'],
                    'position'=>$position + ['description'=>'With aspect: where the original image sits in the new canvas (default center).'],
                    'prompt'=>['type'=>'string','maxLength'=>32000,'description'=>'Optional: what should appear in the new areas.'],
                    'model'=>$f['model'],'quality'=>$f['quality'],'loras'=>$f['loras'],'seed'=>$f['seed'],'album_id'=>$f['album'],'title'=>$f['title']],'required'=>['image_id']]],
            ['name'=>'photoreal_image','title'=>'Photoreal','annotations'=>$write,
                'description'=>'Fotorrealista: photograph the scene of a library image again as a real, unedited camera photo, with the same people, pose, composition and light (the app\'s ready-made instruction, which also cleans noise and artifacts). Saved as a new version.',
                'inputSchema'=>$preset('photoreal')],
            ['name'=>'enhance_image','title'=>'Enhance','annotations'=>$write,
                'description'=>'Melhorar: recreate a library image as a clean, new render of the same picture, without its noise, dots, lines and compression damage, changing nothing of what it shows (the app\'s ready-made instruction). Saved as a new version.',
                'inputSchema'=>$preset('enhance')],
            ['name'=>'update_image','title'=>'Update image','annotations'=>$same,
                'description'=>'Change a library item (image or video): its title or saved prompt, whether it is a favorite, whether it is hidden from the library (Ocultas), or make it the version the library shows for its image (cover).',
                'inputSchema'=>['type'=>'object','properties'=>['image_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Image or video id.'],
                    'title'=>['type'=>'string','maxLength'=>160],'prompt'=>['type'=>'string','maxLength'=>32000],'favorite'=>['type'=>'boolean'],
                    'hidden'=>['type'=>'boolean'],'hide_all_versions'=>['type'=>'boolean','description'=>'With hidden: also every version of the image (default false).'],
                    'cover'=>['type'=>'boolean','description'=>'true: the library shows this version for its image (or for its stack); false: back to the newest.']],'required'=>['image_id']]],
            ['name'=>'trash_images','title'=>'Move to trash','annotations'=>$same,
                'description'=>'Move library items (images or videos) to the trash, or take them back out (restore). The trash keeps them until delete_images.',
                'inputSchema'=>['type'=>'object','properties'=>['image_ids'=>$ids(100, 'Image or video ids.'),'restore'=>['type'=>'boolean','description'=>'true takes them out of the trash.']],'required'=>['image_ids']]],
            ['name'=>'delete_images','title'=>'Delete for good','annotations'=>$gone,
                'description'=>'Delete items for good, with their files. Only items already in the trash are deleted (trash_images first); this can\'t be undone, so do it only when the person asks.',
                'inputSchema'=>['type'=>'object','properties'=>['image_ids'=>$ids(100, 'Ids of items in the trash.')],'required'=>['image_ids']]],
            ['name'=>'list_albums','title'=>'List albums','annotations'=>$read,
                'description'=>'The account\'s albums: id, name, how many items, cover, whether their items also show in the library, and the story, comic or visual novel an album belongs to.',
                'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'create_album','title'=>'Create album','annotations'=>$local,
                'description'=>'Create an album, optionally with items already in it.',
                'inputSchema'=>['type'=>'object','properties'=>['name'=>['type'=>'string','maxLength'=>120],'image_ids'=>$ids(500, 'Items to put in it.'),
                    'show_in_library'=>['type'=>'boolean','description'=>'false keeps its items out of the library: they show only in the album (default true).']],'required'=>['name']]],
            ['name'=>'update_album','title'=>'Update album','annotations'=>$same,
                'description'=>'Rename an album, choose whether its items show in the library, and add items to it or take them out (they stay in the library).',
                'inputSchema'=>['type'=>'object','properties'=>['album_id'=>$albumId,'name'=>['type'=>'string','maxLength'=>120],'show_in_library'=>['type'=>'boolean'],
                    'add_image_ids'=>$ids(500, 'Items to add.'),'remove_image_ids'=>$ids(500, 'Items to take out of the album.')],'required'=>['album_id']]],
            ['name'=>'delete_album','title'=>'Delete album','annotations'=>$gone,
                'description'=>'Delete an album. Its items stay in the library.',
                'inputSchema'=>['type'=>'object','properties'=>['album_id'=>$albumId],'required'=>['album_id']]],
            ['name'=>'stack_images','title'=>'Stack images','annotations'=>$local,
                'description'=>'Group items (each with its versions) into one stack, which the library shows as one tile.',
                'inputSchema'=>['type'=>'object','properties'=>['image_ids'=>$ids(500, 'At least two items.')],'required'=>['image_ids']]],
            ['name'=>'unstack_images','title'=>'Unstack images','annotations'=>$same,
                'description'=>'Take items out of their stacks, or undo their stacks altogether (whole).',
                'inputSchema'=>['type'=>'object','properties'=>['image_ids'=>$ids(500, 'Items in stacks.'),'whole'=>['type'=>'boolean']],'required'=>['image_ids']]],
            ['name'=>'reorder_images','title'=>'Reorder images','annotations'=>$same,
                'description'=>'Move items in the library\'s own order (list_images with sort custom): right before another item, or to the end.',
                'inputSchema'=>['type'=>'object','properties'=>['image_ids'=>$ids(500, 'Items, in the order they should have.'),'before_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Put them right before this item. Without it, at the end.']],'required'=>['image_ids']]],
            ['name'=>'cancel_request','title'=>'Cancel request','annotations'=>$same,
                'description'=>'Cancel a request: one waiting in line is removed; one running stops at its next step (if the provider already started it, it may still be charged).',
                'inputSchema'=>['type'=>'object','properties'=>['request_id'=>$requestId],'required'=>['request_id']]],
            ['name'=>'retry_request','title'=>'Repeat request','annotations'=>$write,
                'description'=>'Make a request again, with the same prompt, images and options (an image, a cleanup or a video), as the app\'s Repeat does. Waits for its images like generate_image.',
                'inputSchema'=>['type'=>'object','properties'=>['request_id'=>$requestId,'n'=>$f['n'],'album_id'=>$f['album']],'required'=>['request_id']]],
            ['name'=>'gpu_status','title'=>'GPU status','annotations'=>$read,
                'description'=>'Whether the account\'s GPU (the Colab notebook that makes the minimax-h3-image pictures and the videos) is on, what it is doing, what waits for it, when it turns itself off and how it saves the pictures (PNG or JPEG). It is turned on only in Colab, by the person.',
                'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'stop_gpu','title'=>'Turn GPU off','annotations'=>$same,
                'description'=>'Ask the GPU notebook to turn itself off (it ends what it is making first, and the Colab runtime is released, so it stops using compute units). Requests waiting for it stay in line.',
                'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'set_gpu_idle','title'=>'GPU idle time','annotations'=>$same,
                'description'=>'How many minutes without requests turn the GPU notebook off by itself.',
                'inputSchema'=>['type'=>'object','properties'=>['minutes'=>['type'=>'integer','enum'=>Gpu::IDLE_CHOICES]],'required'=>['minutes']]],
            ['name'=>'set_gpu_pictures','title'=>'GPU picture format','annotations'=>$same,
                'description'=>'How the GPU notebook saves the minimax-h3-image pictures before sending them, as Settings › GPU does: jpeg true saves a picture that would be a PNG as a JPEG at quality (default 85), '
                    .'several times smaller at 8K or more with no difference to see; false keeps PNG. It counts for every GPU picture from now on (new ones, edits, upscales, expansions). A request that asks for jpeg or webp itself keeps its own format.',
                'inputSchema'=>['type'=>'object','properties'=>['jpeg'=>['type'=>'boolean'],
                    'quality'=>['type'=>'integer','minimum'=>Gpu::JPEG_QUALITY[0],'maximum'=>Gpu::JPEG_QUALITY[1],'description'=>'JPEG quality. Default: the one set now ('.Gpu::DEFAULT_JPEG_QUALITY.' if never changed).']],'required'=>['jpeg']]],
            ['name'=>'list_loras','title'=>'List LoRAs','annotations'=>$read,
                'description'=>'The LoRAs saved for the GPU model (Settings › GPU): id, name, link, trigger words and default strength.',
                'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'save_lora','title'=>'Save LoRA','annotations'=>$local,
                'description'=>'Add a LoRA for the GPU model by its link (Hugging Face or Civitai), or change one (lora_id). The notebook downloads it the first time it is used.',
                'inputSchema'=>['type'=>'object','properties'=>['lora_id'=>$loraId + ['description'=>'To change a saved LoRA. Without it, a new one is added.'],
                    'url'=>['type'=>'string','maxLength'=>2000,'description'=>'https link to the .safetensors file, its Hugging Face page, or a Civitai page with ?modelVersionId='],
                    'name'=>['type'=>'string','maxLength'=>80],'trigger'=>['type'=>'string','maxLength'=>200,'description'=>'Words that call it in a prompt.'],
                    'strength'=>['type'=>'number','minimum'=>-2,'maximum'=>2,'description'=>'Default 1.']],'required'=>['url']]],
            ['name'=>'delete_lora','title'=>'Delete LoRA','annotations'=>$gone,
                'description'=>'Remove a LoRA from the list.',
                'inputSchema'=>['type'=>'object','properties'=>['lora_id'=>$loraId],'required'=>['lora_id']]],
            ['name'=>'generate_video','title'=>'Generate video','annotations'=>$write,
                'description'=>'Ask for a video with sound from the GPU notebook (MiniMax H3 on Colab), at no cost per video: a new one from a prompt (with pictures pinned at moments, reference pictures, videos and sounds), or from a library video (continue it, follow its motion, replace a part). '
                    .'It is made only while the notebook is on, which can\'t be done from here: the request waits in line until then. Returns at once with the request id; check_requests (with wait_seconds) shows when it is ready, and get_video gives the file. Refer to inputs as <Picture 1>, <Video 1>, <Audio 1>, or use improve_video_prompt.',
                'inputSchema'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string','maxLength'=>8000,'description'=>'What happens, shot by shot if useful: subjects, action, camera, sound and dialogue.']]
                    + $videoInputs + ['title'=>$f['title'],'album_id'=>$f['album']],'required'=>['prompt']]],
            ['name'=>'improve_video_prompt','title'=>'Improve video prompt','annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>true],
                'description'=>'The video window\'s prompt helper: Claude, GPT or Gemini (with the account\'s key; a few cents) rewrites an idea into a prompt written for MiniMax H3, knowing the inputs. Returns the prompt, to pass to generate_video with the same inputs.',
                'inputSchema'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string','maxLength'=>8000,'description'=>'The idea.'],
                    'provider'=>['type'=>'string','enum'=>['claude','gpt','gemini'],'description'=>'Default: the first with a key, in that order.']] + $videoInputs,'required'=>['prompt']]],
            ['name'=>'get_video','title'=>'Get video','annotations'=>$read,
                'description'=>'A library video: length, size, how it was made, a still of it and a download link for the file (valid for 24 hours).',
                'inputSchema'=>['type'=>'object','properties'=>['video_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$']],'required'=>['video_id']]],
            ['name'=>'duplicate_story','title'=>'Duplicate story','annotations'=>$local,
                'description'=>'Copy a story (chapters, bible, plan, sheets, comic or visual novel) into a new one that can change on its own. Its pictures are shared.',
                'inputSchema'=>['type'=>'object','properties'=>['story_id'=>$storyId,'title'=>['type'=>'string','maxLength'=>200,'description'=>'Title of the copy (default: the same with "(copy)").']],'required'=>['story_id']]],
            ['name'=>'favorite_story','title'=>'Favorite story','annotations'=>$same,
                'description'=>'Mark a story as a favorite, or not.',
                'inputSchema'=>['type'=>'object','properties'=>['story_id'=>$storyId,'favorite'=>['type'=>'boolean']],'required'=>['story_id','favorite']]],
            ['name'=>'delete_story','title'=>'Delete story','annotations'=>$gone,
                'description'=>'Delete a story with its chapters and writer conversation, for good (its pictures stay in their albums). Only when the person asks: confirm_title must be the story\'s title exactly.',
                'inputSchema'=>['type'=>'object','properties'=>['story_id'=>$storyId,'confirm_title'=>['type'=>'string','maxLength'=>200]],'required'=>['story_id','confirm_title']]],
        ];
    }

    /** One of this trait's tools, or null when the name is not one of them. */
    private function studioCall(string $name, array $args, ?\Closure $keepalive): ?array
    {
        return match ($name) {
            'clean_image' => $this->cleanImage($args, $keepalive),
            'crop_image' => $this->cropImage($args),
            'expand_image' => $this->expandImage($args, $keepalive),
            'photoreal_image' => $this->preset('photoreal', $args, $keepalive),
            'enhance_image' => $this->preset('enhance', $args, $keepalive),
            'update_image' => $this->updateImage($args),
            'trash_images' => $this->trashImages($args),
            'delete_images' => $this->deleteImages($args),
            'list_albums' => self::answer(['albums'=>array_map(fn($a) => ['id'=>$a['id'],'name'=>$a['name'],'count'=>$a['count'],'cover_id'=>$a['cover'],'created'=>gmdate('c', $a['created']),
                'show_in_library'=>$a['library'],'made_by'=>$a['kind'],'story_id'=>$a['story']], $this->vault->albums($this->key))]),
            'create_album' => $this->createAlbum($args),
            'update_album' => $this->updateAlbum($args),
            'delete_album' => $this->deleteAlbum($args),
            'stack_images' => self::answer($this->vault->stack(self::ids($args), $this->key)),
            'unstack_images' => self::answer(['count'=>$this->vault->unstack(self::ids($args), ($args['whole'] ?? false) === true, $this->key)]),
            'reorder_images' => $this->reorderImages($args),
            'cancel_request' => $this->requestAnswer($this->jobs->cancel(self::imageId($args, 'request_id'), $this->key)),
            'retry_request' => $this->retryRequest($args, $keepalive),
            'gpu_status' => self::answer($this->gpuState()),
            'stop_gpu' => $this->stopGpu(),
            'set_gpu_idle' => $this->setGpuIdle($args),
            'set_gpu_pictures' => $this->setGpuPictures($args),
            'list_loras' => self::answer(['loras'=>self::loraList($this->gpu()->loras($this->key))]),
            'save_lora' => $this->saveLora($args),
            'delete_lora' => self::answer(['loras'=>self::loraList($this->gpu()->deleteLora(self::loraId($args['lora_id'] ?? null), $this->key))]),
            'generate_video' => $this->generateVideo($args),
            'improve_video_prompt' => $this->improveVideoPrompt($args),
            'get_video' => $this->getVideo($args),
            'duplicate_story' => $this->duplicateStory($args),
            'favorite_story' => $this->favoriteStory($args),
            'delete_story' => $this->deleteStory($args),
            default => null,
        };
    }

    private static function answer(array $data): array { return ['content'=>[['type'=>'text','text'=>self::json($data)]]]; }

    private ?Gpu $gpuWorker = null;
    private function gpu(): Gpu { return $this->gpuWorker ??= new Gpu($this->vault, $this->config); }
    /** Whether the GPU notebook is on now. */
    private function gpuOn(): bool { return $this->gpu()->status()['worker'] !== null; }

    // -----------------------------------------------------------------------------------------------------------------
    // Arguments
    // -----------------------------------------------------------------------------------------------------------------

    /** @return list<string> the ids of image_ids (or another list), each a library id. */
    private static function ids(array $args, string $name = 'image_ids', int $max = 500): array
    {
        $ids = $args[$name] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > $max) throw new HttpError(422, "$name must be a list of 1 to $max ids.");
        foreach ($ids as $id) if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new HttpError(422, "$name must hold ids of 32 hexadecimal characters.");
        return array_values(array_unique($ids));
    }

    /** The album an image or a request goes into (album_id), which must exist; null when none is given. */
    private function albumId(array $args, string $name = 'album_id'): ?string
    {
        if (!isset($args[$name])) return null;
        $id = self::imageId($args, $name);
        if (!$this->vault->hasAlbum($id)) throw new HttpError(404, 'There is no album with that id. list_albums shows them.');
        return $id;
    }

    private static function cleanLevel(mixed $value, bool $off = false): string
    {
        if (is_string($value)) { $value = mb_strtolower(trim($value)); $value = self::CLEAN_NAMES[$value] ?? $value; }
        if ($off && $value === 'off') return 'off';
        if (!is_string($value) || !isset(Vault::CLEAN_LEVELS[$value])) throw new HttpError(422, 'The cleaning level must be leve (light), media (medium) or forte (strong)'.($off ? ', or off' : '').'.');
        return $value;
    }

    /** clean_first, as Jobs::create takes it (nothing when off). */
    private static function cleanFirst(array $args): array
    {
        if (!isset($args['clean_first'])) return [];
        $level = self::cleanLevel($args['clean_first'], true);
        return $level === 'off' ? [] : ['clean_first'=>$level];
    }

    private static function ratio(mixed $value): array
    {
        if (!is_string($value) || !preg_match('/\A\s*([0-9]{1,3})\s*[:x×\/]\s*([0-9]{1,3})\s*\z/u', $value, $m) || (int)$m[1] < 1 || (int)$m[2] < 1)
            throw new HttpError(422, 'aspect must be a ratio such as 16:9 or 4:5.');
        return [(int)$m[1], (int)$m[2]];
    }

    /** Where something smaller sits in something larger: the offset along each side for $room pixels to spare. */
    private static function place(int $roomX, int $roomY, mixed $position): array
    {
        $position = is_string($position) ? strtolower(trim($position)) : 'center';
        if (!in_array($position, self::POSITIONS, true)) throw new HttpError(422, 'position must be one of '.implode(', ', self::POSITIONS).'.');
        $x = str_contains($position, 'left') ? 0 : (str_contains($position, 'right') ? $roomX : intdiv($roomX, 2));
        $y = str_contains($position, 'top') ? 0 : (str_contains($position, 'bottom') ? $roomY : intdiv($roomY, 2));
        return [$x, $y];
    }

    /**
     * A rectangle of the arguments (x, y, width, height, in unit) in the image's pixels, kept inside the image.
     * @return array{x:int,y:int,width:int,height:int}
     */
    private static function rect(array $a, int $iw, int $ih, string $unit, string $what): array
    {
        $values = [];
        foreach (['x','y','width','height'] as $name) {
            $v = $a[$name] ?? null;
            if (!is_int($v) && !is_float($v)) throw new HttpError(422, "$what needs x, y, width and height (numbers).");
            $values[$name] = (float)$v;
        }
        if ($unit === 'fraction') {
            foreach (['x','y','width','height'] as $name) if ($values[$name] < 0 || $values[$name] > 1) throw new HttpError(422, "With unit fraction, $what's x, y, width and height go from 0 to 1.");
            $values = ['x'=>$values['x'] * $iw, 'y'=>$values['y'] * $ih, 'width'=>$values['width'] * $iw, 'height'=>$values['height'] * $ih];
        }
        $left = max(0, (int)round($values['x'])); $top = max(0, (int)round($values['y']));
        $right = min($iw, (int)round($values['x'] + $values['width'])); $bottom = min($ih, (int)round($values['y'] + $values['height']));
        if ($right - $left < 1 || $bottom - $top < 1) throw new HttpError(422, "$what is outside the image, which is {$iw} × {$ih} px.");
        return ['x'=>$left, 'y'=>$top, 'width'=>$right - $left, 'height'=>$bottom - $top];
    }

    private static function unit(array $args): string
    {
        $unit = $args['unit'] ?? 'px';
        if (!in_array($unit, ['px','fraction'], true)) throw new HttpError(422, 'unit must be px or fraction.');
        return $unit;
    }

    /** The image as the request starts from it: its own options without what only its own model takes. */
    private static function sourceOptions(array $source): array
    {
        $base = is_array($source['options'] ?? null) ? $source['options'] : [];
        unset($base['seed'], $base['loras'], $base['engine']);
        $base['format'] ??= self::formatOf((string)$source['mime']);
        return $base;
    }

    /** The model a request on an image uses: the one asked for, else the image's own (an image API's), else the default. */
    private static function editModel(array $args, array $base): string
    {
        if (is_string($args['model'] ?? null)) return $args['model'];
        $model = (string)($base['model'] ?? '');
        return Images::model($model) !== null && Images::provider($model) !== 'gpu' ? $model : 'gpt-image-2.6-flare';
    }

    /** An edit's options, in the source's proportions (the GPU's model makes the size of the image it is given). */
    private function editOptions(array $source, array $args, array $extra = []): array
    {
        $base = self::sourceOptions($source);
        $model = self::editModel($args, $base);
        if (!isset($args['size'])) $base['size'] = Images::provider($model) === 'gpu' ? 'auto' : self::editSize((int)$source['width'], (int)$source['height'], Images::model($model)['flex'] ?? true);
        return $this->options(array_merge($args, $extra), $base);
    }

    /** loras of the arguments as GpuImages takes them; a strength left out is the one saved with the LoRA. */
    private function loras(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new HttpError(422, 'loras must be a list of {lora_id, strength}.');
        $saved = array_column($this->gpu()->loras($this->key), null, 'id'); $out = [];
        foreach ($value as $l) {
            $id = is_string($l) ? $l : (is_array($l) ? ($l['lora_id'] ?? $l['id'] ?? null) : null);
            $id = self::loraId($id);
            if (!isset($saved[$id])) throw new HttpError(404, 'There is no LoRA with the id '.$id.'. list_loras shows them.');
            $strength = is_array($l) && array_key_exists('strength', $l) ? $l['strength'] : ($saved[$id]['strength'] ?? 1);
            $out[] = ['id'=>$id, 'strength'=>$strength];
        }
        return $out;
    }

    private static function loraId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{16}\z/D', $id)) throw new HttpError(422, 'lora_id must be a LoRA id (16 hexadecimal characters), from list_loras.');
        return $id;
    }

    private static function loraList(array $loras): array
    { return array_map(fn($l) => ['id'=>$l['id'],'name'=>$l['name'] ?? '','url'=>$l['url'] ?? '','trigger'=>$l['trigger'] ?? '','strength'=>$l['strength'] ?? 1,'model'=>'minimax-h3-image'], $loras); }

    // -----------------------------------------------------------------------------------------------------------------
    // Limpar, Recortar, Expandir, Fotorrealista and Melhorar
    // -----------------------------------------------------------------------------------------------------------------

    private function cleanImage(array $args, ?\Closure $keepalive): array
    {
        $source = $this->vault->still(self::imageId($args), $this->key);
        $created = $this->jobs->createClean(bin2hex(random_bytes(16)), $source['id'], self::cleanLevel($args['level'] ?? 'media'), $this->key, $this->albumId($args));
        return $this->finish($created, $keepalive);
    }

    private function cropImage(array $args): array
    {
        $source = $this->vault->still(self::imageId($args), $this->key);
        [$iw, $ih] = [(int)$source['width'], (int)$source['height']];
        if (isset($args['aspect'])) {
            // The largest frame of that shape inside the image (ratioFrame in assets/outpaint.js), placed at position.
            [$rw, $rh] = self::ratio($args['aspect']);
            $w = $iw * $rh > $ih * $rw ? max(16, (int)round($ih * $rw / $rh)) : $iw; $h = $w === $iw ? max(16, (int)round($iw * $rh / $rw)) : $ih;
            [$x, $y] = self::place($iw - $w, $ih - $h, $args['position'] ?? 'center');
            $rect = ['x'=>$x, 'y'=>$y, 'width'=>min($w, $iw), 'height'=>min($h, $ih)];
        } else $rect = self::rect($args, $iw, $ih, self::unit($args), 'The crop');
        return $this->saveCrop($source, $rect, $this->albumId($args));
    }

    /** The part of the image in $rect, saved as its version (as the page's crop). */
    private function saveCrop(array $source, array $rect, ?string $album, ?string $note = null): array
    {
        [$iw, $ih] = [(int)$source['width'], (int)$source['height']];
        if ($rect['width'] < 16 || $rect['height'] < 16) throw new HttpError(422, 'A crop must be at least 16 × 16 px.');
        if ($rect['width'] >= $iw && $rect['height'] >= $ih) throw new HttpError(422, "That crop is the whole image ({$iw} × {$ih} px): nothing to cut.");
        $image = Picture::open($this->vault->bytes($source['id'], 'full', $this->key));
        if (imagesx($image) !== $iw || imagesy($image) !== $ih) { imagedestroy($image); throw new HttpError(422, t('Could not read the image.')); }
        $part = Picture::crop($image, $rect['x'], $rect['y'], $rect['width'], $rect['height']); imagedestroy($image);
        $file = Picture::encode($part, t('The crop got too large to be saved.')); imagedestroy($part);
        $item = $this->vault->croppedImage($file['bytes'], $source['id'], $this->key, $album, true);
        $out = ['image'=>$this->describe($item), 'crop'=>$rect] + ($note !== null ? ['note'=>$note] : []);
        return ['content'=>[['type'=>'text','text'=>self::json($out)], $this->preview($item)]];
    }

    /** What a frame does to an image (frameKind in assets/outpaint.js): same, crop, expand or both. */
    private static function frameKind(int $width, int $height, int $x, int $y, int $iw, int $ih): string
    {
        if ($x <= 0 && $y <= 0 && $x + $iw >= $width && $y + $ih >= $height) return $width === $iw && $height === $ih ? 'same' : 'crop';
        return $x >= 0 && $y >= 0 && $x + $iw <= $width && $y + $ih <= $height ? 'expand' : 'both';
    }

    /** @return array{x:int,y:int,width:int,height:int} the part of the image a frame keeps (keptArea in assets/outpaint.js) */
    private static function keptArea(int $width, int $height, int $x, int $y, int $iw, int $ih): array
    {
        $left = max(0, -$x); $top = max(0, -$y);
        return ['x'=>$left, 'y'=>$top, 'width'=>max(0, min($iw, $width - $x) - $left), 'height'=>max(0, min($ih, $height - $y) - $top)];
    }

    /** Why a frame with new areas can't be generated, or '' (validateFrame in assets/outpaint.js). */
    private static function frameError(int $width, int $height, int $x, int $y, int $iw, int $ih, bool $gpu): string
    {
        $kept = self::keptArea($width, $height, $x, $y, $iw, $ih);
        if ($kept['width'] < 16 || $kept['height'] < 16) return t('The frame must include part of the image.');
        if ($width % 16 || $height % 16) return t('With a new area, use dimensions in multiples of 16.');
        $ratio = max($width, $height) / min($width, $height);
        if ($gpu) return max($width, $height) > GpuImages::MAX_SIDE || $width * $height > GpuImages::MAX_PIXELS || $ratio > 5
            ? t('With a new area, the canvas must stay within 12,000 px per side, 40 MP and an aspect ratio up to 5:1.') : '';
        if (max($width, $height) > self::MAX_SIDE || $width * $height > self::MAX_AREA || $ratio > 3) return t('With a new area, the canvas must stay within 3,840 px per side, 8.29 MP and an aspect ratio up to 3:1.');
        if ($width * $height < self::MIN_AREA) return t('With a new area, the canvas must be at least 0.66 MP (for example, 1024 × 640). Enlarge the frame or crop without leaving the image.');
        return '';
    }

    /**
     * The smallest frame of a proportion (rw:rh) that holds the whole image, in multiples of 16 (aroundFrame in
     * assets/outpaint.js): when the image already has that shape, a quarter larger all around.
     * @return array{0:int,1:int}
     */
    private static function aroundFrame(int $iw, int $ih, int $rw, int $rh): array
    {
        $snap = static fn(float $n): int => (int)(floor($n / 16 + 0.5) * 16); $ceil = static fn(float $n): int => (int)(ceil($n / 16) * 16);
        $width = max($iw, $ih * $rw / $rh); $height = $width * $rh / $rw;
        if ($width - $iw < 1 && $height - $ih < 1) { $width *= 1.25; $height *= 1.25; }
        $w = max($ceil($iw), $snap($width)); $h = max($ceil($ih), $snap($w * $rh / $rw));
        // A side raised to hold the image takes the other with it.
        $w = max($ceil($iw), $snap($h * $rw / $rh));
        return [$w, $h];
    }

    /** One side of a frame with new areas brought to a multiple of 16: a side that grows grows more; else one more pixel row is cut. */
    private static function settle(int $before, int $after, int $size): array
    {
        $length = $size + $before + $after; $rest = $length % 16;
        if ($rest === 0) return [$before, $after];
        if ($after > 0) return [$before, $after + 16 - $rest];
        if ($before > 0) return [$before + 16 - $rest, $after];
        return [$before, $after - $rest];
    }

    private function expandImage(array $args, ?\Closure $keepalive): array
    {
        $source = $this->vault->still(self::imageId($args), $this->key);
        [$iw, $ih] = [(int)$source['width'], (int)$source['height']];
        $base = self::sourceOptions($source);
        $model = self::editModel($args, $base); $gpu = Images::provider($model) === 'gpu';
        if (!(Images::model($model)['flex'] ?? false)) throw new HttpError(422, 'That model cannot expand: it makes only its own few sizes. Choose another model.');
        $album = $this->albumId($args);
        if (isset($args['aspect'])) {
            // The smallest frame of that shape around the whole image, as the page's proportions make it (aroundFrame in
            // assets/outpaint.js), grown to the least the image APIs make when it is smaller.
            [$rw, $rh] = self::ratio($args['aspect']);
            [$w, $h] = self::aroundFrame($iw, $ih, $rw, $rh);
            if (!$gpu && $w * $h < self::MIN_AREA) { $grow = sqrt(self::MIN_AREA / ($w * $h)); $w = (int)(ceil($w * $grow / 16) * 16); $h = (int)(ceil($h * $grow / 16) * 16); }
            [$x, $y] = self::place($w - $iw, $h - $ih, $args['position'] ?? 'center');
        } else {
            $m = [];
            foreach (['left','top','right','bottom'] as $side) {
                $v = $args[$side] ?? 0;
                if (!is_int($v) || abs($v) > 20000) throw new HttpError(422, "$side must be a whole number of pixels (negative crops that side).");
                $m[$side] = $v;
            }
            if (self::frameKind($iw + $m['left'] + $m['right'], $ih + $m['top'] + $m['bottom'], $m['left'], $m['top'], $iw, $ih) !== 'crop') {
                [$m['left'], $m['right']] = self::settle($m['left'], $m['right'], $iw);
                [$m['top'], $m['bottom']] = self::settle($m['top'], $m['bottom'], $ih);
            }
            [$w, $h, $x, $y] = [$iw + $m['left'] + $m['right'], $ih + $m['top'] + $m['bottom'], $m['left'], $m['top']];
            if ($w < 16 || $h < 16) throw new HttpError(422, t('Use whole dimensions, from 16 px up.'));
        }
        $kind = self::frameKind($w, $h, $x, $y, $iw, $ih);
        if ($kind === 'same') throw new HttpError(422, 'Give left, top, right or bottom (pixels to add; negative to cut), or an aspect ratio the image doesn\'t have yet.');
        $kept = self::keptArea($w, $h, $x, $y, $iw, $ih);
        if ($kind === 'crop') return $this->saveCrop($source, $kept, $album, 'Every margin cuts the image, so it was only cropped (no API, no cost).');
        if (($error = self::frameError($w, $h, $x, $y, $iw, $ih, $gpu)) !== '') throw new HttpError(422, $error." (The canvas would be {$w} × {$h} px.)");
        $image = Picture::open($this->vault->bytes($source['id'], 'full', $this->key));
        [$picture, $mask] = Picture::frame($image, $w, $h, $x, $y, $kept); imagedestroy($image);
        $file = Picture::encode($picture, t('The prepared image got too large. Make the frame smaller.'), 14 * 1024 * 1024, false); imagedestroy($picture);
        $maskUrl = Picture::maskUrl($mask); imagedestroy($mask);
        $instructions = (string)self::text($args, 'prompt', 32000);
        $options = $this->options(array_merge(array_intersect_key($args, array_flip(['model','quality','loras','seed'])), ['size'=>"{$w}x{$h}", 'vary'=>false, 'planning'=>'off']), $base);
        $label = ($kind === 'both' ? t('Crop and expansion to {width} × {height}', ['width'=>$w, 'height'=>$h]) : t('Expansion to {width} × {height}', ['width'=>$w, 'height'=>$h]))
            .($instructions !== '' ? ": $instructions" : '');
        $created = $this->jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>Presets::EXPAND.($instructions !== '' ? ' Instructions for the new areas: '.$instructions : ''),
            'typed'=>'','label'=>self::text($args, 'title', 200) ?? $label,'options'=>$options,'references'=>[['data'=>Picture::dataUrl($file), 'source_id'=>$source['id']]],
            'mask'=>$maskUrl,'parent_id'=>$source['id'],'operation'=>'expand'] + ($album !== null ? ['album'=>$album] : []), $this->key);
        return $this->finish($created, $keepalive);
    }

    /** Fotorrealista or Melhorar: the app's ready-made instruction, with what the client adds as notes that come first. */
    private function preset(string $name, array $args, ?\Closure $keepalive): array
    {
        $source = $this->vault->still(self::imageId($args), $this->key);
        [$instruction, $label] = Presets::EDITS[$name];
        $notes = (string)self::text($args, 'notes', 32000);
        // The prompt is complete as it is: no planning step rewrites it, and every image of the request follows it.
        $options = $this->editOptions($source, $args, ['planning'=>'off', 'vary'=>false]);
        $album = $this->albumId($args);
        $created = $this->jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>Presets::withNotes($instruction, $notes),'typed'=>'',
            'label'=>self::text($args, 'title', 200) ?? t($label).($notes !== '' ? ": $notes" : ''),'options'=>$options,'references'=>[['id'=>$source['id']]],
            'parent_id'=>$source['id'],'operation'=>'edit'] + self::cleanFirst($args) + ($album !== null ? ['album'=>$album] : []), $this->key);
        return $this->finish($created, $keepalive);
    }

    /**
     * The area of an edit (areas or mask), as the page's brush marks it: the mask, and the shapes when they were given.
     * @return ?array{mask:\GdImage,shapes:?list<array>}
     */
    private function area(array $args, array $source): ?array
    {
        $hasAreas = isset($args['areas']); $hasMask = isset($args['mask']);
        if (!$hasAreas && !$hasMask) return null;
        if ($hasAreas && $hasMask) throw new HttpError(422, 'Give areas or mask, not both.');
        [$iw, $ih] = [(int)$source['width'], (int)$source['height']];
        if ($hasMask) {
            $data = $args['mask'];
            if (!is_string($data) || $data === '') throw new HttpError(422, 'mask must be a PNG or WebP as base64 or a data URL.');
            $bytes = str_starts_with($data, 'data:') ? (preg_match('#\Adata:image/[a-z+.-]+;base64,(.+)\z#s', $data, $m) ? base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true) : false)
                : base64_decode(preg_replace('/\s+/', '', $data) ?? '', true);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 20 * 1024 * 1024) throw new HttpError(422, 'mask must be a PNG or WebP as base64 or a data URL.');
            $mask = Picture::maskFrom($bytes, $iw, $ih);
            if ($mask === null) throw new HttpError(422, 'The mask marks nothing: make the area to change transparent (or white on black).');
            return ['mask'=>$mask, 'shapes'=>null];
        }
        $areas = $args['areas'];
        if (!is_array($areas) || !array_is_list($areas) || !$areas || count($areas) > 20) throw new HttpError(422, 'areas must be a list of 1 to 20 rectangles or ellipses.');
        $unit = self::unit($args); $shapes = [];
        foreach ($areas as $a) {
            if (!is_array($a)) throw new HttpError(422, 'Each area needs x, y, width and height.');
            $shape = $a['shape'] ?? 'rect';
            if (!in_array($shape, ['rect','ellipse'], true)) throw new HttpError(422, 'An area\'s shape must be rect or ellipse.');
            $shapes[] = self::rect($a, $iw, $ih, $unit, 'An area') + ['shape'=>$shape];
        }
        return ['mask'=>Picture::shapesMask($iw, $ih, $shapes), 'shapes'=>$shapes];
    }

    /** The image with the area erased (Cobrir), as a reference that came from it. */
    private function covered(array $source, array $area): array
    {
        $image = Picture::open($this->vault->bytes($source['id'], 'full', $this->key));
        if ($area['shapes'] !== null) Picture::clear($image, $area['shapes']); else Picture::erase($image, $area['mask']);
        $file = Picture::encode($image, t('The image is too large to be sent with a covered area.'), 14 * 1024 * 1024, false); imagedestroy($image);
        return ['data'=>Picture::dataUrl($file), 'source_id'=>$source['id']];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The library
    // -----------------------------------------------------------------------------------------------------------------

    private function updateImage(array $args): array
    {
        $id = self::imageId($args);
        $item = $this->vault->image($id, $this->key);
        $done = [];
        if (isset($args['title']) || isset($args['prompt'])) {
            $title = isset($args['title']) ? (is_string($args['title']) ? trim($args['title']) : throw new HttpError(422, 'title must be a string.')) : (string)($item['title'] ?? '');
            $prompt = isset($args['prompt']) ? (is_string($args['prompt']) ? trim($args['prompt']) : throw new HttpError(422, 'prompt must be a string.')) : (string)($item['prompt'] ?? '');
            if (mb_strlen($title) > 160 || mb_strlen($prompt) > 32000) throw new HttpError(422, t('Title or prompt too long.'));
            $this->vault->editMeta($id, $title, $prompt, $this->key); $done[] = 'text';
        }
        if (isset($args['favorite'])) { $this->vault->bulk($args['favorite'] === true ? 'favorite' : 'unfavorite', [$id]); $done[] = 'favorite'; }
        if (isset($args['hidden'])) { $this->vault->hide([$id], $args['hidden'] === true, ($args['hide_all_versions'] ?? false) === true, $this->key); $done[] = 'hidden'; }
        if (isset($args['cover'])) { $this->vault->setCover($id, $this->key, $args['cover'] === true); $done[] = 'cover'; }
        if (!$done) throw new HttpError(422, 'Give at least one of title, prompt, favorite, hidden or cover.');
        $item = $this->vault->image($id, $this->key);
        return self::answer(['id'=>$id,'title'=>(string)($item['title'] ?? ''),'prompt'=>mb_substr((string)($item['prompt'] ?? ''), 0, 300),'favorite'=>(bool)$item['favorite'],
            'hidden'=>(bool)($item['hidden'] ?? false),'cover'=>(bool)($item['cover'] ?? false)]);
    }

    private function trashImages(array $args): array
    {
        $restore = ($args['restore'] ?? false) === true;
        $count = $this->vault->bulk($restore ? 'restore' : 'trash', self::ids($args, 'image_ids', 100));
        return self::answer(['count'=>$count,'note'=>$restore ? 'Taken out of the trash.' : 'In the trash: they leave the library and its listings; trash_images with restore brings them back, delete_images deletes them for good.']);
    }

    private function deleteImages(array $args): array
    {
        $ids = self::ids($args, 'image_ids', 100);
        $count = $this->vault->bulk('purge', $ids);
        return self::answer(['deleted'=>$count] + ($count < count($ids) ? ['note'=>'Only items in the trash are deleted: '.(count($ids) - $count).' of them were not (move them with trash_images first) or are already gone.'] : []));
    }

    private function createAlbum(array $args): array
    {
        $name = self::text($args, 'name', 120, true);
        $album = $this->vault->createAlbum((string)$name, $this->key, null, ($args['show_in_library'] ?? true) !== false);
        $count = isset($args['image_ids']) ? $this->vault->bulk('album-add', self::ids($args), $album['id']) : 0;
        return self::answer(['album_id'=>$album['id'],'name'=>$album['name'],'count'=>$count,'show_in_library'=>$album['library']]);
    }

    private function updateAlbum(array $args): array
    {
        $id = (string)$this->albumId($args);
        if (!isset($args['album_id'])) throw new HttpError(422, 'album_id is needed.');
        $out = ['album_id'=>$id];
        if (isset($args['name'])) { $this->vault->renameAlbum($id, (string)self::text($args, 'name', 120, true), $this->key); $out['renamed'] = true; }
        if (isset($args['show_in_library'])) { $this->vault->setAlbumLibrary($id, $args['show_in_library'] !== false); $out['show_in_library'] = $args['show_in_library'] !== false; }
        if (isset($args['add_image_ids'])) $out['added'] = $this->vault->bulk('album-add', self::ids($args, 'add_image_ids'), $id);
        if (isset($args['remove_image_ids'])) $out['removed'] = $this->vault->bulk('album-remove', self::ids($args, 'remove_image_ids'), $id);
        if (count($out) === 1) throw new HttpError(422, 'Give name, show_in_library, add_image_ids or remove_image_ids.');
        return self::answer($out);
    }

    private function deleteAlbum(array $args): array
    {
        $id = $this->albumId($args);
        if ($id === null) throw new HttpError(422, 'album_id is needed.');
        $this->vault->deleteAlbum($id);
        return self::answer(['deleted'=>$id,'note'=>'The album is gone; its items stay in the library.']);
    }

    private function reorderImages(array $args): array
    {
        $before = isset($args['before_id']) ? self::imageId($args, 'before_id') : null;
        $this->vault->reorder(self::ids($args), $before);
        return self::answer(['ok'=>true,'note'=>'list_images with sort custom shows this order.']);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Requests and the GPU
    // -----------------------------------------------------------------------------------------------------------------

    /** A request as check_requests shows it. */
    private function requestRow(array $j): array
    {
        $kind = ($j['runner'] ?? null) !== null ? ($j['kind'] ?? 'video') : (($j['operation'] ?? '') === 'clean' ? 'clean' : 'image');
        return ['request_id'=>$j['id'],'status'=>$j['status'],'phase'=>$j['phase'],'kind'=>$kind,'operation'=>$j['operation'],'made_by'=>($j['runner'] ?? null) !== null ? 'gpu' : 'server',
            'prompt'=>mb_substr((string)($j['label'] ?? $j['prompt']), 0, 200),'created'=>gmdate('c', (int)$j['created']),'error'=>$j['error'],'image_ids'=>$j['image_ids']]
            + (is_array($j['progress'] ?? null) ? ['progress'=>$j['progress']] : []);
    }

    private function requestAnswer(array $job): array { return self::answer(['request'=>$this->requestRow($job)]); }

    private function retryRequest(array $args, ?\Closure $keepalive): array
    {
        $created = $this->jobs->retry(bin2hex(random_bytes(16)), self::imageId($args, 'request_id'), $args['n'] ?? null, $this->key, $this->albumId($args));
        return $this->finish($created, $keepalive);
    }

    /** What the page's GPU settings show: whether the notebook is on and doing what, and what waits for it. */
    private function gpuState(): array
    {
        $s = $this->gpu()->status(); $w = $s['worker'];
        $out = ['on'=>$w !== null,'queued'=>$s['queued'],'running'=>$s['running'],'idle_minutes'=>$s['idle_minutes'],'pictures'=>$this->gpuPictures(),'token_made'=>$s['token'] !== null,'notebook'=>$s['notebook']];
        if ($w !== null) $out['worker'] = ['gpu'=>$w['gpu'] ?? null,'vram_gb'=>$w['vram'] ?? null,'phase'=>$w['phase'] ?? null,'message'=>$w['message'] ?? null,'models_loaded'=>$w['loaded'] ?? null,
            'making'=>$w['making'] ?? null,'progress'=>$w['progress'] ?? null,'stopping'=>$w['stopping'],'turns_off_at'=>is_int($w['off_at']) ? gmdate('c', $w['off_at']) : null];
        if ($s['last'] !== null) $out['last'] = ['ended'=>'The last notebook turned itself off: Colab gave it a GPU too small for the models ('.$s['last']['gpu'].'). Choose an A100 or a G4 in Colab.','at'=>gmdate('c', $s['last']['at'])];
        $out['note'] = $w !== null ? 'The GPU is on: pictures of minimax-h3-image and videos are made now, one at a time.'
            : ($s['token'] === null ? 'The GPU is not set up: the person makes the notebook\'s token in Settings › GPU and starts the notebook in Colab. It can\'t be started from here.'
                : 'The GPU is off: requests for it wait in line until the person starts the notebook in Colab (Settings › GPU has the link). It can\'t be started from here.');
        return $out;
    }

    private function stopGpu(): array
    {
        $this->gpu()->requestStop();
        return self::answer(['asked'=>true,'note'=>'The notebook turns itself off after what it is making now, and releases the runtime.'] + ['gpu'=>$this->gpuState()]);
    }

    private function setGpuIdle(array $args): array
    {
        $minutes = $args['minutes'] ?? null;
        if (!is_int($minutes)) throw new HttpError(422, 'minutes must be one of '.implode(', ', Gpu::IDLE_CHOICES).'.');
        $this->gpu()->setIdleMinutes($minutes);
        return self::answer(['idle_minutes'=>$minutes]);
    }

    private function setGpuPictures(array $args): array
    {
        if (!is_bool($args['jpeg'] ?? null)) throw new HttpError(422, 'jpeg must be true or false.');
        $quality = $args['quality'] ?? null;
        if ($quality !== null && !is_int($quality)) throw new HttpError(422, 'quality must be a whole number from '.Gpu::JPEG_QUALITY[0].' to '.Gpu::JPEG_QUALITY[1].'.');
        $this->gpu()->setPictureFormat($args['jpeg'], $quality);
        return self::answer(['pictures'=>$this->gpuPictures()]);
    }

    /** How the notebook saves the pictures, as the MCP client reads it. */
    private function gpuPictures(): array
    {
        $f = $this->gpu()->pictureFormat();
        return ['jpeg'=>$f['jpeg'], 'jpeg_quality'=>$f['quality'], 'note'=>$f['jpeg']
            ? 'GPU pictures that would be PNG are saved as JPEG at quality '.$f['quality'].'; a request that asks for jpeg or webp keeps its own.'
            : 'GPU pictures are saved in the format each request asks for (png unless it asks for jpeg or webp).'];
    }

    private function saveLora(array $args): array
    {
        $input = ['url'=>(string)self::text($args, 'url', 2000, true), 'name'=>(string)self::text($args, 'name', 80), 'trigger'=>(string)self::text($args, 'trigger', 200), 'engine'=>'h3-image'];
        if (isset($args['strength'])) $input['strength'] = $args['strength'];
        if (isset($args['lora_id'])) {
            $id = self::loraId($args['lora_id']); $input['id'] = $id;
            // A change keeps what it doesn't name.
            $old = array_column($this->gpu()->loras($this->key), null, 'id')[$id] ?? throw new HttpError(404, t('This LoRA is no longer in your list.'));
            if (!isset($args['name'])) $input['name'] = (string)($old['name'] ?? '');
            if (!isset($args['trigger'])) $input['trigger'] = (string)($old['trigger'] ?? '');
            if (!isset($args['strength'])) $input['strength'] = $old['strength'] ?? 1;
        }
        $list = $this->gpu()->saveLora($input, $this->key);
        $saved = isset($input['id']) ? array_column($list, null, 'id')[$input['id']] : end($list);
        return self::answer(['lora'=>self::loraList([$saved])[0], 'count'=>count($list)]);
    }

    /** A video request of the arguments: the options and inputs as the page's video window sends them. */
    private function videoRequest(array $args): array
    {
        $engine = Gpu::engines()['h3'];
        $options = ['engine'=>'h3', 'mode'=>isset($args['source_video_id']) ? 'video' : 'new'];
        foreach (['seconds','aspect','quality','resolution','seed'] as $name) if (isset($args[$name])) $options[$name] = $args[$name];
        $inputs = [];
        $list = function (string $name, int $max) use ($args): array {
            if (!isset($args[$name])) return [];
            if (!is_array($args[$name]) || !array_is_list($args[$name]) || count($args[$name]) > $max) throw new HttpError(422, "$name must be a list of up to $max items.");
            return $args[$name];
        };
        foreach ($list('reference_image_ids', $engine['limits']['images']) as $id) $inputs[] = ['kind'=>'image', 'role'=>'ref', 'id'=>self::imageId(['id'=>$id], 'id')];
        if ($options['mode'] === 'video') {
            foreach (['keyframes','reference_video_ids','sounds'] as $name) if (!empty($args[$name])) throw new HttpError(422, "$name are for a new video, not with source_video_id.");
            $inputs[] = ['kind'=>'video', 'role'=>'source', 'id'=>self::imageId($args, 'source_video_id')];
            $op = $args['operation'] ?? null;
            if (!is_string($op)) throw new HttpError(422, 'With source_video_id, operation says what to do with it: '.implode(', ', $engine['ops']).'.');
            $options['op'] = $op;
            if ($op === 'replace') $options['target'] = (string)self::text($args, 'target', 120);
            if ($op === 'continue') $options['join'] = ($args['join'] ?? true) !== false; else $options['keep_audio'] = ($args['keep_audio'] ?? true) !== false;
        } else {
            if (isset($args['operation'])) throw new HttpError(422, 'operation needs source_video_id: the video to start from.');
            foreach ($list('keyframes', $engine['limits']['images']) as $k) {
                if (!is_array($k)) throw new HttpError(422, 'Each keyframe needs image_id and at.');
                $at = $k['at'] ?? null; $at = $at === 'start' ? 0 : $at;
                if ($at !== 'end' && !is_int($at) && !is_float($at)) throw new HttpError(422, 'A keyframe\'s at must be seconds from the start, or "end".');
                $inputs[] = ['kind'=>'image', 'role'=>'key', 'at'=>$at, 'id'=>self::imageId($k)];
            }
            foreach ($list('reference_video_ids', $engine['limits']['videos']) as $id) $inputs[] = ['kind'=>'video', 'role'=>'ref', 'id'=>self::imageId(['id'=>$id], 'id')];
            foreach ($list('sounds', $engine['limits']['audios']) as $s) {
                if (!is_array($s) || !is_string($s['data'] ?? null)) throw new HttpError(422, 'Each sound needs data: a data URL such as data:audio/mpeg;base64,...');
                $inputs[] = ['kind'=>'audio', 'role'=>isset($s['at']) ? 'key' : 'ref', 'data'=>$s['data'], 'name'=>is_string($s['name'] ?? null) ? $s['name'] : '']
                    + (isset($s['at']) ? ['at'=>$s['at']] : []);
            }
        }
        return [$options, $inputs];
    }

    private function generateVideo(array $args): array
    {
        $prompt = self::text($args, 'prompt', 8000, true);
        [$options, $inputs] = $this->videoRequest($args);
        $album = $this->albumId($args);
        $created = $this->jobs->createVideo(['id'=>bin2hex(random_bytes(16)),'prompt'=>$prompt,'options'=>$options,'inputs'=>$inputs,'label'=>self::text($args, 'title', 200)]
            + ($album !== null ? ['album'=>$album] : []), $this->key);
        $on = $this->gpuOn();
        return self::answer(['request'=>$this->requestRow($created[0]),'gpu_on'=>$on,
            'note'=>$on ? 'The GPU is on: the video is made in a few minutes (more the first time, while the model downloads). check_requests with this request id and wait_seconds shows when it is ready; get_video then gives the file.'
                : 'Waiting for the GPU: the video is made once the person starts the notebook in Colab (Settings › GPU). It can\'t be started from here. check_requests shows when it is ready.']);
    }

    private function improveVideoPrompt(array $args): array
    {
        [$options, $inputs] = $this->videoRequest($args);
        if (function_exists('set_time_limit')) @set_time_limit(150);
        $input = ['prompt'=>self::text($args, 'prompt', 8000, true),'options'=>$options,'inputs'=>$inputs];
        if (isset($args['provider'])) $input['provider'] = $args['provider'];
        $out = (new VideoPrompt($this->vault))->improve($input, $this->key);
        return self::answer(['prompt'=>$out['prompt'],'by'=>$out['model'],'cost_usd'=>round((float)$out['cost'], 4),'note'=>'Pass this prompt to generate_video with the same inputs and options.']);
    }

    private function getVideo(array $args): array
    {
        $item = $this->vault->video(self::imageId($args, 'video_id'), $this->key);
        if ($item['deleted'] !== null) throw new HttpError(404, t('Video not found.'));
        $content = [['type'=>'text','text'=>self::json($this->describe($item, true) + ['size_bytes'=>(int)($item['size'] ?? 0),'fps'=>$item['fps'] ?? null])]];
        try { $content[] = $this->preview($item); } catch (HttpError) { /* no still yet */ }
        return ['content'=>$content];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Stories as a whole
    // -----------------------------------------------------------------------------------------------------------------

    private function duplicateStory(array $args): array
    {
        $copy = $this->writer()->duplicate($this->storyId($args), $args['title'] ?? null, $this->key);
        return self::answer(['story_id'=>$copy['id'],'title'=>$copy['title'],'note'=>'Copied. Call open_story with the new story_id to work on it.']);
    }

    private function favoriteStory(array $args): array
    {
        $id = $this->storyId($args);
        if (!is_bool($args['favorite'] ?? null)) throw new HttpError(422, 'favorite must be true or false.');
        $this->stories()->favorite($id, $args['favorite']);
        return self::answer(['story_id'=>$id,'favorite'=>$args['favorite']]);
    }

    private function deleteStory(array $args): array
    {
        $id = $this->storyId($args);
        $title = (string)$this->stories()->listed($id, $this->key)['title'];
        $confirm = $args['confirm_title'] ?? null;
        if (!is_string($confirm) || trim($confirm) !== trim($title)) throw new HttpError(422, 'confirm_title must be the story\'s title exactly ("'.$title.'"). Delete it only when the person asked for it.');
        if ($this->writerBusy($id) !== null) throw new HttpError(409, 'The app\'s writer is working on this story now. Wait for it to finish first.');
        $this->stories()->delete($id);
        return self::answer(['deleted'=>$id,'title'=>$title,'note'=>'The story is gone. Its pictures stay in their albums.']);
    }
}
