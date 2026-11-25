<?php
declare(strict_types=1);
// The studio over MCP (app/McpStudio.php): Limpar, crop, expand, a painted area and Cobrir, Fotorrealista, Melhorar,
// Ampliar with extras, the library, the requests, the GPU and its videos, and the stories as a whole. No real API calls:
// the image API is a fake that records what it is sent.
require dirname(__DIR__).'/app/bootstrap.php';
use Studio\{Vault,OpenAI,Jobs,Users,Mcp,Clean,Presets,Gpu,Stories,HttpError};

$count = 0;
function check(bool $condition, string $message): void { global $count; if (!$condition) throw new RuntimeException($message); $count++; echo "PASS $message\n"; }
function png(GdImage $im): string { ob_start(); imagepng($im); return (string)ob_get_clean(); }
/** The alpha (0 opaque, 127 clear) of a pixel of a data URL's picture. */
function alphaAt(string $url, int $x, int $y): int { $im = imagecreatefromstring(base64_decode(substr($url, strpos($url, ',') + 1))); return (imagecolorat($im, $x, $y) >> 24) & 0x7F; }
function dims(string $url): array { $i = getimagesizefromstring(base64_decode(substr($url, strpos($url, ',') + 1))); return [$i[0], $i[1]]; }

$dir = sys_get_temp_dir().'/imagegen-mcp-tools-'.bin2hex(random_bytes(6));
$key = random_bytes(32);
try {
    $vault = new Vault($dir); $users = new Users($vault);
    $owner = $users->create('Pessoal', 'test-password-long-enough', $key, true); $vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests', $key);
    // A picture with specks on it, the size the edits keep (1024 × 768 is a size the image API makes).
    $im = imagecreatetruecolor(1024, 768); imagefill($im, 0, 0, imagecolorallocate($im, 150, 120, 100));
    mt_srand(7); for ($i = 0; $i < 3000; $i++) imagesetpixel($im, mt_rand(0, 1023), mt_rand(0, 767), imagecolorallocate($im, 250, 250, 250));
    imagefilledrectangle($im, 100, 100, 199, 149, imagecolorallocate($im, 20, 40, 200));
    $bytes = png($im); imagedestroy($im);
    $source = $vault->saveImage($bytes, ['title'=>'Base','prompt'=>'a wall','original_prompt'=>'a wall','options'=>OpenAI::options(['size'=>'1024x768']),'parent_ids'=>[]], $key);

    $calls = []; $result = $bytes;
    $api = new OpenAI('test', 60, function ($path, $body) use (&$calls, &$result) { $calls[] = [$path, $body]; return ['data'=>[['b64_json'=>base64_encode($result)]],'usage'=>['total_tokens'=>10]]; });
    $jobs = new Jobs($vault, ['api_timeout'=>60]);
    $mcp = new Mcp($vault, $jobs, $key, 'https://example.test/', ['api_timeout'=>60], $api);
    $rpc = fn(string $method, array $params = []) => $mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]);
    $tool = function (string $name, array $arguments) use ($rpc) {
        $r = $rpc('tools/call', ['name'=>$name, 'arguments'=>$arguments]);
        if (isset($r['error'])) throw new RuntimeException("$name: ".$r['error']['message']);
        $r['result']['data'] = json_decode($r['result']['content'][0]['text'], true);
        return $r['result'];
    };
    $failed = fn(array $r, string $text = '') => !empty($r['isError']) && ($text === '' || str_contains($r['content'][0]['text'], $text));
    $last = function () use (&$calls) { return end($calls)[1]; };

    $ping = $rpc('ping');
    check(($ping['result'] ?? null) instanceof stdClass && !isset($ping['error']), 'ping answers with an empty result (it used to end in an error)');

    // The list: the image tools first, as before, then the studio's, then the stories'.
    $tools = Mcp::tools(); $names = array_column($tools, 'name');
    check(array_slice($names, 0, 7) === ['generate_image','edit_image','upscale_image','list_images','get_image','import_image','check_requests'] && count($names) === count(array_unique($names)), 'the tools keep their order and every name is unique');
    foreach (['clean_image','crop_image','expand_image','photoreal_image','enhance_image','update_image','trash_images','delete_images','list_albums','create_album','update_album','delete_album',
        'stack_images','unstack_images','reorder_images','cancel_request','retry_request','gpu_status','stop_gpu','set_gpu_idle','list_loras','save_lora','delete_lora',
        'generate_video','improve_video_prompt','get_video','duplicate_story','favorite_story','delete_story'] as $name) check(in_array($name, $names, true), "MCP offers $name");
    check(array_search('delete_story', $names, true) < array_search('list_stories', $names, true), 'and the studio\'s tools come before the stories\'');
    check(in_array('minimax-h3-image', array_column($tools, 'inputSchema', 'name')['generate_image']['properties']['model']['enum'], true), 'the GPU model can be chosen');
    $long = fn(string $name, array $args = []) => Mcp::isLong(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]]);
    check($long('clean_image') && $long('expand_image') && $long('photoreal_image') && !$long('crop_image') && !$long('list_albums') && !$long('check_requests') && $long('check_requests', ['wait_seconds'=>30]), 'calls that wait are streamed, and check_requests only when it waits');
    $init = $rpc('initialize', ['protocolVersion'=>'2025-06-18'])['result'];
    check(str_contains($init['instructions'], 'clean_image') && str_contains($init['instructions'], 'gpu_status'), 'the instructions tell the client about Limpar and the GPU');

    // Limpar, as a request of its own: on the server, the page's filter, saved as a version; nothing is sent anywhere.
    $before = count($calls);
    $cleaned = $tool('clean_image', ['image_id'=>$source['id'], 'level'=>'medium']);
    $c = $cleaned['data']['requests'][0];
    $expected = Clean::file($bytes, 'media');
    check(empty($cleaned['isError']) && $c['status'] === 'completed' && count($c['images']) === 1 && count($calls) === $before, 'clean_image runs the cleanup without the image API');
    $item = $vault->image($c['images'][0]['id'], $key);
    check($item['operation'] === 'clean' && $item['clean'] === 'media' && $item['parent_id'] === $source['id'] && $item['made_on'] === 'server' && $item['job_id'] === $c['request_id'], 'the cleaned image is a version of the source, made on the server, at the level asked');
    check($vault->bytes($item['id'], 'full', $key) === $expected['bytes'], 'and it is exactly what the filter makes');
    $row = $tool('check_requests', ['request_ids'=>[$c['request_id']]])['data']['requests'];
    $row = array_values(array_filter($row, fn($r) => $r['request_id'] === $c['request_id']))[0];
    check($row['kind'] === 'clean' && $row['made_by'] === 'server' && $row['status'] === 'completed', 'check_requests shows it as a cleanup');
    check($failed($tool('clean_image', ['image_id'=>$source['id'], 'level'=>'extreme'])), 'an unknown level is refused');

    // Limpar antes de enviar: the image sent is cleaned, the library's stays, and the prompt says the softness is on purpose.
    $edited = $tool('edit_image', ['image_id'=>$source['id'], 'prompt'=>'Paint the wall green', 'clean_first'=>'forte']);
    $sent = $last();
    $strong = Clean::file($bytes, 'forte');
    check(empty($edited['isError']) && $sent['images'][0]['image_url'] === 'data:'.$strong['mime'].';base64,'.base64_encode($strong['bytes']), 'clean_first cleans the image right before it is sent');
    check(str_contains($sent['prompt'], 'blurred on purpose') && $vault->image($edited['data']['requests'][0]['images'][0]['id'], $key)['cleaned'] === 'forte' && $vault->bytes($source['id'], 'full', $key) === $bytes, 'the model is told, the version says so, and the library\'s image is untouched');
    check($failed($tool('edit_image', ['image_id'=>$source['id'], 'prompt'=>'x', 'clean_first'=>'media', 'areas'=>[['x'=>0,'y'=>0,'width'=>10,'height'=>10]]])), 'clean_first is refused with an area');

    // Recortar on the server.
    $crop = $tool('crop_image', ['image_id'=>$source['id'], 'x'=>100, 'y'=>100, 'width'=>100, 'height'=>50]);
    $cropped = $vault->image($crop['data']['image']['id'], $key);
    $part = imagecreatefromstring($vault->bytes($cropped['id'], 'full', $key));
    check($cropped['operation'] === 'crop' && $cropped['made_on'] === 'server' && imagesx($part) === 100 && imagesy($part) === 50 && (imagecolorat($part, 50, 25) & 0xFFFFFF) === 0x1428C8, 'crop_image keeps exactly the rectangle, as a version made on the server');
    $square = $tool('crop_image', ['image_id'=>$source['id'], 'aspect'=>'1:1', 'position'=>'left']);
    check($square['data']['crop'] === ['x'=>0,'y'=>0,'width'=>768,'height'=>768] && $square['data']['image']['width'] === 768, 'crop_image takes the largest crop of a shape, where asked');
    $half = $tool('crop_image', ['image_id'=>$source['id'], 'x'=>0.5, 'y'=>0, 'width'=>0.5, 'height'=>1, 'unit'=>'fraction']);
    check($half['data']['crop'] === ['x'=>512,'y'=>0,'width'=>512,'height'=>768], 'and fractions of the image');
    check($failed($tool('crop_image', ['image_id'=>$source['id'], 'x'=>0, 'y'=>0, 'width'=>1024, 'height'=>768])) && $failed($tool('crop_image', ['image_id'=>$source['id']])), 'a crop of the whole image, or of nothing, is refused');

    // Expandir: the picture with clear new areas, the mask over what is kept, sizes in multiples of 16.
    $expanded = $tool('expand_image', ['image_id'=>$source['id'], 'right'=>250, 'prompt'=>'a garden']);
    $sent = $last();
    check(empty($expanded['isError']) && $sent['size'] === '1280x768' && dims($sent['images'][0]['image_url']) === [1280, 768], 'expand_image rounds the new canvas to a multiple of 16');
    check(alphaAt($sent['images'][0]['image_url'], 1100, 300) === 127 && alphaAt($sent['images'][0]['image_url'], 500, 300) === 0 && alphaAt($sent['mask']['image_url'], 1100, 300) === 127 && alphaAt($sent['mask']['image_url'], 500, 300) === 0, 'the new area is clear in the picture and in the mask, the kept image opaque');
    check(str_starts_with($sent['prompt'], Presets::EXPAND.' Instructions for the new areas: a garden') && $vault->image($expanded['data']['requests'][0]['images'][0]['id'], $key)['operation'] === 'expand', 'with the page\'s instruction, saved as an expansion');
    $wide = $tool('expand_image', ['image_id'=>$source['id'], 'aspect'=>'16:9']);
    check($last()['size'] === '1360x768' && alphaAt($last()['images'][0]['image_url'], 10, 300) === 127 && alphaAt($last()['images'][0]['image_url'], 168, 300) === 0 && alphaAt($last()['images'][0]['image_url'], 167, 300) === 127, 'an aspect ratio grows the canvas around the image as the page\'s proportions do, with the image in the middle');
    $tool('expand_image', ['image_id'=>$source['id'], 'aspect'=>'4:3', 'position'=>'bottom-right']);
    check($last()['size'] === '1280x960' && alphaAt($last()['images'][0]['image_url'], 255, 191) === 127 && alphaAt($last()['images'][0]['image_url'], 256, 192) === 0, 'the image\'s own shape makes it a quarter larger, and position places the image');
    $both = $tool('expand_image', ['image_id'=>$source['id'], 'left'=>-100, 'top'=>200]);
    check($last()['size'] === '912x976' && str_starts_with($vault->image($both['data']['requests'][0]['images'][0]['id'], $key)['label'], 'Crop and expansion'), 'a negative margin crops that side while the other grows');
    $before = count($calls);
    $onlyCrop = $tool('expand_image', ['image_id'=>$source['id'], 'left'=>-24, 'bottom'=>-8]);
    check(count($calls) === $before && $onlyCrop['data']['image']['width'] === 1000 && $onlyCrop['data']['image']['height'] === 760, 'margins that only cut make a crop, with no API');
    check($failed($tool('expand_image', ['image_id'=>$source['id'], 'right'=>4000]), '3,840'), 'a canvas too large for the API is refused with the page\'s reason');

    // The brush: a marked area, redone or covered.
    $redo = $tool('edit_image', ['image_id'=>$source['id'], 'areas'=>[['x'=>100,'y'=>100,'width'=>100,'height'=>50]]]);
    $sent = $last();
    check(empty($redo['isError']) && str_starts_with($sent['prompt'], Presets::INPAINT), 'an area without a prompt is redone with the page\'s instruction');
    check(alphaAt($sent['mask']['image_url'], 150, 120) === 127 && alphaAt($sent['mask']['image_url'], 50, 50) === 0 && alphaAt($sent['images'][0]['image_url'], 150, 120) === 0, 'the mask is clear on the area and the image goes as it is');
    $fill = $tool('edit_image', ['image_id'=>$source['id'], 'areas'=>[['x'=>0.4,'y'=>0.4,'width'=>0.2,'height'=>0.2,'shape'=>'ellipse']], 'unit'=>'fraction', 'cover'=>true]);
    $sent = $last();
    check(str_starts_with($sent['prompt'], Presets::FILL) && alphaAt($sent['images'][0]['image_url'], 512, 384) === 127 && alphaAt($sent['images'][0]['image_url'], 420, 320) === 0 && alphaAt($sent['mask']['image_url'], 512, 384) === 127, 'cover erases the area (an ellipse) before sending and fills it from around it');
    $covered = $tool('edit_image', ['image_id'=>$source['id'], 'areas'=>[['x'=>100,'y'=>100,'width'=>100,'height'=>50]], 'cover'=>true, 'prompt'=>'a window']);
    $saved = $vault->image($covered['data']['requests'][0]['images'][0]['id'], $key);
    check(str_starts_with($last()['prompt'], Presets::COVER_NOTE.'a window') && $saved['label'] === 'Covered area: a window', 'cover with a prompt draws what it asks in the erased area');
    $m = imagecreatetruecolor(512, 384); imagefill($m, 0, 0, imagecolorallocate($m, 0, 0, 0)); imagefilledrectangle($m, 256, 0, 511, 383, imagecolorallocate($m, 255, 255, 255));
    $masked = $tool('edit_image', ['image_id'=>$source['id'], 'prompt'=>'make it night', 'mask'=>'data:image/png;base64,'.base64_encode(png($m))]);
    check(alphaAt($last()['mask']['image_url'], 900, 300) === 127 && alphaAt($last()['mask']['image_url'], 100, 300) === 0 && dims($last()['mask']['image_url']) === [1024, 768], 'a white-on-black mask of another size marks its area on the image');
    check($failed($tool('edit_image', ['image_id'=>$source['id']])) && $failed($tool('edit_image', ['image_id'=>$source['id'], 'prompt'=>'x', 'cover'=>true])), 'an edit needs a prompt or an area, and cover needs an area');

    // Fotorrealista, Melhorar and Ampliar with extras.
    $photo = $tool('photoreal_image', ['image_id'=>$source['id'], 'notes'=>'keep the blue square']);
    check(str_starts_with($last()['prompt'], Presets::withNotes(Presets::PHOTOREAL, 'keep the blue square')) && str_starts_with($vault->image($photo['data']['requests'][0]['images'][0]['id'], $key)['label'], 'Photoreal'), 'photoreal_image sends the page\'s Fotorrealista with the notes first');
    $enh = $tool('enhance_image', ['image_id'=>$source['id']]);
    check(str_starts_with($last()['prompt'], Presets::ENHANCE) && $last()['size'] === '1024x768', 'enhance_image sends Melhorar in the image\'s own size');
    $result = $bytes;
    $up = $tool('upscale_image', ['image_id'=>$source['id'], 'scale'=>'2', 'with'=>'photoreal']);
    check(str_starts_with($last()['prompt'], Presets::upscale('', 'photoreal')) && $last()['size'] === '2048x1536' && str_contains($vault->image($up['data']['requests'][0]['images'][0]['id'], $key)['label'], 'photoreal'), 'upscale_image can make it Fotorrealista at the larger size');
    check(Mcp::upscaleSizes(1024, 768, true)['max'] === [7296, 5472] && Mcp::upscaleSizes(3840, 2160) === [] && isset(Mcp::upscaleSizes(3840, 2160, true)['2']), 'the GPU model upscales further than the API');

    // The GPU: requests wait for its notebook, which can't be turned on from here.
    $gpu = new Gpu($vault, []); $gpu->issueToken($owner['id'], $key);
    $lora = $tool('save_lora', ['url'=>'https://huggingface.co/someone/style/resolve/main/style.safetensors', 'name'=>'Style', 'trigger'=>'stylized', 'strength'=>0.8]);
    $loraId = $lora['data']['lora']['id'];
    check($lora['data']['lora']['strength'] == 0.8 && $tool('list_loras', [])['data']['loras'][0]['id'] === $loraId, 'LoRAs are saved and listed');
    $before = count($calls);
    $onGpu = $tool('generate_image', ['prompt'=>'stylized city', 'model'=>'minimax-h3-image', 'size'=>'1600x900', 'loras'=>[['lora_id'=>$loraId]], 'seed'=>42]);
    $r = $onGpu['data']['requests'][0];
    $job = $jobs->freshJob($r['request_id'], $key);
    check(empty($onGpu['isError']) && $r['status'] === 'queued' && $r['made_by'] === 'gpu' && str_contains($r['note'], 'Waiting for the GPU') && count($calls) === $before, 'a picture on the GPU model waits in line while the notebook is off, and is not an error');
    check($job['runner'] === 'gpu' && $job['options']['seed'] === 42 && $job['options']['loras'][0]['strength'] == 0.8 && $job['options']['loras'][0]['trigger'] === 'stylized', 'with its seed and LoRAs, at the strength saved with them');
    check($failed($tool('generate_image', ['prompt'=>'x', 'loras'=>[['lora_id'=>$loraId]]]), 'GPU model') && $failed($tool('generate_image', ['prompt'=>'x', 'model'=>'minimax-h3-image', 'loras'=>[['lora_id'=>'0123456789abcdef']]])), 'LoRAs are only for the GPU model, and must be saved ones');
    $tool('edit_image', ['image_id'=>$source['id'], 'prompt'=>'night', 'model'=>'minimax-h3-image']);
    check($jobs->list($key, 'recent')[0]['options']['size'] === 'auto', 'an edit on the GPU model keeps the image\'s own size');
    check($failed($tool('photoreal_image', ['image_id'=>$source['id'], 'model'=>'minimax-h3-image', 'clean_first'=>'media'])), 'clean_first is refused on the GPU model');
    $state = $tool('gpu_status', [])['data'];
    check($state['on'] === false && $state['queued'] >= 2 && $state['token_made'] === true && str_contains($state['note'], 'Colab'), 'gpu_status says the GPU is off and what waits for it');
    check($tool('set_gpu_idle', ['minutes'=>30])['data']['idle_minutes'] === 30 && $gpu->status()['idle_minutes'] === 30 && $failed($tool('set_gpu_idle', ['minutes'=>7])), 'the idle minutes can be set to the page\'s choices');
    check($tool('stop_gpu', [])['data']['asked'] === true, 'the GPU can be asked to turn off');
    $cancelled = $tool('cancel_request', ['request_id'=>$r['request_id']]);
    check($cancelled['data']['request']['status'] === 'cancelled', 'a request waiting for the GPU can be cancelled');
    check(count($tool('delete_lora', ['lora_id'=>$loraId])['data']['loras']) === 0, 'and a LoRA removed');

    // Videos: asked for from MCP, made by the GPU; a library video can be downloaded in ranges.
    $sound = 'data:audio/mpeg;base64,'.base64_encode("ID3\x03\x00\x00\x00".str_repeat("\x00", 64));
    $video = $tool('generate_video', ['prompt'=>'<Picture 1> walks to the sea', 'reference_image_ids'=>[$source['id']], 'keyframes'=>[['image_id'=>$source['id'], 'at'=>'end']], 'sounds'=>[['data'=>$sound, 'name'=>'waves', 'at'=>0]], 'seconds'=>6, 'aspect'=>'9:16']);
    $vr = $video['data']['request'];
    $vjob = $jobs->freshJob($vr['request_id'], $key);
    check(empty($video['isError']) && $vr['kind'] === 'video' && $vr['status'] === 'queued' && $video['data']['gpu_on'] === false && $vjob['options']['seconds'] == 6 && $vjob['options']['aspect'] === '9:16', 'generate_video asks the GPU for a video with its options');
    check(array_map(fn($i) => [$i['kind'], $i['role']], $vjob['inputs']) === [['image','ref'],['image','key'],['audio','key']] && $vjob['inputs'][1]['at'] === 'end', 'with its pictures, the pinned one and the sound in place');
    check($failed($tool('generate_video', ['prompt'=>'x', 'operation'=>'continue'])) && $failed($tool('generate_video', ['prompt'=>'x', 'seconds'=>40])), 'a video request the window would refuse is refused');
    $clip = file_get_contents(__DIR__.'/fixtures/frames.mp4');
    $upload = $vault->startUpload(strlen($clip), 10 * Vault::VIDEO_CHUNK); $vault->appendUpload($upload, 0, $clip, $key);
    $poster = imagecreatetruecolor(64, 36); ob_start(); imagejpeg($poster); $posterBytes = (string)ob_get_clean();
    $clipItem = $vault->finishUpload($upload, ['name'=>'clip.mp4','duration'=>2,'width'=>320,'height'=>180,'fps'=>12,'frames'=>24], 'data:image/jpeg;base64,'.base64_encode($posterBytes), $key);
    $got = $tool('get_video', ['video_id'=>$clipItem['id']]);
    check($got['data']['kind'] === 'video' && $got['data']['duration'] == 2 && $got['data']['size_bytes'] === strlen($clip) && $got['content'][1]['type'] === 'image', 'get_video gives the video\'s details and a still');
    [$linked, $linkKey] = $vault->linkKey(substr($got['data']['download_url'], strpos($got['data']['download_url'], '=') + 1));
    $streamed = ''; $vault->streamVideo($vault->video($linked['id'], $linkKey), 0, strlen($clip) - 1, $linkKey, function ($b) use (&$streamed) { $streamed .= $b; return true; });
    check($linked['id'] === $clipItem['id'] && $streamed === $clip, 'and its download link opens the video itself');
    $continued = $tool('generate_video', ['prompt'=>'it goes on', 'source_video_id'=>$clipItem['id'], 'operation'=>'continue', 'join'=>false]);
    $cjob = $jobs->freshJob($continued['data']['request']['request_id'], $key);
    check($cjob['options']['mode'] === 'video' && $cjob['options']['op'] === 'continue' && $cjob['options']['join'] === false, 'a video can continue one of the library');
    check($failed($tool('get_image', ['image_id'=>$clipItem['id']])) && !in_array($clipItem['id'], array_column($tool('list_images', [])['data']['images'], 'id'), true), 'the image tools still leave videos out');
    check(in_array($clipItem['id'], array_column($tool('list_images', ['kind'=>'video'])['data']['images'], 'id'), true), 'and list_images finds them when asked');

    // The library: albums, favorites, hidden items, covers, stacks, the trash.
    $album = $tool('create_album', ['name'=>'Paredes', 'image_ids'=>[$source['id'], $cropped['id']]])['data'];
    check($album['count'] === 2 && $tool('list_albums', [])['data']['albums'][0]['name'] === 'Paredes', 'create_album makes an album with its items');
    $inAlbum = $tool('list_images', ['album_id'=>$album['album_id']])['data'];
    check($inAlbum['total'] === 2, 'list_images lists an album');
    $changed = $tool('update_album', ['album_id'=>$album['album_id'], 'name'=>'Muros', 'remove_image_ids'=>[$cropped['id']], 'show_in_library'=>false])['data'];
    check($changed['removed'] === 1 && $changed['show_in_library'] === false && $vault->albums($key)[0]['name'] === 'Muros' && $vault->albums($key)[0]['library'] === false, 'update_album renames it, takes items out and keeps its items out of the library');
    $tagged = $tool('generate_image', ['prompt'=>'For the album', 'album_id'=>$album['album_id']]);
    check($tool('list_images', ['album_id'=>$album['album_id']])['data']['total'] === 2, 'new images can go straight into an album');
    check($failed($tool('generate_image', ['prompt'=>'x', 'album_id'=>str_repeat('a', 32)]), 'album'), 'an album that doesn\'t exist is refused');
    $u = $tool('update_image', ['image_id'=>$cropped['id'], 'title'=>'Quadrado azul', 'favorite'=>true, 'hidden'=>true])['data'];
    check($u['title'] === 'Quadrado azul' && $u['favorite'] === true && $u['hidden'] === true, 'update_image renames, marks as favorite and hides');
    check($tool('list_images', ['hidden'=>'only'])['data']['images'][0]['id'] === $cropped['id'] && !in_array($cropped['id'], array_column($tool('list_images', ['hidden'=>'exclude', 'limit'=>50])['data']['images'], 'id'), true), 'list_images shows the hidden items apart');
    $tool('update_image', ['image_id'=>$cropped['id'], 'hidden'=>false, 'cover'=>true]);
    $grouped = $tool('list_images', ['group'=>true, 'limit'=>50])['data']['images'];
    check(in_array($cropped['id'], array_column($grouped, 'id'), true) && !in_array($source['id'], array_column($grouped, 'id'), true), 'a version chosen as cover stands for its image when grouped');
    $other = $tool('import_image', ['data'=>base64_encode($bytes), 'name'=>'other.png'])['data'];
    $stack = $tool('stack_images', ['image_ids'=>[$source['id'], $other['id']]])['data'];
    check($stack['count'] >= 2 && $tool('unstack_images', ['image_ids'=>[$other['id']]])['data']['count'] >= 1, 'items can be stacked and taken out again');
    $tool('reorder_images', ['image_ids'=>[$other['id']]]);
    check(end($tool('list_images', ['sort'=>'custom', 'limit'=>50])['data']['images'])['id'] === $other['id'], 'and moved in the custom order');
    check($failed($tool('delete_images', ['image_ids'=>[$other['id']]])) === false && $vault->image($other['id'], $key)['deleted'] === null, 'delete_images leaves what is not in the trash');
    check($tool('trash_images', ['image_ids'=>[$other['id']]])['data']['count'] === 1 && $tool('list_images', ['trash'=>true])['data']['images'][0]['id'] === $other['id'], 'trash_images moves items to the trash');
    check($tool('trash_images', ['image_ids'=>[$other['id']], 'restore'=>true])['data']['count'] === 1 && $tool('trash_images', ['image_ids'=>[$other['id']]])['data']['count'] === 1, 'and takes them back out');
    check($tool('delete_images', ['image_ids'=>[$other['id']]])['data']['deleted'] === 1 && $tool('list_images', ['trash'=>true])['data']['total'] === 0, 'delete_images deletes what is in the trash, for good');
    check(str_contains($tool('delete_album', ['album_id'=>$album['album_id']])['data']['note'], 'stay') && $vault->albums($key) === [] && $vault->image($source['id'], $key)['id'] === $source['id'], 'delete_album leaves its items');

    // Requests: repeat one.
    $again = $tool('retry_request', ['request_id'=>$photo['data']['requests'][0]['request_id']]);
    check(empty($again['isError']) && $again['data']['requests'][0]['status'] === 'completed' && str_starts_with($last()['prompt'], Presets::withNotes(Presets::PHOTOREAL, 'keep the blue square')), 'retry_request makes a request again, as it was');
    $cleanAgain = $tool('retry_request', ['request_id'=>$c['request_id']]);
    check($vault->image($cleanAgain['data']['requests'][0]['images'][0]['id'], $key)['operation'] === 'clean', 'a cleanup can be repeated too');

    // Stories as a whole.
    $stories = new Stories($vault);
    $story = $stories->create('A casa', $key);
    $copy = $tool('duplicate_story', ['story_id'=>$story['id'], 'title'=>'A casa 2'])['data'];
    check($copy['title'] === 'A casa 2' && $stories->exists($copy['story_id']), 'duplicate_story copies a story');
    check($tool('favorite_story', ['story_id'=>$story['id'], 'favorite'=>true])['data']['favorite'] === true, 'favorite_story marks it');
    check($failed($tool('delete_story', ['story_id'=>$copy['story_id'], 'confirm_title'=>'Outra'])) && $stories->exists($copy['story_id']), 'delete_story needs the exact title');
    $tool('delete_story', ['story_id'=>$copy['story_id'], 'confirm_title'=>'A casa 2']);
    check(!$stories->exists($copy['story_id']) && $stories->exists($story['id']), 'and then deletes it');
} finally {
    if (is_dir($dir)) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); rmdir($dir); }
}
echo "\n$count checks passed. No real API calls.\n";
