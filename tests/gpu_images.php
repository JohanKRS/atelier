<?php
declare(strict_types=1);
// Pictures on the GPU (MiniMax H3-Image through the Colab worker): the model among the image models, its options, the
// LoRA list, how a request waits for the worker, what the worker gets, and the picture it sends back becoming a library
// image like those of the image APIs.
require dirname(__DIR__).'/app/bootstrap.php';
use Studio\{Vault,Jobs,Users,HttpError,Gpu,GpuImages,Images};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function rejects(callable $fn,string $message,?int $status=null): void {
    try{$fn();}catch(Throwable $e){ if($status!==null && (!$e instanceof HttpError || $e->status!==$status)) throw new RuntimeException($message.' (got '.get_class($e).': '.$e->getMessage().')'); check(true,$message);return;}
    throw new RuntimeException($message);
}
function png(int $w,int $h,array $rgb=[200,90,40],bool $hole=false): string {
    $im=imagecreatetruecolor($w,$h); imagesavealpha($im,true); imagealphablending($im,false);
    imagefill($im,0,0,imagecolorallocate($im,...$rgb));
    if($hole) imagefilledrectangle($im,0,0,intdiv($w,2),intdiv($h,2),imagecolorallocatealpha($im,0,0,0,127));
    ob_start(); imagepng($im); imagedestroy($im); return (string)ob_get_clean();
}
function dataUrl(int $w,int $h,bool $hole=false): string { return 'data:image/png;base64,'.base64_encode(png($w,$h,[200,90,40],$hole)); }
function id(): string { return bin2hex(random_bytes(16)); }
const MODEL='minimax-h3-image';

