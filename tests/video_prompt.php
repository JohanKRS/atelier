<?php
declare(strict_types=1);
// Video requests on the GPU: what a request can bring (pictures pinned at moments, references, a video to start from,
// sounds), how the worker gets it, and the prompt helper, with Claude, GPT and Gemini played back by fake transports.
require dirname(__DIR__).'/app/bootstrap.php';
use Studio\{Vault,Jobs,Users,HttpError,Gpu,VideoPrompt};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function rejects(callable $fn,string $message,?int $status=null): void {
    try{$fn();}catch(Throwable $e){ if($status!==null && (!$e instanceof HttpError || $e->status!==$status)) throw new RuntimeException($message.' (got '.get_class($e).': '.$e->getMessage().')'); check(true,$message);return;}
    throw new RuntimeException($message);
}
/** A fake transport that plays the given answers in order and keeps every request. */
function player(array &$requests,array $answers): Closure {
    $n=0;
    return function(string $url,array $headers,array $body,Closure $onEvent,Closure $tick,int $timeout) use (&$requests,&$n,$answers): array {
        $requests[]=['url'=>$url,'body'=>json_decode(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),true)];
        $answer=$answers[$n++]??throw new RuntimeException('No more fake answers');
        if(isset($answer['status']))return $answer+['sent'=>true,'errno'=>0,'retry_after'=>null];
        foreach($answer as [$event,$data])$onEvent($event,$data);
        return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
    };
}
function png(int $w,int $h): string { $im=imagecreatetruecolor($w,$h); imagefill($im,0,0,imagecolorallocate($im,200,90,40)); ob_start(); imagepng($im); imagedestroy($im); return (string)ob_get_clean(); }
function dataUrl(int $w,int $h): string { return 'data:image/png;base64,'.base64_encode(png($w,$h)); }
function id(): string { return bin2hex(random_bytes(16)); }
$sound='data:audio/mpeg;base64,'.base64_encode(str_repeat("\xFF\xFB\x90\x00",50000));

