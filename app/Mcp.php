<?php
declare(strict_types=1);
namespace Studio;

/**
 * Remote MCP server (Streamable HTTP transport, stateless, JSON-RPC 2.0). A token belongs to one account: every tool
 * reads and writes that account's library and stories and uses that account's API keys. The story tools are the app
 * writer's own (Writer::tools), so a client on the person's own subscription can write the stories in the app.
 *
 * Generation tools run the request inside the MCP call, so the client gets the images in the answer. While the
 * provider works, the caller's keepalive keeps the connection busy, and the request is a normal job: it shows up in
 * the account's feed and keeps running on the server if the client gives up.
 */
final class Mcp
{
    use McpStudio;

    public const PROTOCOLS = ['2025-11-25','2025-06-18','2025-03-26','2024-11-05'];
    private const LONG_TOOLS = ['generate_image','edit_image','upscale_image'];
    /** Story tools that may wait for a picture still being made (a reference, the previous page), so they are streamed too. */
    private const STORY_WAITS = ['illustrate','character_sheet','set_style','edit_illustration','draw_comic_page','comic_settings','make_sprite','make_background','make_cg','update_art'];
    /** What a client over MCP gets before the writer's own instructions (Writer::SYSTEM), when it opens a story. */
    public const STORY_GUIDE = <<<'TEXT'
You are now this story's writer, working on it through the Atelier app's tools over MCP. The story lives in the app: the person reads it there, and may also use the app's own writer on the same story, so the app is the source of truth. Read before you write, and don't rely on what an earlier conversation said about it.
Below are the instructions the app's own writer follows. Follow them too, with these differences:
- There is no <story_state> or <story_update> in your messages: open_story shows the story as it is now (the <story_state> below), and get_outline shows it again later. When the person says they changed something in the app, read it again.
- Every story tool takes this story's story_id and the opened value given with it below, and refuses without them: that is how the app knows these instructions were read first. The value lasts a day; after that, or in a new conversation, call open_story again.
- Your replies are shown in full in this chat, so don't use the <no_spoilers> tags: keep every reply free of spoilers instead (the Spoilers section says what counts), unless the person asks for them. Tool results can show the plan; don't quote it.
- "Messages written while you work" don't apply here.
- The app doesn't know the person's image credit here: make only the pictures asked for or that the story settings call for.
- Pictures are made on the server and take a minute or a few. A request id works in references right away: the tool waits for that picture for a while (on this server, up to about a minute and a half), and if it is still being made, says so; then call the tool again a little later. check_requests shows how the requests go.
- What you change is marked in the app as done via MCP, and the app's writer is told about it at its next message.
TEXT;
    private const LINK_SECONDS = 86400;
    private const OPENED_SECONDS = 86400;
    /** Same instruction as the studio's upscale panel (assets/app.js). */
    private const MAX_SIDE = 3840;
    private const MAX_AREA = 8294400;
    private const MIN_AREA = 655360;

    public function __construct(private Vault $vault, private Jobs $jobs, private string $key, private string $baseUrl, private array $config, private ?ImageClient $api = null) {}

    /** Calls that wait for the provider, so the answer must be streamed with keepalives. */
    public static function isLong(mixed $message): bool
    {
        if (!is_array($message) || ($message['method'] ?? '') !== 'tools/call') return false;
        $name = $message['params']['name'] ?? '';
        // check_requests waits only when asked to.
        if ($name === 'check_requests') return (int)($message['params']['arguments']['wait_seconds'] ?? 0) > 0;
        return in_array($name, [...self::LONG_TOOLS, ...self::STORY_WAITS, ...self::STUDIO_LONG], true);
    }