$dir=sys_get_temp_dir().'/atelier-gpu-images-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>4,'max_video_mb'=>50]);
    $gpu=new Gpu($vault);

    // ---------------------------------------------------------------------------------------------------------------
    // The model and its options
    $models=Images::models();
    check(($models[MODEL]['provider']??null)==='gpu'&&$models[MODEL]['qualities']===['low'=>4,'medium'=>8,'high'=>16]&&$models[MODEL]['flex']===true,'H3-Image is among the image models, from the GPU, with its megapixels per quality');
    check(array_key_last($models)===MODEL&&Images::provider(MODEL)==='gpu'&&Images::maxImages(MODEL)===16,'it comes last, and reads up to 16 pictures');
    $o=Images::options(['model'=>MODEL,'quality'=>'max','size'=>'1536x1024','thinking'=>'medium','previews'=>2,'n'=>3]);
    check($o['quality']==='high'&&$o['previews']===0&&$o['engine']==='h3-image'&&$o['loras']===[]&&$o['seed']===null&&$o['n']===3,'a quality it lacks becomes its highest; no previews');
    check(GpuImages::megapixels($o)===16.0&&GpuImages::megapixels(['model'=>MODEL,'quality'=>'low','size'=>'1024x1024'])===4.0,'the quality sets the megapixels');
    check(GpuImages::megapixels(['model'=>MODEL,'quality'=>'low','size'=>'3072x2048'])===6.0&&GpuImages::megapixels(['model'=>MODEL,'quality'=>'high','size'=>'3840x2160'])===16.0,'a bigger size asked for (Ampliar) raises them');
    rejects(fn()=>Images::options(['model'=>MODEL,'background'=>'transparent']),'no transparent background',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'loras'=>[['id'=>'nothex']]]),'a LoRA id is checked',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'loras'=>[['id'=>str_repeat('a',16),'strength'=>3]]]),'a LoRA strength is checked',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'loras'=>array_map(fn($n)=>['id'=>str_repeat((string)$n,16)],range(1,5))]),'up to 4 LoRAs',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'seed'=>-1]),'a seed is checked',422);
    // Its own sizes: whole pixels from 64 to 12,000 a side, up to 40 MP and 5:1, where the APIs need multiples of 16.
    check(Images::options(['model'=>MODEL,'size'=>'1001x777'])['size']==='1001x777'&&Images::options(['model'=>MODEL,'size'=>'9000x4000'])['size']==='9000x4000','any whole size it can draw in, beyond what the APIs take');
    check(Images::options(['model'=>MODEL,'size'=>'500x100'])['size']==='500x100'&&Images::options(['model'=>MODEL])['size']==='auto','a 5:1 ratio, and Auto');
    rejects(fn()=>Images::options(['model'=>MODEL,'size'=>'12001x6000']),'up to 12,000 px a side',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'size'=>'8000x6000']),'up to 40 MP',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'size'=>'601x100']),'up to 5:1',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'size'=>'63x63']),'at least 64 px a side',422);
    rejects(fn()=>Images::options(['model'=>MODEL,'size'=>'big']),'a size is checked',422);

    // The prompt the worker gets
    check(GpuImages::mentions('@1 hugs @2 near @3, mail@1x.com',2)==='<Picture 1> hugs <Picture 2> near @3, mail@1x.com','@1 and @2 become the pictures the model sees; others stay as written');
    $p=GpuImages::prompt(['prompt'=>'Make @1 wear a hat','references'=>['a'],'operation'=>'edit','mask'=>null]);
    check(str_starts_with($p,'<Picture 1> is the input image')&&str_contains($p,'Make <Picture 1> wear a hat')&&str_contains($p,'Skin tone'),'an edit says which picture is changed and keeps the fidelity rules');
    $p=GpuImages::prompt(['prompt'=>'a door','references'=>['a','b'],'operation'=>'edit','mask'=>'m']);
    check(str_starts_with($p,'About the pictures: the last one (<Picture 3>)')&&str_contains($p,'flat magenta'),'a painted area is explained by the marked copy, the last picture');
    $p=GpuImages::prompt(['prompt'=>'more sky','references'=>['a'],'operation'=>'expand','mask'=>'m']);
    check(str_contains($p,'flat gray: it is empty canvas'),'an expansion says the new canvas is gray');
    $p=GpuImages::prompt(['prompt'=>'a cat','references'=>[],'operation'=>'generate','variation'=>['index'=>2,'count'=>3,'direction'=>'Direction for this image: at night.']]);
    check(str_contains($p,'Direction for this image: at night.')&&str_contains($p,'one image of a set of 3'),'the direction of a varied image and that it is one of a set');
    // What the prompt expander gets: the request as typed, with the same notices around its caption.
    $plan=GpuImages::planParts(['prompt'=>'Make @1 wear a hat','references'=>['a'],'operation'=>'edit','mask'=>'m']);
    check(str_starts_with($plan['typed'],'About the pictures: the last one (<Picture 2>)')&&str_ends_with($plan['typed'],'Make <Picture 1> wear a hat'),'the expander reads the request with the pictures named, and the note on the marked copy');
    check($plan['before'].'Make <Picture 1> wear a hat'.$plan['after']===GpuImages::prompt(['prompt'=>'Make <Picture 1> wear a hat','references'=>['a'],'operation'=>'edit','mask'=>'m']),'its caption goes where the prompt would, with the same notices around it');

    // ---------------------------------------------------------------------------------------------------------------
    // LoRAs
    check(Gpu::loraUrl('https://huggingface.co/akatz-ai/MiniMax-H3-Image-Character-Swap-LoRA/blob/main/h3_image_character_swap_v1.safetensors')
        ==='https://huggingface.co/akatz-ai/MiniMax-H3-Image-Character-Swap-LoRA/resolve/main/h3_image_character_swap_v1.safetensors','a Hugging Face file page becomes its download link');
    check(Gpu::loraUrl('https://civitai.com/models/12345/some-style?modelVersionId=67890')==='https://civitai.com/api/download/models/67890','a Civitai page with its version becomes the version\'s download');
    check(Gpu::loraUrl('https://civitai.com/api/download/models/67890?type=Model&format=SafeTensor')==='https://civitai.com/api/download/models/67890','a Civitai download link is kept');
    check(Gpu::loraUrl('https://example.org/files/style.safetensors')==='https://example.org/files/style.safetensors','another https link is kept');
    rejects(fn()=>Gpu::loraUrl('http://example.org/a.safetensors'),'only https',422);
    rejects(fn()=>Gpu::loraUrl('https://civitai.com/models/12345'),'a Civitai page needs its version',422);
    rejects(fn()=>Gpu::loraUrl('https://huggingface.co/someone/repo'),'a Hugging Face repo is not a file',422);
    check($gpu->loras($key)===[],'no LoRAs at first');
    $list=$gpu->saveLora(['url'=>'https://huggingface.co/a/b/resolve/main/watercolor_style-v2.safetensors','trigger'=>'wtrclr','strength'=>0.8],$key);
    check(count($list)===1&&$list[0]['name']==='watercolor style v2'&&$list[0]['strength']===0.8&&$list[0]['engine']==='h3-image'&&preg_match('/\A[a-f0-9]{16}\z/',$list[0]['id']),'a LoRA is added, named after its file');
    $style=$list[0]['id'];
    $list=$gpu->saveLora(['id'=>$style,'name'=>'Aquarela','url'=>$list[0]['url'],'trigger'=>'wtrclr','strength'=>0.7],$key);
    check(count($list)===1&&$list[0]['name']==='Aquarela'&&$list[0]['strength']===0.7,'and changed');
    $list=$gpu->saveLora(['name'=>'Swap','url'=>'https://huggingface.co/akatz-ai/x/resolve/main/swap.safetensors'],$key);
    $swap=$list[1]['id'];
    rejects(fn()=>$gpu->saveLora(['id'=>str_repeat('0',16),'url'=>'https://a.b/c.safetensors'],$key),'changing one that is gone',404);
    $row=$vault->db->query('SELECT loras FROM gpu_workers')->fetchColumn();
    check(is_string($row)&&!str_contains($row,'huggingface'),'the list is kept sealed');

    // ---------------------------------------------------------------------------------------------------------------
    // A request waits for the GPU
    rejects(fn()=>$jobs->create(['id'=>id(),'prompt'=>'x','options'=>['model'=>MODEL]],$key),'without the notebook\'s token, nothing would make it',422);
    $gpu->issueToken($owner['id'],$key);
    $source=$vault->saveImage(png(640,480),['title'=>'Gato','prompt'=>'a cat','options'=>['model'=>'gpt-image-2'],'parent_ids'=>[],'operation'=>'generate'],$key);
    $id=id();
    $made=$jobs->create(['id'=>$id,'prompt'=>'Give @1 a red hat, in the style of @2','parent_id'=>$source['id'],'references'=>[['id'=>$source['id']],['data'=>dataUrl(300,300)]],
        'options'=>['model'=>MODEL,'quality'=>'medium','size'=>'1536x1152','thinking'=>'low','loras'=>[['id'=>$style,'strength'=>0.6],['id'=>$swap]]],'typed'=>'chapéu'],$key);
    check(count($made)===1&&$made[0]['status']==='queued'&&$made[0]['runner']==='gpu'&&$made[0]['kind']==='image'&&$made[0]['operation']==='edit','an edit with H3-Image waits in line for the GPU, as a picture');
    check(array_column($made[0]['options']['loras'],'name')===['Aquarela','Swap']&&$made[0]['options']['loras'][0]['strength']===0.6&&(float)$made[0]['options']['loras'][1]['strength']===1.0&&$made[0]['options']['loras'][0]['trigger']==='wtrclr','the LoRAs go with it, with their strengths and trigger words');
    rejects(fn()=>$jobs->create(['id'=>id(),'prompt'=>'x','options'=>['model'=>MODEL,'loras'=>[['id'=>str_repeat('9',16)]]]],$key),'a LoRA not in the list is refused',422);
    check($jobs->run($id,$key)['status']==='queued','this server never runs it itself');
    check($jobs->queuedEngines($key)===['h3-image'],'the worker learns which models the queue needs');
    check($jobs->claimGpu(['h3'],$key)===null,'a worker with only the video model leaves it');
    $claimed=$jobs->claimGpu(['h3','h3-image'],$key);
    check($claimed['id']===$id&&$claimed['kind']==='image'&&$claimed['operation']==='edit'&&$claimed['images']===2&&$claimed['mask']===false,'the worker takes it, with how many pictures to fetch');
    check($claimed['options']['megapixels']===8.0&&$claimed['options']['thinking']==='low'&&count($claimed['options']['loras'])===2&&str_starts_with($claimed['options']['loras'][0]['url'],'https://huggingface.co/'),'with the megapixels, the planning and the LoRAs\' links');
    check(str_contains($claimed['prompt'],'Give <Picture 1> a red hat, in the style of <Picture 2>')&&str_contains($claimed['prompt'],'Skin tone'),'and the prompt as the model reads it');
    $first=$jobs->inputPicture($id,'0',$key); $second=$jobs->inputPicture($id,'1',$key);
    check($first['mime']==='image/png'&&getimagesizefromstring($first['bytes'])[0]===640&&getimagesizefromstring($second['bytes'])[0]===300,'it downloads each picture whole, in order');
    rejects(fn()=>$jobs->inputPicture($id,'2',$key),'only the pictures it has',404);
    rejects(fn()=>$jobs->inputPicture($id,'mask',$key),'no mask when no area was painted',404);
    check($jobs->videoProgress($id,['phase'=>'generating','done'=>3,'total'=>6,'message'=>'Making the picture'],$key)===false,'it reports its steps');
    $listed=$jobs->list($key,'active',[$id])[0] ?? null;
    check(($listed['progress']['done']??null)===3&&$listed['phase']==='generating','the page sees them');

    // The picture comes back
    $result=png(1536,1152,[20,120,200]);
    $upload=$jobs->startVideoUpload($id,strlen($result),0,$key);
    $vault->appendUpload($upload,0,$result,$key);
    $done=$jobs->finishVideo($id,$upload,['seed'=>42,'took'=>37,'gpu'=>'NVIDIA A100-SXM4-80GB','planned_prompt'=>'A tabby cat wearing a red felt hat, watercolor.'],'',$key);
    check($done['status']==='completed'&&count($done['image_ids'])===1&&$done['made']['seed']===42&&$done['made']['seconds']===37,'the picture finishes the request');
    $image=$vault->image($done['image_ids'][0],$key);
    check($image['width']===1536&&$image['parent_id']===$source['id']&&$image['operation']==='edit'&&($image['kind']??'image')!=='video','it is a new version of the image it changed');
    check($image['planned_prompt']==='A tabby cat wearing a red felt hat, watercolor.'&&$image['options']['seed']===42&&$image['options']['model']===MODEL&&$image['typed']==='chapéu','with the expanded prompt, the seed, the options and what was typed');
    check(count($image['options']['loras'])===2&&$image['engine']==='h3-image'&&$image['made']['gpu']==='NVIDIA A100-SXM4-80GB','and the LoRAs and the GPU that made it');
    check($jobs->sentPrompt($id,$key)===$claimed['prompt'],'the details show the prompt as it went to the GPU');
    check($jobs->finishVideo($id,$upload,[],'',$key)['image_ids']===$done['image_ids'],'repeating the call after a lost answer changes nothing');
    check((int)$vault->db->query('SELECT COUNT(*) FROM uploads')->fetchColumn()===0,'the upload is gone once saved');

    // Repeating it keeps its LoRAs, even one removed from the list since.
    $gpu->deleteLora($swap,$key);
    $again=$jobs->retry(id(),$id,null,$key)[0];
    check($again['runner']==='gpu'&&$again['kind']==='image'&&array_column($again['options']['loras'],'name')===['Aquarela','Swap'],'making it again keeps its LoRAs');
    $jobs->cancel($again['id'],$key);

    // A painted area, an expansion, a failure
    $id=id();
    $jobs->create(['id'=>$id,'prompt'=>'Expand the image by filling only the transparent areas.','operation'=>'expand','parent_id'=>$source['id'],'references'=>[['data'=>dataUrl(1024,768,true),'source_id'=>$source['id']]],'mask'=>dataUrl(1024,768,true),
        'options'=>['model'=>MODEL,'quality'=>'low','size'=>'1024x768','thinking'=>'off']],$key);
    $claimed=$jobs->claimGpu([],$key);
    check($claimed['operation']==='expand'&&$claimed['mask']===true&&$claimed['images']===1&&str_contains($claimed['prompt'],'(<Picture 2>)'),'an expansion brings its mask, and the prompt names the marked copy the worker adds');
    check(getimagesizefromstring($jobs->inputPicture($id,'mask',$key)['bytes'])[0]===1024,'the worker downloads the mask');
    $failed=$jobs->failVideo($id,'CUDA out of memory',false,$key);
    check($failed['status']==='failed'&&str_contains($failed['error'],'could not make the picture: CUDA out of memory'),'a failure says it was the picture');
    $id=id();
    $jobs->create(['id'=>$id,'prompt'=>'a lighthouse','options'=>['model'=>MODEL]],$key); $jobs->claimGpu([],$key);
    $vault->db->exec("UPDATE jobs SET updated=updated-1000 WHERE id='$id'"); $jobs->refresh();
    $stale=$jobs->list($key,'active',[$id])[0] ?? $jobs->entry($id,$key);
    check(str_contains((string)($stale['error']??''),'while it made this picture'),'a silent GPU interrupts the picture, saying so');

    // A varied batch: with no key to plan with, each image takes a fixed direction.
    $batch=$jobs->create(['id'=>id(),'prompt'=>'a fox','options'=>['model'=>MODEL,'n'=>3,'vary'=>true]],$key);
    check(count($batch)===3&&array_column(array_column($batch,'variation'),'index')===[1,2,3]&&$batch[1]['variation']['label']!==null&&$batch[1]['variation']['planned']===false,'a varied batch is three requests, each with its own direction');
    $claimed=$jobs->claimGpu([],$key);
    check(!str_contains($claimed['prompt'],'Direction for this image')&&str_contains($claimed['prompt'],'set of 3'),'the first keeps the prompt as written');
    $claimed=$jobs->claimGpu([],$key);
    check(str_contains($claimed['prompt'],'Direction for this image'),'the others get their direction');

    // ---------------------------------------------------------------------------------------------------------------
    // How the worker saves the picture: as each request asks, or a PNG as a JPEG at the quality of Settings › GPU.
    check($gpu->pictureFormat()===['jpeg'=>false,'quality'=>85]&&$gpu->status()['pictures']===['jpeg'=>false,'quality'=>85,'range'=>[50,100]],'at first each request has its own format, with 85 ready for JPEG');
    $plain=$jobs->create(['id'=>id(),'prompt'=>'a field','options'=>['model'=>MODEL,'format'=>'png']],$key)[0];
    check($plain['options']['format']==='png','so a PNG stays a PNG');
    $gpu->setPictureFormat(true);
    check($gpu->pictureFormat()===['jpeg'=>true,'quality'=>85],'JPEG can be turned on, at 85 unless told otherwise');
    $big=$jobs->create(['id'=>id(),'prompt'=>'Recreate this same image at a higher resolution.','operation'=>'upscale','parent_id'=>$source['id'],'references'=>[['id'=>$source['id']]],
        'options'=>['model'=>MODEL,'format'=>'png','compression'=>90,'size'=>'4000x3000','thinking'=>'off']],$key)[0];
    check($big['options']['format']==='jpeg'&&$big['options']['compression']===85,'then an upscale that would be a PNG is saved as a JPEG at 85');
    $edit=$jobs->create(['id'=>id(),'prompt'=>'Make @1 at night','parent_id'=>$source['id'],'references'=>[['id'=>$source['id']]],'options'=>['model'=>MODEL]],$key)[0];
    check($edit['options']['format']==='jpeg'&&$edit['options']['compression']===85,'and so is an edit, with no format given');
    $own=$jobs->create(['id'=>id(),'prompt'=>'a field','options'=>['model'=>MODEL,'format'=>'webp','compression'=>70]],$key)[0];
    $jpeg=$jobs->create(['id'=>id(),'prompt'=>'a field','options'=>['model'=>MODEL,'format'=>'jpeg','compression'=>95]],$key)[0];
    check($own['options']['format']==='webp'&&$own['options']['compression']===70&&$jpeg['options']['format']==='jpeg'&&$jpeg['options']['compression']===95,'a request that asks for WebP or JPEG itself keeps its own');
    $again=$jobs->retry(id(),$plain['id'],null,$key)[0];
    check($again['options']['format']==='jpeg'&&$again['options']['compression']===85,'repeating a PNG request now makes a JPEG');
    $gpu->setPictureFormat(true,70);
    check($gpu->pictureFormat()['quality']===70&&$jobs->create(['id'=>id(),'prompt'=>'a field','options'=>['model'=>MODEL]],$key)[0]['options']['compression']===70,'the quality can be changed');
    $gpu->setPictureFormat(false);
    check($gpu->pictureFormat()===['jpeg'=>false,'quality'=>70]&&$jobs->create(['id'=>id(),'prompt'=>'a field','options'=>['model'=>MODEL]],$key)[0]['options']['format']==='png','turned off, PNG again, and the quality is kept for next time');
    rejects(fn()=>$gpu->setPictureFormat(true,49),'a quality from 50',422);
    rejects(fn()=>$gpu->setPictureFormat(true,101),'up to 100',422);
    rejects(fn()=>$gpu->setPictureFormat(true,'85.5'),'a whole number',422);
    check($gpu->pictureFormat()===['jpeg'=>false,'quality'=>70],'a refused change changes nothing');
    $gpu->setPictureFormat(true,85);
    $mine=$gpu->pictureFormat();
    $other=$users->create('Outra','test-password-long-enough',random_bytes(32));
    $vault->useOwner($other['id']); $theirs=(new Gpu($vault))->pictureFormat(); $vault->useOwner($owner['id']);
    check($mine['jpeg']===true&&$theirs['jpeg']===false,'each account has its own');

    // Not through the writer; MCP offers them, as requests that wait for the notebook (tests/mcp_tools.php)
    rejects(fn()=>Studio\Writer::settings(['image_model'=>MODEL]),'the writer does not use the GPU\'s models',422);
    $tools=Studio\Mcp::tools(); $enum=null;
    foreach($tools as $t) if(($t['name']??'')==='generate_image') $enum=$t['inputSchema']['properties']['model']['enum']??null;
    check(is_array($enum)&&in_array(MODEL,$enum,true),'MCP offers them');

    echo "\n$count checks passed\n";
}finally{
    exec('rm -rf '.escapeshellarg($dir));
}