$dir=sys_get_temp_dir().'/atelier-video-prompt-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>4]);

    // ---------------------------------------------------------------------------------------------------------------
    // What a request can bring
    $opt=fn(array $options,array $inputs)=>Gpu::options($options,$inputs);
    [$o,$in]=$opt(['seconds'=>8],[['kind'=>'image','role'=>'key','at'=>0],['kind'=>'image','role'=>'key','at'=>'4'],['kind'=>'image','role'=>'key','at'=>8],['kind'=>'audio','role'=>'key','at'=>2.5]]);
    check($o['mode']==='new'&&$o['seconds']===8.0&&array_column($in,'at')===[0.0,4.0,'end',2.5],'pictures and sounds are pinned at moments; a picture at the last second is the last frame');
    rejects(fn()=>$opt(['seconds'=>5],[['kind'=>'image','role'=>'key','at'=>9]]),'a moment past the end is refused',422);
    rejects(fn()=>$opt([],[['kind'=>'image','role'=>'key','at'=>1],['kind'=>'image','role'=>'key','at'=>1.0]]),'two pictures at the same moment are refused',422);
    rejects(fn()=>$opt([],array_fill(0,10,['kind'=>'image','role'=>'ref'])),'up to 9 pictures',422);
    rejects(fn()=>$opt([],array_fill(0,4,['kind'=>'video','role'=>'ref'])),'up to 3 videos',422);
    rejects(fn()=>$opt([],array_fill(0,4,['kind'=>'audio','role'=>'ref'])),'up to 3 sounds',422);
    rejects(fn()=>$opt([],[['kind'=>'video','role'=>'source']]),'a new video has no source video',422);
    rejects(fn()=>$opt(['mode'=>'video','op'=>'continue'],[]),'a video from a video needs it',422);
    rejects(fn()=>$opt(['mode'=>'video','op'=>'dance'],[['kind'=>'video','role'=>'source']]),'only the known operations',422);
    rejects(fn()=>$opt(['mode'=>'video','op'=>'replace'],[['kind'=>'video','role'=>'source']]),'replacing needs what to replace',422);
    rejects(fn()=>$opt(['mode'=>'video','op'=>'pose'],[['kind'=>'video','role'=>'source'],['kind'=>'audio','role'=>'ref']]),'a video from a video takes no sounds',422);
    [$o]=$opt(['mode'=>'video','op'=>'replace','target'=>'  the red   shirt '],[['kind'=>'video','role'=>'source'],['kind'=>'image','role'=>'ref']]);
    check($o['op']==='replace'&&$o['target']==='the red shirt'&&$o['keep_audio']===true,'replacing keeps the original sound unless told otherwise');
    [$o]=$opt(['mode'=>'video','op'=>'continue','join'=>false],[['kind'=>'video','role'=>'source']]);
    check($o['join']===false&&!isset($o['keep_audio']),'a continuation can come alone or joined to the video');

    // The labels H3 knows the inputs by, as the worker connects them.
    $labels=fn(array $options,array $inputs)=>VideoPrompt::labels($options,Gpu::options($options,$inputs)[1]);
    $l=$labels([],[['kind'=>'image','role'=>'key','at'=>'end'],['kind'=>'image','role'=>'key','at'=>0]]);
    check($l['format']==='base'&&$l['labels']===[1=>'<Picture 1>',0=>'<Picture 2>'],'first and last frames alone use the base format, first frame first');
    $l=$labels(['seconds'=>6],[['kind'=>'image','role'=>'key','at'=>3],['kind'=>'image','role'=>'ref'],['kind'=>'image','role'=>'key','at'=>0],['kind'=>'video','role'=>'ref'],['kind'=>'audio','role'=>'ref']]);
    check($l['format']==='reference'&&$l['labels']===[1=>'<Picture 1>',2=>'<Picture 2>',0=>'<Picture 3>',3=>'<Video 1>',4=>'<Audio 1>'],'references come first, then the pinned pictures by moment');
    $l=$labels(['mode'=>'video','op'=>'pose'],[['kind'=>'video','role'=>'source'],['kind'=>'image','role'=>'ref']]);
    check($l['labels']===[1=>'<Picture 1>'],'the video whose movement is followed has no label');
    $l=$labels(['mode'=>'video','op'=>'continue'],[['kind'=>'video','role'=>'source']]);
    check($l['labels']===[0=>'<Video 1>'],'the video that is continued is <Video 1>');

    // ---------------------------------------------------------------------------------------------------------------
    // A request with every kind of input, and what the worker gets
    $picture=$vault->importImage(png(120,80),'Praia',$key);
    $mp4=sys_get_temp_dir().'/atelier-video-prompt-'.bin2hex(random_bytes(4)).'.mp4';
    exec('ffmpeg -y -v error -f lavfi -i testsrc2=size=160x90:rate=24 -t 3 -c:v libx264 -pix_fmt yuv420p '.escapeshellarg($mp4),$out,$code);
    if($code!==0)throw new RuntimeException('ffmpeg is needed for these tests');
    $bytes=(string)file_get_contents($mp4); @unlink($mp4);
    $upload=$vault->startUpload(strlen($bytes),50<<20); $vault->appendUpload($upload,0,$bytes,$key);
    $video=$vault->finishUpload($upload,['name'=>'Ondas','width'=>160,'height'=>90,'duration'=>3.0,'fps'=>24,'frames'=>72,'mime'=>'video/mp4'],dataUrl(160,90),$key);
    rejects(fn()=>$jobs->createVideo(['id'=>id(),'prompt'=>'x','inputs'=>[['kind'=>'audio','role'=>'ref','data'=>dataUrl(8,8)]]],$key),'a sound must be a sound',422);
    rejects(fn()=>$jobs->createVideo(['id'=>id(),'prompt'=>'x','inputs'=>[['kind'=>'video','role'=>'ref','id'=>$picture['id']]]],$key),'a picture is not a video',404);
    $id=id();
    $made=$jobs->createVideo(['id'=>$id,'prompt'=>'A seagull @1 lands','options'=>['seconds'=>6,'quality'=>'balanced'],'inputs'=>[
        ['kind'=>'image','role'=>'ref','id'=>$picture['id']],['kind'=>'image','role'=>'key','at'=>0,'data'=>dataUrl(90,60)],['kind'=>'image','role'=>'key','at'=>'end','data'=>dataUrl(90,60)],
        ['kind'=>'video','role'=>'ref','id'=>$video['id']],['kind'=>'audio','role'=>'key','at'=>1.5,'data'=>$sound,'name'=>'voz.mp3']]],$key)[0];
    check($made['status']==='queued'&&$made['kind']==='video'&&$made['roles']===['ref','key','key','ref','key'],'a request with pictures, a video and a sound waits for the GPU');
    check(array_column($made['inputs'],'kind')===['image','image','image','video','audio']&&$made['inputs'][2]['at']==='end'&&$made['inputs'][4]['name']==='voz.mp3'&&!isset($made['inputs'][1]['data']),'the page sees the inputs without their data');
    check($made['parent_ids']===[$picture['id'],$video['id']],'the library pictures and videos it came from are its parents');
    $row=$vault->db->prepare('SELECT length(payload) FROM jobs WHERE id=?');$row->execute([$id]);
    check((int)$row->fetchColumn()<20000,'the sound goes to the private file of the request, not to its row');
    $claimed=$jobs->claimVideo(['h3'],$key);
    $kinds=array_column($claimed['inputs'],'kind');
    check($claimed['id']===$id&&$kinds===['image','image','image','video','audio'],'the worker gets every input in order');
    check(str_starts_with($claimed['inputs'][0]['data'],'data:image/')&&str_starts_with($claimed['inputs'][4]['data'],'data:audio/mpeg')&&$claimed['inputs'][4]['at']===1.5,'pictures and sounds come as data, with their moments');
    check(!isset($claimed['inputs'][3]['data'])&&$claimed['inputs'][3]['index']===3&&$claimed['inputs'][3]['size']===strlen($bytes),'a video comes as its place and size, to download');
    $media=$jobs->videoMedia($id,3,$key);
    $got=''; $vault->streamVideo($media,0,(int)$media['size']-1,$key,function(string $b) use (&$got): bool { $got.=$b; return true; });
    check($got===$bytes,'the worker downloads exactly the video of the library');
    rejects(fn()=>$jobs->videoMedia($id,0,$key),'only videos are downloaded',404);
    $jobs->failVideo($id,'test',false,$key);
    rejects(fn()=>$jobs->videoMedia($id,3,$key),'nor after the request ended',409);
    $again=$jobs->retry(id(),$id,null,$key)[0];
    check($again['status']==='queued'&&$again['inputs']===$made['inputs'],'making it again keeps its inputs');

    // ---------------------------------------------------------------------------------------------------------------
    // The prompt helper
    $helper=new VideoPrompt($vault);
    check($helper->providers($key)===['gpt'],'the helper uses the keys the account has');
    $vault->setClaudeKey('sk-ant-test',$key); $vault->setGeminiKey('gemini-test',$key);
    check($helper->providers($key)===['claude','gpt','gemini'],'Claude first when there is a Claude key');
    $answer="subject_definitions:\n<Subject 1> is the seagull in <Picture 1>.\n\nsummary:\n[keyframe completion + reference generation] A seagull lands.";
    $requests=[];
    $events=[['message_start',['type'=>'message_start','message'=>['id'=>'m','model'=>'claude-sonnet-5-5','role'=>'assistant','content'=>[],'usage'=>['input_tokens'=>3000,'output_tokens'=>800]]]],
        ['content_block_start',['type'=>'content_block_start','index'=>0,'content_block'=>['type'=>'text','text'=>'']]],
        ['content_block_delta',['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>"```\n$answer\n```"]]],
        ['content_block_stop',['type'=>'content_block_stop','index'=>0]],
        ['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn','stop_details'=>null],'usage'=>['output_tokens'=>800]]],['message_stop',['type'=>'message_stop']]];
    $helper=new VideoPrompt($vault,player($requests,[$events]));
    $result=$helper->improve(['prompt'=>'uma gaivota @1 pousa e grita "sai daqui"','options'=>['seconds'=>6],'inputs'=>[
        ['kind'=>'image','role'=>'ref','id'=>$picture['id']],['kind'=>'image','role'=>'key','at'=>0,'data'=>dataUrl(90,60)],['kind'=>'image','role'=>'key','at'=>'end','data'=>dataUrl(90,60)],
        ['kind'=>'video','role'=>'ref','id'=>$video['id']],['kind'=>'audio','role'=>'ref','data'=>$sound,'name'=>'voz.mp3']]],$key);
    check($result['prompt']===$answer&&$result['provider']==='claude'&&$result['model']==='claude-sonnet-5-5'&&$result['cost']>0,'Claude rewrites the idea; code fences are taken off');
    $body=$requests[0]['body']; $content=$body['messages'][0]['content']; $text=end($content)['text'];
    check(str_contains($body['system'],'integrated_multimodal_description')&&str_contains($body['system'],'retention_analysis')&&str_contains($body['system'],'<d>'),'it gets the rules of the official H3 guides');
    check(count(array_filter($content,fn($c)=>$c['type']==='image'))===4,'it sees the pictures and the first frame of the video');
    check(str_contains($text,'Format: [B] reference')&&str_contains($text,'<Picture 1>: reference picture')&&str_contains($text,'<Picture 2>: the first frame')&&str_contains($text,'<Picture 3>: the last frame, pinned at 6.00 s'),'it is told each picture\'s label and role');
    check(str_contains($text,'<Video 1>: reference video, 3.0 s')&&str_contains($text,'<Audio 1>: reference sound ("voz.mp3"')&&str_contains($text,'sai daqui'),'and the video, the sound and the idea as written');
    $spent=$vault->db->query("SELECT COUNT(*) FROM spend")->fetchColumn();
    check((int)$spent>=1,'what the helper costs is counted with the account\'s spending');

    $requests=[];
    $gpt=[['response.output_text.delta',['type'=>'response.output_text.delta','delta'=>'integrated_multimodal_description: [Shot 1] Rain.']],
        ['response.output_item.done',['type'=>'response.output_item.done','output_index'=>0,'item'=>['type'=>'message','content'=>[['type'=>'output_text','text'=>'integrated_multimodal_description: [Shot 1] Rain.']]]]],
        ['response.completed',['type'=>'response.completed','response'=>['status'=>'completed','model'=>'gpt-6.2-sol','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'integrated_multimodal_description: [Shot 1] Rain.']]]],'usage'=>['input_tokens'=>900,'output_tokens'=>300]]]]];
    $helper=new VideoPrompt($vault,null,player($requests,[$gpt]));
    $result=$helper->improve(['prompt'=>'chuva','provider'=>'gpt','options'=>['seconds'=>5]],$key);
    check($result['prompt']==='integrated_multimodal_description: [Shot 1] Rain.'&&$result['provider']==='gpt','GPT can be the helper too');
    check(str_contains($requests[0]['body']['input'][0]['content'][0]['text'],'Format: [A] base')&&str_contains($requests[0]['body']['input'][0]['content'][0]['text'],'Inputs: none'),'text alone asks for the base format');

    $requests=[];
    $gemini=['status'=>200,'body'=>json_encode(['status'=>'completed','steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>'subject_definitions: x']]]],'usage'=>['total_input_tokens'=>500,'total_output_tokens'=>100]])];
    $helper=new VideoPrompt($vault,null,null,player($requests,[$gemini]));
    $result=$helper->improve(['prompt'=>'continua','provider'=>'gemini','options'=>['mode'=>'video','op'=>'continue','seconds'=>5],'inputs'=>[['kind'=>'video','role'=>'source','id'=>$video['id']]]],$key);
    check($result['prompt']==='subject_definitions: x'&&str_contains(json_encode($requests[0]['body'],JSON_UNESCAPED_UNICODE),'the video to continue'),'Gemini can be the helper, and a continuation is described as one');
    rejects(fn()=>$helper->improve(['prompt'=>'x','provider'=>'grok'],$key),'only the account\'s providers',422);
    rejects(fn()=>$helper->improve(['prompt'=>''],$key),'an empty idea is refused',422);
} finally {
    if(is_dir($dir)){ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); rmdir($dir); }
}
echo "\n$count video request checks passed.\n";