    /** @return ?array the JSON-RPC answer, or null for notifications and client responses */
    public function handle(mixed $message, ?\Closure $keepalive = null): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') return self::error(null, -32600, 'Invalid Request');
        if (!isset($message['method'])) return null;              // a response to a server request: none are sent
        if (!array_key_exists('id', $message)) return null;       // notifications/initialized, cancelled...
        $id = $message['id'];
        if (!is_string($id) && !is_int($id)) return self::error(null, -32600, 'Invalid Request');
        $params = $message['params'] ?? [];
        if (!is_array($params)) return self::error($id, -32602, 'Invalid params');
        $result = match ($message['method']) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass(),
            'tools/list' => ['tools'=>self::tools()],
            'tools/call' => $this->call($params, $keepalive),
            default => null,
        };
        if ($result === null) return self::error($id, -32601, 'Method not found');
        if (is_array($result) && isset($result['jsonrpc_error'])) return self::error($id, $result['jsonrpc_error'], $result['message']);
        return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result];
    }

    public static function error(mixed $id, int $code, string $message): array
    { return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]]; }

    private function initialize(array $params): array
    {
        $requested = (string)($params['protocolVersion'] ?? '');
        $protocol = in_array($requested, self::PROTOCOLS, true) ? $requested : '2025-06-18';
        return [
            'protocolVersion'=>$protocol,
            'capabilities'=>['tools'=>['listChanged'=>false]],
            'serverInfo'=>self::serverInfo($this->baseUrl, $protocol),
            'instructions'=>'Atelier is a private image library backed by the OpenAI Image API (GPT Image models) and, when the account has a Gemini key, Google\'s Nano Banana models. Every image you generate, edit or upscale is saved '
                .'to this account\'s library and comes back with its id, a preview and a download link valid for 24 hours (fetch it with any HTTP '
                .'client to get the full-resolution file). Each image costs the provider\'s credits and usually takes 30 seconds to 3 minutes, so ask for '
                .'several images only when useful; for several different images, parallel calls finish sooner than one call with n > 1. '
                .'Edits and upscales are saved as new versions linked to the source image. To edit a picture you only have as a file, import it '
                .'first with import_image. If a call times out, the request keeps running on the server: use check_requests to find its images. '
                .'The account\'s stories (long fiction written chapter by chapter, with a story bible, a private plan, illustrations, a cover, character and place sheets, and comic or manga adaptations) '
                .'can be worked on with the same tools the app\'s own writer uses: find the story with list_stories (or start one with create_story), then call open_story before anything else: '
                .'it gives the writer\'s instructions, the story as it is now and an opened value, and every story tool takes the story_id and that opened value (they refuse without it). '
                .'The rest of the app is here too: Limpar (clean_image, or clean_first on an edit), crop, expand, a painted area and Cobrir (edit_image with areas or mask), Fotorrealista, Melhorar, '
                .'the library (albums, stacks, hidden items, the trash), and the account\'s GPU on Colab, which makes minimax-h3-image pictures (with LoRAs) and videos with sound at no cost per picture, '
                .'but only while the person has its notebook on: this server can\'t turn it on, so those requests wait in line until then (gpu_status, check_requests).',
        ];
    }

    /**
     * Who the server is, with the app's logo for the client to show next to the connector (MCP 2025-11-25). The icons
     * are the app's own PNGs at their public address on this site (same origin, no sign-in), with the icon version so
     * a client that keeps icons fetches a new one. "sizes" is a list since 2025-11-25 and a single string in clients
     * written for the draft before it, so only clients that ask for 2025-11-25 get it.
     */
    public static function serverInfo(string $baseUrl, string $protocol): array
    {
        $listsSizes = strcmp($protocol, '2025-11-25') >= 0;
        $icons = [];
        foreach ([512, 192] as $side)
            $icons[] = ['src'=>$baseUrl.'assets/icon-'.$side.'.png?v='.iconVersion(), 'mimeType'=>'image/png'] + ($listsSizes ? ['sizes'=>[$side.'x'.$side]] : []);
        return ['name'=>'atelier', 'title'=>'Atelier', 'version'=>VERSION,
            'description'=>'A private studio for pictures and stories made with AI.',
            'icons'=>$icons, 'websiteUrl'=>$baseUrl];
    }

    public static function tools(): array
    {
        $f = self::fields();
        ['size'=>$size,'quality'=>$quality,'model'=>$model,'refs'=>$refs,'n'=>$n,'vary'=>$vary,'lines'=>$lines,'title'=>$title,'imageId'=>$imageId] = $f;
        $flexible = array_keys(array_filter(Images::models(), fn($m) => $m['flex']));
        $write = ['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>true];
        $read = ['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false];
        $unit = ['type'=>'string','enum'=>['px','fraction'],'description'=>'px (default): pixels of the image as stored (get_image gives its width and height). fraction: 0 to 1 of its width and height.'];
        return [
            ['name'=>'generate_image','title'=>'Generate image','annotations'=>$write,
                'description'=>'Create new images from a text prompt (optionally guided by reference images from the library) and save them to the library. Waits until they are ready and returns each image with its id, a preview and a download link.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'prompt'=>['type'=>'string','maxLength'=>32000,'description'=>'What to create, in any language. Be specific about subject, style, composition, lighting and any text to render. '
                        .'To say what to take from each reference image, cite it as @1, @2… in the order of reference_image_ids (e.g. "the face from @1 wearing the jacket from @2").'],
                    'size'=>$size + ['default'=>'1:1'],'quality'=>$quality,'model'=>$model,
                    'background'=>['type'=>'string','enum'=>['auto','opaque','transparent'],'description'=>'transparent needs png or webp.'],
                    'format'=>['type'=>'string','enum'=>['png','jpeg','webp'],'description'=>'File format. Default png. On the GPU model, png comes as jpeg when Settings › GPU saves its pictures as JPEG (gpu_status, set_gpu_pictures).'],
                    'n'=>$n,'vary'=>$vary,'lines'=>$lines,'reference_image_ids'=>$refs,'title'=>$title,
                    'planning'=>$f['planning'],'complexity'=>$f['complexity'],'loras'=>$f['loras'],'seed'=>$f['seed'],'album_id'=>$f['album'],
                ],'required'=>['prompt']]],
            ['name'=>'edit_image','title'=>'Edit image','annotations'=>$write,
                'description'=>'Change an image from the library following an instruction. The result is saved as a new version linked to the original, which is kept. Keeps the original proportions unless size is given. '
                    .'As the app\'s brush, areas or mask can mark the only part to change (the rest comes back as it is); with an area and no prompt, that area is redone clean and correct (fixing hands, faces, text, artifacts). '
                    .'cover erases the area before sending, so the model draws it without seeing what was there (no prompt: it is filled with what surrounds it, to remove something).',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'image_id'=>$imageId,
                    'prompt'=>['type'=>'string','maxLength'=>32000,'description'=>'What to change, and what must stay the same. @1 is the image being edited; @2, @3… are the reference_image_ids in order (e.g. "put the hat from @2 on the person in @1"). '
                        .'Optional with areas or mask.'],
                    'areas'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','properties'=>[
                            'x'=>['type'=>'number','minimum'=>0],'y'=>['type'=>'number','minimum'=>0],'width'=>['type'=>'number','exclusiveMinimum'=>0],'height'=>['type'=>'number','exclusiveMinimum'=>0],
                            'shape'=>['type'=>'string','enum'=>['rect','ellipse'],'description'=>'Default rect.']],'required'=>['x','y','width','height']],
                        'description'=>'The only parts of the image to change: rectangles or ellipses (x, y of the top left corner), in unit.'],
                    'unit'=>$unit,
                    'mask'=>['type'=>'string','description'=>'Instead of areas: a PNG or WebP (base64 or data URL), stretched to the image\'s size, whose transparent pixels are the part to change (the image API\'s convention); a mask with no transparency marks it in white on black.'],
                    'cover'=>['type'=>'boolean','description'=>'Cobrir, with areas or mask: erase the area before sending, so nothing of what was there is kept. Default false.'],
                    'reference_image_ids'=>$refs,'size'=>$size,'quality'=>$quality,'model'=>$model,'n'=>$n,'vary'=>$vary,'lines'=>$lines,'title'=>$title,
                    'planning'=>$f['planning'],'complexity'=>$f['complexity'],'loras'=>$f['loras'],'seed'=>$f['seed'],'clean_first'=>$f['cleanFirst'],'album_id'=>$f['album'],
                ],'required'=>['image_id']]],
            ['name'=>'upscale_image','title'=>'Upscale image','annotations'=>$write,
                'description'=>'Recreate a library image at a larger size with more detail, keeping its proportions (the API has no native upscaler, so small details may change). Saved as a new version. '
                    .'with adds Melhorar\'s cleanup (enhance) or makes it Fotorrealista at the larger size (photoreal), as the app\'s Ampliar does.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'image_id'=>$imageId,
                    'scale'=>['type'=>'string','enum'=>['1.5','2','3','max'],'description'=>'Default 2. max is the largest size the model allows for these proportions (the GPU model goes up to 12000 px a side and 40 MP).'],
                    'instructions'=>['type'=>'string','maxLength'=>2000,'description'=>'Optional: what to improve, e.g. sharper face.'],
                    'with'=>['type'=>'string','enum'=>['none','enhance','photoreal'],'description'=>'Default none.'],
                    'quality'=>$quality,'model'=>['type'=>'string','enum'=>$flexible,'description'=>$model['description']],
                    'loras'=>$f['loras'],'seed'=>$f['seed'],'clean_first'=>$f['cleanFirst'],'album_id'=>$f['album'],
                ],'required'=>['image_id']]],
            ['name'=>'list_images','title'=>'List images','annotations'=>$read,
                'description'=>'List the library, newest first, optionally searching titles and prompts, in an album, among videos, hidden items or the trash. Returns ids and metadata, no pixels.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'query'=>['type'=>'string','maxLength'=>200,'description'=>'Text to look for in prompts and labels.'],
                    'favorites_only'=>['type'=>'boolean'],
                    'kind'=>['type'=>'string','enum'=>['image','video','all'],'description'=>'Default image.'],
                    'album_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Only the items of this album (list_albums).'],
                    'origin'=>['type'=>'string','enum'=>Vault::ORIGINS,'description'=>'generated (made by a model), imported (brought in, or a video frame) or video.'],
                    'hidden'=>['type'=>'string','enum'=>['include','exclude','only'],'description'=>'Items hidden from the library (Ocultas): include (default), exclude or only.'],
                    'trash'=>['type'=>'boolean','description'=>'List what is in the trash instead.'],
                    'group'=>['type'=>'boolean','description'=>'One entry per image with its versions (and per stack), as the library shows them. Default false: every version.'],
                    'sort'=>['type'=>'string','enum'=>Vault::SORTS,'description'=>'Default newest. custom is the order arranged by hand.'],
                    'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'description'=>'Default 20.'],
                    'offset'=>['type'=>'integer','minimum'=>0],
                ]]],
            ['name'=>'get_image','title'=>'Get image','annotations'=>$read,
                'description'=>'Details of one library image: full prompt, options, versions, a preview and a fresh download link.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'image_id'=>$imageId,
                    'include_preview'=>['type'=>'boolean','description'=>'Return a preview of up to 1024 px. Default true.'],
                ],'required'=>['image_id']]],
            ['name'=>'import_image','title'=>'Import image','annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>false],
                'description'=>'Add a PNG, JPEG or WebP image to the library (up to 14 MB), so it can be edited, upscaled or used as a reference.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'data'=>['type'=>'string','description'=>'The image as base64, or as a data URL (data:image/png;base64,...).'],
                    'name'=>['type'=>'string','maxLength'=>160,'description'=>'File name or title.'],
                ],'required'=>['data']]],
            ['name'=>'check_requests','title'=>'Check requests','annotations'=>$read,
                'description'=>'Status of recent requests (the 10 newest, plus any ids given): images, cleanups and the GPU\'s pictures and videos, with their progress, errors and image ids. Use it after a call that timed out, or to wait for a GPU request.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'request_ids'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>20],
                    'wait_seconds'=>['type'=>'integer','minimum'=>0,'maximum'=>600,'description'=>'With request_ids: wait up to this long (and at most what this server lets one call last) for them to end. Default 0.'],
                ]]],
            ...self::studioTools(),
            ...self::storyTools(),
        ];
    }

    /** The stories: list, create and open one, then the tools of the app's writer (Writer::tools), each with the story's id. */
    public static function storyTools(): array
    {
        $storyId = ['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'The story\'s id, from list_stories or create_story.'];
        $opened = ['type'=>'string','description'=>'The opened value open_story gave for this story. Call open_story first: it gives the writer\'s instructions, which come before any work on the story. It lasts a day.'];
        $read = ['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false];
        $tools = [
            ['name'=>'list_stories','title'=>'List stories','annotations'=>$read,
                'description'=>'The account\'s stories, last changed first: id, title, kind (story, or vn for a visual novel project), series, synopsis, chapters, words and illustrations of each, and whether the app\'s own writer is working on it now.',
                'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'create_story','title'=>'Create story','annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>false],
                'description'=>'Starts a new, empty story in the app (it shows up in the person\'s list of stories), or a visual novel project (kind vn: it shows up in their Visual Novel tab), with the default writer settings. Then call open_story.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'title'=>['type'=>'string','maxLength'=>200,'description'=>'The title, in the language of the story ("" for none yet).'],
                    'kind'=>['type'=>'string','enum'=>Stories::KINDS,'description'=>'story (default) or vn, a visual novel project.'],
                    'synopsis'=>['type'=>'string','maxLength'=>6000,'description'=>'Back-cover text, without spoilers. Optional.'],
                    'series'=>['type'=>'string','maxLength'=>120,'description'=>'The series it belongs to. Optional.'],
                ],'required'=>['title']]],
            ['name'=>'open_story','title'=>'Open story','annotations'=>$read,
                'description'=>'Call this first when working on a story, in each conversation: the story tools refuse until then, since they take the opened value it gives. It gives the instructions the app\'s writer follows (how the story is kept, planned, written, illustrated and adapted to comics, or for a visual novel project its scenes, choices and art), and the story as it is now: '
                    .'details, settings, every chapter with its summary, the bible, the private plan, the sheets and style, the comic or the visual novel, and the last messages of the app\'s writer conversation.',
                'inputSchema'=>['type'=>'object','properties'=>['story_id'=>$storyId],'required'=>['story_id']]],
        ];
        $reads = ['get_outline','read_chapter','read_notes','read_plan','search_story','find_repetitions','review_book','view_image','search_gallery','read_comic','view_comic_page','read_scenes','read_gameplay','check_vn'];
        $paid = ['illustrate','character_sheet','set_style','edit_illustration','draw_comic_page','make_sprite','make_background','make_cg'];
        $deletes = ['delete_chapter','rewrite_chapter','delete_comic_page','delete_scene'];
        // One list for every story: the visual novel's tools work on visual novel projects, the comic's on stories.
        foreach (Writer::tools(null) as $name => [$description, $schema]) {
            $schema['properties'] = ['story_id'=>$storyId,'opened'=>$opened] + (array)$schema['properties'];
            $schema['required'] = array_values(array_unique(['story_id', 'opened', ...($schema['required'] ?? [])]));
            $tools[] = ['name'=>$name,'title'=>ucfirst(str_replace('_', ' ', $name)),'description'=>$description,'inputSchema'=>$schema,
                'annotations'=>in_array($name, $reads, true) ? $read : ['readOnlyHint'=>false,'destructiveHint'=>in_array($name, $deletes, true),'idempotentHint'=>false,'openWorldHint'=>in_array($name, $paid, true)]];
        }
        return $tools;
    }

    private function call(array $params, ?\Closure $keepalive): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        if (!is_string($name) || !in_array($name, array_column(self::tools(), 'name'), true)) return ['jsonrpc_error'=>-32602,'message'=>'Unknown tool'];
        if (!is_array($args)) return self::failed('arguments must be an object.');
        try {
            return match ($name) {
                'generate_image' => $this->generate($args, $keepalive),
                'edit_image' => $this->edit($args, $keepalive),
                'upscale_image' => $this->upscale($args, $keepalive),
                'list_images' => $this->listImages($args),
                'get_image' => $this->getImage($args),
                'import_image' => $this->import($args),
                'check_requests' => $this->checkRequests($args, $keepalive),
                'list_stories' => $this->listStories(),
                'create_story' => $this->createStory($args),
                'open_story' => $this->openStory($args),
                default => $this->studioCall($name, $args, $keepalive) ?? $this->storyTool($name, $args, $keepalive),
            };
        } catch (HttpError $e) {
            return self::failed($e->getMessage());
        }
    }

    private static function failed(string $message): array { return ['content'=>[['type'=>'text','text'=>$message]],'isError'=>true]; }

    private static function text(array $args, string $name, int $max, bool $required = false): ?string
    {
        $value = $args[$name] ?? null;
        if ($value === null && !$required) return null;
        if (!is_string($value) || ($required && trim($value) === '')) throw new HttpError(422, "$name must be a non-empty string.");
        $value = trim($value);
        if (mb_strlen($value) > $max) throw new HttpError(422, "$name is longer than $max characters.");
        return $value === '' ? null : $value;
    }

    private static function imageId(array $args, string $name = 'image_id'): string
    {
        $id = $args[$name] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new HttpError(422, "$name must be an image id (32 hexadecimal characters).");
        return $id;
    }

    /** @return list<array{id:string}> */
    private function references(array $args): array
    {
        $ids = $args['reference_image_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 15) throw new HttpError(422, 'reference_image_ids must be a list of up to 15 image ids.');
        $refs = [];
        foreach ($ids as $id) { $id = self::imageId(['id'=>$id], 'id'); $this->vault->still($id, $this->key); $refs[] = ['id'=>$id]; }
        return $refs;
    }

    /** Options from the tool arguments over the given base, validated by the same rules as the studio. */
    private function options(array $args, array $base = []): array
    {
        // Options copied from an older image may name a model that is gone, or a quality the chosen model lacks. The
        // GPU's model is used only when asked for: it makes pictures only while the account's notebook is on.
        if (Images::model((string)($base['model'] ?? '')) === null || Images::provider((string)$base['model']) === 'gpu') unset($base['model']);
        unset($base['loras'], $base['seed'], $base['engine']);
        $o = array_merge($base, ['n'=>1, 'previews'=>0, 'thinking'=>'off']);
        foreach (['model','quality','background','format','complexity'] as $name) if (isset($args[$name])) $o[$name] = $args[$name];
        if (isset($args['planning'])) $o['thinking'] = $args['planning'];
        $id = (string)($o['model'] ?? 'gpt-image-2.6-flare'); $model = Images::model($id);
        $provider = $model !== null ? Images::provider($id) : null;
        if ($model && !isset($args['quality']) && in_array($o['quality'] ?? '', ['xhigh','max'], true) && !$model['extended']) $o['quality'] = 'high';
        // A source made with a transparent background, changed with a model that has none (Nano Banana, the GPU's).
        if (in_array($provider, ['gemini','gpu'], true) && !isset($args['background']) && ($o['background'] ?? '') === 'transparent') $o['background'] = 'auto';
        if (isset($args['size'])) {
            if (!is_string($args['size'])) throw new HttpError(422, 'size must be a string.');
            $size = strtolower(str_replace([' ', '×', '*'], ['', 'x', 'x'], $args['size']));
            $o['size'] = OpenAI::SIZES[$size] ?? $size;
        }
        if (isset($args['n'])) {
            $n = filter_var($args['n'], FILTER_VALIDATE_INT);
            if ($n === false || $n < 1 || $n > 4) throw new HttpError(422, 'n must be between 1 and 4.');
            $o['n'] = $n;
        }
        $o['vary'] = !array_key_exists('vary', $args) || $args['vary'] === true;
        if (($o['background'] ?? '') === 'transparent' && ($o['format'] ?? 'png') === 'jpeg') $o['format'] = 'png';
        if ($provider === 'gpu') {
            if (isset($args['loras'])) $o['loras'] = $this->loras($args['loras']);
            if (isset($args['seed'])) $o['seed'] = $args['seed'];
        } elseif (isset($args['loras']) || isset($args['seed'])) throw new HttpError(422, 'loras and seed are only for the GPU model (minimax-h3-image).');
        return Images::options($o);
    }

    private function generate(array $args, ?\Closure $keepalive): array
    {
        $album = $this->albumId($args);
        $created = $this->jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>self::text($args, 'prompt', 32000, true),'options'=>$this->options($args),
            'references'=>$this->references($args),'label'=>self::text($args, 'title', 200)] + self::lines($args) + ($album !== null ? ['album'=>$album] : []), $this->key);
        return $this->finish($created, $keepalive);
    }

    /** One line per image, at most 4 like n: the images of one call run one after another. */
    private static function lines(array $args): array
    {
        if (!isset($args['lines'])) return [];
        if (!is_array($args['lines']) || !array_is_list($args['lines']) || count($args['lines']) > 4) throw new HttpError(422, 'lines must be a list of 2 to 4 texts.');
        return ['lines'=>$args['lines']];
    }

    /**
     * An edit, as the studio's edit panel sends it: with a prompt, or with an area marked (areas or mask) and no prompt
     * to redo that area, and with Cobrir to erase it first. Same ready-made instructions as the page (Presets).
     */
    private function edit(array $args, ?\Closure $keepalive): array
    {
        $source = $this->vault->still(self::imageId($args), $this->key);
        $prompt = self::text($args, 'prompt', 32000);
        $cover = ($args['cover'] ?? false) === true;
        $area = $this->area($args, $source);
        if ($area === null && $cover) throw new HttpError(422, 'cover needs the area to erase: give areas or mask.');
        if ($area === null && $prompt === null) throw new HttpError(422, 'prompt must be a non-empty string (it is optional only with areas or mask, to redo that area).');
        $redo = $prompt === null;
        if ($redo && isset($args['lines'])) throw new HttpError(422, 'lines need a prompt: without one, the area is redone the same way in every image.');
        // The ready-made instruction goes as written: no planning, every image the same request.
        $options = $this->editOptions($source, $args, $redo ? ['planning'=>'off', 'vary'=>false] : []);
        [$text, $label] = $redo ? ($cover ? [Presets::FILL, t('Covered area filled')] : [Presets::INPAINT, t('Area redone')])
            : ($cover ? [Presets::COVER_NOTE.$prompt, t('Covered area: {text}', ['text'=>preg_replace('/\s+/u', ' ', $prompt)])] : [$prompt, null]);
        try {
            // With an area marked the image goes as it is (never cleaned first: what is outside the area comes back as it
            // was sent); a covered area goes erased.
            $first = $area !== null && $cover ? $this->covered($source, $area) : ['id'=>$source['id']];
            $mask = $area !== null ? Picture::maskUrl($area['mask']) : null;
        } finally { if ($area !== null) imagedestroy($area['mask']); }
        $album = $this->albumId($args);
        $created = $this->jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>$text,'options'=>$options,
            'references'=>array_merge([$first], $this->references($args)),'mask'=>$mask,'parent_id'=>$source['id'],'operation'=>'edit',
            'label'=>self::text($args, 'title', 200) ?? $label] + ($label !== null ? ['typed'=>''] : []) + self::lines($args) + self::cleanFirst($args) + ($album !== null ? ['album'=>$album] : []), $this->key);
        return $this->finish($created, $keepalive);
    }

    private function upscale(array $args, ?\Closure $keepalive): array
    {
        $source = $this->vault->still(self::imageId($args), $this->key);
        $scale = $args['scale'] ?? '2';
        $scale = is_int($scale) || is_float($scale) ? rtrim(rtrim(number_format((float)$scale, 1, '.', ''), '0'), '.') : $scale;
        if (!in_array($scale, ['1.5','2','3','max'], true)) throw new HttpError(422, 'scale must be 1.5, 2, 3 or max.');
        $with = $args['with'] ?? 'none';
        if (!in_array($with, ['none','enhance','photoreal'], true)) throw new HttpError(422, 'with must be none, enhance or photoreal.');
        $base = self::sourceOptions($source);
        if (isset($base['model']) && !(Images::model((string)$base['model'])['flex'] ?? false)) unset($base['model']);
        $model = self::editModel($args, $base); $gpu = Images::provider($model) === 'gpu';
        $sizes = self::upscaleSizes((int)$source['width'], (int)$source['height'], $gpu);
        if (!$sizes) throw new HttpError(422, 'This image is already at the largest size '.($gpu ? 'the GPU model makes' : 'the API can produce').' for its proportions.');
        if (!isset($sizes[$scale])) throw new HttpError(422, 'That scale is not available for this image. Available: '.implode(', ', array_keys($sizes)).'.');
        [$width, $height] = $sizes[$scale];
        $instructions = (string)self::text($args, 'instructions', 2000);
        // Same format and background as the source. Planning would rewrite the instruction, so it stays off.
        $options = $this->options(['size'=>"{$width}x{$height}"] + array_intersect_key($args, array_flip(['model','quality','loras','seed'])) + ['vary'=>false], $base);
        if (!Images::model($options['model'])['flex']) throw new HttpError(422, 'GPT Image 1 mini cannot upscale: choose another model.');
        $label = match ($with) {
            'enhance' => t('Upscaled to {width} × {height} and enhanced', ['width'=>$width, 'height'=>$height]),
            'photoreal' => t('Upscaled to {width} × {height}, photoreal', ['width'=>$width, 'height'=>$height]),
            default => t('Upscaled to {width} × {height}', ['width'=>$width, 'height'=>$height]),
        };
        $album = $this->albumId($args);
        $created = $this->jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>Presets::upscale($instructions, $with),
            'options'=>$options,'references'=>[['id'=>$source['id']]],'parent_id'=>$source['id'],'operation'=>'upscale',
            // Written by the MCP client, not typed by the person: the details offer nothing as "what you wrote".
            'label'=>$label.($instructions !== '' ? ": $instructions" : ''),'typed'=>''] + self::cleanFirst($args) + ($album !== null ? ['album'=>$album] : []), $this->key);
        return $this->finish($created, $keepalive);
    }

    /**
     * Runs the created jobs and reports their images. The server's requests run here, or in a separate process that
     * this call waits for; the GPU's are made by its notebook, which this call waits for only while it is on.
     */
    private function finish(array $created, ?\Closure $keepalive): array
    {
        $gpu = ($created[0]['runner'] ?? null) !== null;
        $done = $created; $on = null;
        if ($gpu) {
            if ($on = $this->gpuOn()) $done = $this->waitFor($created, $keepalive, $this->waitDeadline());
        } elseif (($this->config['separate_process'] ?? false) && $this->api === null && $this->jobs->startSeparately(array_column($created, 'id'), $this->key)) {
            // They run in a separate process, which the web server doesn't stop. This request only waits, and answers
            // before the web server's own limit (Hostinger: 120 s); what is still running is found with check_requests.
            $done = $this->waitFor($created, $keepalive, $this->waitDeadline());
        }
        else {
            $done = [];
            foreach ($created as $job) {
                if (function_exists('set_time_limit')) @set_time_limit($this->config['api_timeout'] * 2 + 300);
                $done[] = $this->jobs->runAndWait($job['id'], $this->key, $this->api, $keepalive);
            }
        }
        $report = []; $content = []; $active = false;
        foreach ($done as $job) {
            $images = [];
            foreach ($job['image_ids'] as $imageId) {
                $item = $this->vault->image($imageId, $this->key);
                $images[] = $this->describe($item);
                $content[] = $this->preview($item);
            }
            $running = in_array($job['status'], ['queued','running','cancelling'], true); $active = $active || $running;
            $note = !$running ? null : (!$gpu ? 'Still running on the server. Call check_requests later.'
                : ($on ? 'The GPU is making it (or it waits for the GPU\'s turn). Call check_requests with this request id, and wait_seconds, to wait for it.'
                    : 'Waiting for the GPU: it is made once the person starts the notebook in Colab (Settings › GPU). It can\'t be started from here; check_requests shows when it is ready.'));
            $report[] = ['request_id'=>$job['id'],'status'=>$job['status'],'error'=>$job['error'],'variation'=>$job['variation']['label'] ?? null,'images'=>$images]
                + ($gpu ? ['made_by'=>'gpu'] : []) + ($note !== null ? ['note'=>$note] : []);
        }
        array_unshift($content, ['type'=>'text','text'=>self::json(['requests'=>$report])]);
        $result = ['content'=>$content];
        // Nothing came out and nothing is on the way (a request waiting for the GPU is not an error).
        if (!array_filter($done, fn($j) => $j['image_ids']) && !($gpu && $active)) $result['isError'] = true;
        return $result;
    }

    /** When a call that waits for requests answers: before the web server stops it (Hostinger: 120 s), else in 14 minutes. */
    private function waitDeadline(): int
    {
        $limit = Spawn::webLimit();
        return time() + max(20, min(840, ($limit ?? 900) - 25));
    }

    /** Waits for requests made elsewhere (a separate process, the GPU) until they end or the deadline. */
    private function waitFor(array $jobs, ?\Closure $keepalive, int $deadline): array
    {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $active = fn(array $list) => array_filter($list, fn(array $job) => in_array($job['status'], ['queued','running','cancelling'], true));
        while ($active($jobs) && time() < $deadline) {
            usleep(1500000);
            if ($keepalive !== null) $keepalive();
            $jobs = array_map(fn(array $job) => $this->jobs->freshJob($job['id'], $this->key) ?? $job, $jobs);
        }
        return $jobs;
    }

    /** Metadata for the client, with a download link of its own. */
    private function describe(array $item, bool $full = false): array
    {
        $video = ($item['kind'] ?? 'image') === 'video';
        $out = ['id'=>$item['id'],'title'=>(string)($item['title'] ?? ''),'width'=>(int)$item['width'],'height'=>(int)$item['height'],'mime'=>$item['mime'],
            'created'=>gmdate('c', (int)$item['created']),'operation'=>Vault::operation($item),'parent_id'=>Vault::lineageParent($item)]
            + ($video ? ['kind'=>'video','duration'=>round((float)($item['duration'] ?? 0), 3)] : [])
            + ['download_url'=>$this->baseUrl.'mcp.php?file='.$this->vault->createLink($item['id'], $this->key, self::LINK_SECONDS),
            'download_expires'=>gmdate('c', time() + self::LINK_SECONDS)];
        if ($full) {
            $out += ['prompt'=>(string)($item['prompt'] ?? ''),'original_prompt'=>$item['original_prompt'] ?? null,'planned_prompt'=>$item['planned_prompt'] ?? null,
                'label'=>$item['label'] ?? null,'favorite'=>(bool)$item['favorite'],'hidden'=>(bool)($item['hidden'] ?? false),'options'=>$item['options'] ?? null,'variation'=>$item['variation']['label'] ?? null];
        }
        return $out;
    }

    /** A preview the model can look at: up to 1024 px, JPEG, or PNG when the image is transparent. */
    private function preview(array $item): array
    {
        $picture = $this->vault->modelPreview($item, $this->key);
        return ['type'=>'image','data'=>$picture['data'],'mimeType'=>$picture['mime']];
    }

    private function listImages(array $args): array
    {
        $limit = filter_var($args['limit'] ?? 20, FILTER_VALIDATE_INT); $offset = filter_var($args['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 50 || $offset === false || $offset < 0) throw new HttpError(422, 'limit must be 1 to 50 and offset 0 or more.');
        $kind = $args['kind'] ?? 'image';
        if (!in_array($kind, ['image','video','all'], true)) throw new HttpError(422, 'kind must be image, video or all.');
        $hidden = $args['hidden'] ?? 'include';
        if (!in_array($hidden, ['include','exclude','only'], true)) throw new HttpError(422, 'hidden must be include, exclude or only.');
        $origin = $args['origin'] ?? null; $sort = $args['sort'] ?? 'newest';
        if ($origin !== null && !in_array($origin, Vault::ORIGINS, true)) throw new HttpError(422, 'origin must be one of '.implode(', ', Vault::ORIGINS).'.');
        if (!in_array($sort, Vault::SORTS, true)) throw new HttpError(422, 'sort must be one of '.implode(', ', Vault::SORTS).'.');
        $view = ['search'=>(string)self::text($args, 'query', 200),'favorites'=>($args['favorites_only'] ?? false) === true,'trash'=>($args['trash'] ?? false) === true,
            'kind'=>$kind === 'all' ? null : $kind,'origin'=>$origin,'album'=>$this->albumId($args),'group'=>($args['group'] ?? false) === true,'sort'=>$sort,
            'hidden'=>['include'=>null,'exclude'=>false,'only'=>true][$hidden]];
        $page = $this->vault->page($this->key, $view, $offset, $limit);
        $items = array_map(fn($i) => ['id'=>$i['id'],'kind'=>$i['kind'],'title'=>$i['title'],'prompt'=>$i['prompt'],'label'=>$i['label'],'width'=>$i['width'],'height'=>$i['height'],
            'created'=>gmdate('c', $i['created']),'operation'=>$i['operation'],'parent_id'=>$i['parent'],'favorite'=>$i['favorite'],'model'=>$i['model'],'versions'=>$i['versions']]
            + ($i['hidden'] ? ['hidden'=>true] : []) + ($i['stack'] !== null ? ['stack'=>$i['stack']] : []) + ($i['deleted'] !== null ? ['trashed'=>gmdate('c', (int)$i['deleted'])] : [])
            + ($i['kind'] === 'video' ? ['duration'=>$i['duration']] : []), $page['items']);
        return ['content'=>[['type'=>'text','text'=>self::json(['total'=>$page['total'],'has_more'=>$page['has_more'],'images'=>$items])]]];
    }

    private function getImage(array $args): array
    {
        $item = $this->vault->still(self::imageId($args), $this->key);
        $versions = array_map(fn($n) => ['id'=>$n['id'],'parent_id'=>$n['parent'],'operation'=>$n['operation'],'label'=>$n['label'],'created'=>gmdate('c', $n['created'])],
            $this->vault->lineage($item['id'], $this->key)['nodes']);
        $content = [['type'=>'text','text'=>self::json($this->describe($item, true) + ['versions'=>$versions])]];
        if (($args['include_preview'] ?? true) !== false) $content[] = $this->preview($item);
        return ['content'=>$content];
    }

    private function import(array $args): array
    {
        $data = $args['data'] ?? null;
        if (!is_string($data) || $data === '') throw new HttpError(422, 'data must be the image as base64 or a data URL.');
        if (!str_starts_with($data, 'data:')) {
            $bytes = base64_decode(preg_replace('/\s+/', '', $data) ?? '', true);
            $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
            if (!$info) throw new HttpError(422, 'data is not a PNG, JPEG or WebP image in base64.');
            $data = 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
        }
        $image = OpenAI::imageInput($data);
        $item = $this->vault->importImage((string)base64_decode(substr($image['url'], strpos($image['url'], ',') + 1), true), (string)self::text($args, 'name', 160), $this->key);
        return ['content'=>[['type'=>'text','text'=>self::json($this->describe($item))]]];
    }

    private function checkRequests(array $args, ?\Closure $keepalive = null): array
    {
        $ids = $args['request_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 20) throw new HttpError(422, 'request_ids must be a list of up to 20 ids.');
        $ids = array_values(array_filter($ids, 'is_string'));
        $wait = filter_var($args['wait_seconds'] ?? 0, FILTER_VALIDATE_INT);
        if ($wait === false || $wait < 0 || $wait > 600) throw new HttpError(422, 'wait_seconds must be 0 to 600.');
        if ($wait > 0 && $ids) {
            $asked = array_values(array_filter(array_map(fn($id) => preg_match('/\A[a-f0-9]{32}\z/D', $id) ? $this->jobs->freshJob($id, $this->key) : null, $ids)));
            if ($asked) $this->waitFor($asked, $keepalive, min(time() + $wait, $this->waitDeadline()));
        }
        $jobs = array_map(fn($j) => $this->requestRow($j), $this->jobs->list($this->key, 'recent', $ids));
        $out = ['requests'=>$jobs];
        if (array_filter($jobs, fn($j) => $j['made_by'] === 'gpu' && in_array($j['status'], ['queued','running','cancelling'], true))) {
            $gpu = $this->gpuState(); $out['gpu'] = ['on'=>$gpu['on'],'note'=>$gpu['note']];
        }
        return ['content'=>[['type'=>'text','text'=>self::json($out)]]];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Stories: the client works on them with the app's writer tools (Writer::runTool), as an author other than the
    // app's writer ('mcp'), so the app marks what it changed and the app's writer is told about it.
    // -----------------------------------------------------------------------------------------------------------------

    private ?Stories $stories = null;
    private ?Writer $writer = null;
    private function stories(): Stories { return $this->stories ??= new Stories($this->vault); }
    private function writer(): Writer { return $this->writer ??= new Writer($this->vault, $this->stories(), $this->jobs, $this->config); }

    /** A story of this account, by the story_id argument. */
    private function storyId(array $args): string
    {
        $id = $args['story_id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new HttpError(422, 'story_id must be a story id (32 hexadecimal characters). list_stories shows them.');
        if (!$this->stories()->exists($id)) throw new HttpError(404, 'There is no story with that id in this account. list_stories shows them.');
        return $id;
    }

    /**
     * What open_story gives for a story and every story tool then takes: proof that the client got the writer's
     * instructions. Signed with the account's key, so it can't be made up, and good for a day (OPENED_SECONDS).
     */
    private function opened(string $storyId, ?int $at = null): string
    {
        $at ??= time();
        return $at.'.'.substr(hash_hmac('sha256', "mcp-opened\0".$storyId."\0".$at, $this->key), 0, 24);
    }

    private function checkOpened(string $storyId, mixed $value): void
    {
        $first = 'Call open_story for this story first: it gives the writer\'s instructions, which come before any work on the story, and the opened value that every story tool takes with the story_id.';
        if (!is_string($value) || trim($value) === '') throw new HttpError(422, $first);
        $value = trim($value);
        if (!preg_match('/\A(\d{1,12})\.[a-f0-9]{24}\z/D', $value, $m) || !hash_equals($this->opened($storyId, (int)$m[1]), $value) || (int)$m[1] > time() + 60)
            throw new HttpError(422, 'That opened value is not one open_story gave for this story. '.$first);
        if (time() - (int)$m[1] > self::OPENED_SECONDS)
            throw new HttpError(422, 'This story was opened more than a day ago. Call open_story for it again, since the instructions and the story may have changed, and use the new opened value.');
    }

    /** Whether the app's own writer is answering a message in this story right now. */
    private function writerBusy(string $storyId): ?array
    {
        $turn = $this->writer()->lastTurn($storyId, $this->key);
        return $turn !== null && in_array($turn['status'], ['queued','running','cancelling'], true) ? $turn : null;
    }

    private function listStories(): array
    {
        $items = array_map(fn(array $s) => ['id'=>$s['id'],'title'=>$s['title'],'kind'=>$s['kind'],'series'=>$s['series'] !== '' ? $s['series'] : null,'synopsis'=>$s['synopsis'],
            'chapters'=>$s['chapters'],'words'=>$s['words'],'illustrations'=>$s['images'],'cover_image_id'=>$s['cover'],'favorite'=>$s['favorite'],
            'updated'=>gmdate('c', $s['updated']),'app_writer_working'=>$this->writerBusy($s['id']) !== null], $this->stories()->all($this->key, $this->jobs));
        return ['content'=>[['type'=>'text','text'=>self::json(['stories'=>$items])]]];
    }

    private function createStory(array $args): array
    {
        $title = $args['title'] ?? null;
        if (!is_string($title)) throw new HttpError(422, 'title must be a string ("" for none yet).');
        $kind = $args['kind'] ?? 'story';
        if (!in_array($kind, Stories::KINDS, true)) throw new HttpError(422, 'kind must be story or vn.');
        $story = $this->stories()->create($title, $this->key, $kind);
        $fields = array_filter(['synopsis'=>self::text($args, 'synopsis', 6000), 'series'=>self::text($args, 'series', 120)], fn($v) => $v !== null);
        if ($fields) $story = $this->stories()->update($story['id'], $fields, $this->key);
        return ['content'=>[['type'=>'text','text'=>self::json(['story_id'=>$story['id'],'title'=>$story['title'],'note'=>'Created. Call open_story with this story_id before writing: it gives the writer\'s instructions and the opened value the story tools take.'])]]];
    }

    private function openStory(array $args): array
    {
        $id = $this->storyId($args);
        $this->resolve($id);
        $vn = $this->stories()->kind($id, $this->key) === 'vn';
        // The instructions always come with the opened value, so a client can't work on the story without them.
        $text = self::STORY_GUIDE."\n<app_writer_instructions>\n".Writer::system($vn)."\n</app_writer_instructions>\n\n";
        $text .= 'story_id: '.$id."\nopened: ".$this->opened($id)." (pass it with the story_id to every story tool for this story; it lasts a day)\n".$this->writer()->openState($id, $this->key);
        if (($turn = $this->writerBusy($id)) !== null)
            $text .= "\n\nThe app's own writer is answering a message in this story right now (".($turn['status'] === 'queued' ? 'about to start' : 'for '.Writer::duration(max(0, time() - (int)($turn['started'] ?? $turn['created'])))).'). '
                .'Changes made at the same time can cross: tell the person, and wait for it to finish (list_stories shows when) before changing the story.';
        return ['content'=>[['type'=>'text','text'=>$text]]];
    }

    /** Pictures that are ready take their places in the chapters, the cover, the sheets and the comic (the page does this when it opens the story). */
    private function resolve(string $storyId): void
    {
        try { $this->stories()->resolveIllustrations($storyId, $this->key, $this->jobs); }
        catch (\Throwable $e) { if (!$e instanceof HttpError) logFailure($e); }
    }

    /** One of the app's writer tools on a story. The pictures it asks for start at once, in a separate process when the host allows. */
    private function storyTool(string $name, array $args, ?\Closure $keepalive): array
    {
        $id = $this->storyId($args);
        $this->checkOpened($id, $args['opened'] ?? null);
        unset($args['story_id'], $args['opened']);
        $this->resolve($id);
        $writer = $this->writer();
        $c = new ToolContext($id, '', $writer->storySettings($id, $this->key), $this->key);
        $c->by = 'mcp';
        $c->tick = static function () use ($keepalive): bool { if ($keepalive !== null) $keepalive(); return false; };
        $separate = ($this->config['separate_process'] ?? false) && $this->api === null;
        // A wait for a picture ends at the deadline, before the host's own limit; PHP's limit is not the one that counts.
        if (in_array($name, self::STORY_WAITS, true) && function_exists('set_time_limit')) @set_time_limit(0);
        // A picture waited for is made in this request only where nothing else will make it and the host doesn't stop the request.
        [$ok, $out, ] = $writer->forRequest($this->deadline(), !$separate && Spawn::webLimit() === null)->withImageApi($this->api)->runTool($name, $args, $c);
        $content = self::content($out);
        if ($c->jobs && ($note = $this->startPictures($c->jobs, $keepalive)) !== null) $content[] = ['type'=>'text','text'=>$note];
        return ['content'=>$content] + ($ok ? [] : ['isError'=>true]);
    }

    /** When a request has to answer: before the web server stops it (Hostinger: 120 s after it started), else in 14 minutes. */
    private function deadline(): int
    {
        $limit = Spawn::webLimit();
        if ($limit === null) return time() + 840;
        return max(time() + 15, (int)($_SERVER['REQUEST_TIME'] ?? time()) + $limit - 25);
    }

    /**
     * Starts the pictures a story tool asked for. In a separate process when the host allows (Hostinger stops web
     * requests at 120 s); else here, one after another, where the host doesn't stop requests; else they wait for the
     * app, which starts them when it is open. Returns what the client should know, or null.
     */
    private function startPictures(array $ids, ?\Closure $keepalive): ?string
    {
        if (($this->config['separate_process'] ?? false) && $this->api === null && $this->jobs->startSeparately($ids, $this->key)) return null;
        if ($this->api === null && Spawn::webLimit() !== null)
            return 'This server could not start the picture on its own just now: it starts when the Atelier app is open on any device, or when a tool waits for it.';
        foreach ($ids as $jobId) {
            if (function_exists('set_time_limit')) @set_time_limit($this->config['api_timeout'] * 2 + 300);
            try { $this->jobs->run($jobId, $this->key, $this->api, $keepalive); } catch (\Throwable $e) { if (!$e instanceof HttpError) logFailure($e); }
        }
        return null;
    }

    /** A writer tool's result (text, or Claude's content blocks) as MCP content. */
    private static function content(string|array $out): array
    {
        if (is_string($out)) return [['type'=>'text','text'=>$out]];
        $content = [];
        foreach ($out as $block) {
            if (($block['type'] ?? '') === 'image' && isset($block['source']['data'])) $content[] = ['type'=>'image','data'=>$block['source']['data'],'mimeType'=>$block['source']['media_type']];
            elseif (isset($block['text'])) $content[] = ['type'=>'text','text'=>(string)$block['text']];
        }
        return $content ?: [['type'=>'text','text'=>'Done.']];
    }

    private static function json(array $data): string
    { return json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE); }

    private static function formatOf(string $mime): string { return ['image/jpeg'=>'jpeg','image/webp'=>'webp'][$mime] ?? 'png'; }

    private static function validSize(int $w, int $h, bool $gpu = false): bool
    {
        // The GPU's model brings its result to any size within its limits (validSize in assets/lineage.js).
        if ($gpu) return min($w, $h) >= 64 && max($w, $h) <= GpuImages::MAX_SIDE && max($w, $h) / min($w, $h) <= 5 && $w * $h <= GpuImages::MAX_PIXELS;
        if ($w % 16 || $h % 16 || $w <= 0 || $h <= 0) return false;
        return max($w, $h) <= self::MAX_SIDE && max($w, $h) / min($w, $h) <= 3 && $w * $h >= self::MIN_AREA && $w * $h <= self::MAX_AREA;
    }

    /** Same rule as editSize in assets/lineage.js: keep the image's proportions when the model allows. */
    public static function editSize(int $width, int $height, bool $flexible): string
    {
        if (!$width || !$height) return 'auto';
        $ratio = $width / $height;
        if (!$flexible) {
            $best = [1024, 1024];
            foreach ([[1024,1024],[1024,1536],[1536,1024]] as [$w, $h]) if (abs(log($w / $h / $ratio)) < abs(log($best[0] / $best[1] / $ratio))) $best = [$w, $h];
            return "{$best[0]}x{$best[1]}";
        }
        if (self::validSize($width, $height)) return "{$width}x{$height}";
        if ($ratio > 3 || $ratio < 1 / 3) return 'auto';
        $scale = 1.0; $area = $width * $height;
        if ($area > self::MAX_AREA) $scale = sqrt(self::MAX_AREA / $area);
        if ($area < self::MIN_AREA) $scale = sqrt(self::MIN_AREA / $area);
        if (max($width, $height) * $scale > self::MAX_SIDE) $scale = self::MAX_SIDE / max($width, $height);
        foreach (['round','floor','ceil'] as $round) {
            $w = (int)($round($width * $scale / 16) * 16); $h = (int)($round($height * $scale / 16) * 16);
            if (self::validSize($w, $h)) return "{$w}x{$h}";
        }
        return 'auto';
    }

    /**
     * Same rule as upscaleSizes in assets/lineage.js (with the GPU model's limits when $gpu).
     * @return array<string,array{0:int,1:int}> scale => [width, height], only sizes clearly larger than the image
     */
    public static function upscaleSizes(int $width, int $height, bool $gpu = false): array
    {
        $ratio = $gpu ? 5 : 3;
        if (!$width || !$height || $width / $height > $ratio || $height / $width > $ratio) return [];
        $limit = min(sqrt(($gpu ? GpuImages::MAX_PIXELS : self::MAX_AREA) / ($width * $height)), ($gpu ? GpuImages::MAX_SIDE : self::MAX_SIDE) / max($width, $height));
        $sizes = [];
        foreach (['1.5'=>1.5, '2'=>2.0, '3'=>3.0, 'max'=>$limit] as $label => $scale) {
            if ($scale > $limit + 1e-9) continue;
            $size = null;
            foreach (['round','floor'] as $round) {
                $w = (int)($round($width * $scale / 16) * 16); $h = (int)($round($height * $scale / 16) * 16);
                if (self::validSize($w, $h, $gpu)) { $size = [$w, $h]; break; }
            }
            if (!$size || $size[0] * $size[1] < $width * $height * 1.2 || in_array($size, $sizes, true)) continue;
            $sizes[(string)$label] = $size;
        }
        return $sizes;
    }
}
