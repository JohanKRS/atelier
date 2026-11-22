<?php
declare(strict_types=1);
// Google Gemini as the story writer and Nano Banana as the image model, played back by fake transports: no real API calls.
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Crypto,Vault,OpenAI,Jobs,Users,HttpError,Stories,Writer,Claude,Gemini,GeminiImages,Images};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function rejects(callable $fn,string $message,?int $status=null,?string $contains=null): void {
    try{$fn();}catch(Throwable $e){
        if($status!==null && (!$e instanceof HttpError || $e->status!==$status)) throw new RuntimeException($message.' (got '.get_class($e).': '.$e->getMessage().')');
        if($contains!==null && !str_contains($e->getMessage(),$contains)) throw new RuntimeException($message.' (message: '.$e->getMessage().')');
        check(true,$message);return;
    }
    throw new RuntimeException($message);
}
/** The events of one Gemini answer (Interactions API), as it streams them. */
function geminiEvents(array $steps,string $status='completed',array $usage=['total_input_tokens'=>1000,'total_cached_tokens'=>0,'total_output_tokens'=>200,'total_thought_tokens'=>50,'total_tokens'=>1250],string $model='gemini-4-argon',array $extra=[]): array {
    $events=[['interaction.created',['event_type'=>'interaction.created','interaction'=>['id'=>'int_1','model'=>$model,'status'=>'in_progress']]]];
    foreach($steps as $i=>$s){
        if($s['type']==='thought'){
            $events[]=['step.start',['event_type'=>'step.start','index'=>$i,'step'=>['type'=>'thought']]];
            if(($s['summary']??'')!=='')$events[]=['step.delta',['event_type'=>'step.delta','index'=>$i,'delta'=>['type'=>'thought_summary','content'=>['type'=>'text','text'=>$s['summary']]]]];
            if(($s['signature']??'gsig-'.$i)!=='')$events[]=['step.delta',['event_type'=>'step.delta','index'=>$i,'delta'=>['type'=>'thought_signature','signature'=>$s['signature']??'gsig-'.$i]]];
        }elseif($s['type']==='function_call'){
            $events[]=['step.start',['event_type'=>'step.start','index'=>$i,'step'=>['type'=>'function_call','id'=>$s['id'],'name'=>$s['name']]]];
            $json=$s['raw']??json_encode($s['arguments'],JSON_UNESCAPED_UNICODE);
            foreach($json===''?[]:str_split($json,9) as $part)$events[]=['step.delta',['event_type'=>'step.delta','index'=>$i,'delta'=>['type'=>'arguments_delta','arguments'=>$part]]];
        }else{
            $events[]=['step.start',['event_type'=>'step.start','index'=>$i,'step'=>['type'=>'model_output']]];
            $events[]=['step.delta',['event_type'=>'step.delta','index'=>$i,'delta'=>['type'=>'text','text'=>$s['text']]]];
        }
        $events[]=['step.stop',['event_type'=>'step.stop','index'=>$i]];
    }
    $events[]=['interaction.completed',['event_type'=>'interaction.completed','interaction'=>['id'=>'int_1','model'=>$model,'status'=>$status,'usage'=>$usage]+$extra]];
    return $events;
}
/** A fake transport that plays the given answers in order and keeps every request. */
function player(array &$requests,array $answers): Closure {
    $n=0;
    return function(string $url,array $headers,array $body,Closure $onEvent,Closure $tick,int $timeout) use (&$requests,&$n,$answers): array {
        $requests[]=['url'=>$url,'headers'=>$headers,'body'=>json_decode(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),true),'raw'=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $answer=$answers[$n++]??throw new RuntimeException('No more fake answers');
        if($answer instanceof Closure)return $answer($onEvent,$tick,$body);
        if(isset($answer['status']))return $answer+['sent'=>true,'errno'=>0,'retry_after'=>null];
        foreach($answer as [$event,$data])$onEvent($event,$data);
        return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
    };
}
/** A plain JSON answer (a picture, a planning call). */
function jsonAnswer(array $json): array { return ['status'=>200,'body'=>json_encode($json,JSON_UNESCAPED_SLASHES)]; }
function png(int $w,int $h,array $rgb=[30,90,160],bool $transparentLeft=false): string {
    $im=imagecreatetruecolor($w,$h); imagealphablending($im,false); imagesavealpha($im,true);
    imagefill($im,0,0,imagecolorallocatealpha($im,$rgb[0],$rgb[1],$rgb[2],0));
    if($transparentLeft) imagefilledrectangle($im,0,0,intdiv($w,2)-1,$h-1,imagecolorallocatealpha($im,0,0,0,127));
    ob_start(); imagepng($im); $data=(string)ob_get_clean(); imagedestroy($im); return $data;
}
function dataUrl(string $png): string { return 'data:image/png;base64,'.base64_encode($png); }
function imageAnswer(string $bytes,array $usage=['total_input_tokens'=>1500,'total_output_tokens'=>1300,'total_thought_tokens'=>200,'total_tokens'=>3000,'output_tokens_by_modality'=>[['modality'=>'IMAGE','tokens'=>1120],['modality'=>'TEXT','tokens'=>180]]]): array {
    return jsonAnswer(['id'=>'int_img','status'=>'completed','model'=>'gemini-nano-banana-2.1','steps'=>[
        ['type'=>'thought','signature'=>'s1'],['type'=>'model_output','content'=>[['type'=>'text','text'=>'Here it is.'],['type'=>'image','mime_type'=>'image/png','data'=>base64_encode($bytes)]]]],'usage'=>$usage]);
}
function pixel(string $bytes,int $x,int $y): array { $im=imagecreatefromstring($bytes); $c=imagecolorat($im,$x,$y); imagedestroy($im); return [($c>>16)&255,($c>>8)&255,$c&255,($c>>24)&127]; }

$dir=sys_get_temp_dir().'/imagegen-gemini-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    // ---------------------------------------------------------------------------------------------------------------
    // The client: a streamed answer with a thought, a call and text
    $requests=[];$deltas=[];
    $client=new Gemini('AIza-test-key',60,player($requests,[geminiEvents([
        ['type'=>'thought','summary'=>'Planejando o capítulo.','signature'=>'SIG-A'],
        ['type'=>'function_call','id'=>'fc_1','name'=>'write_chapter','arguments'=>['title'=>'Um','text'=>'Era uma vez um farol.']],
        ['type'=>'function_call','id'=>'fc_2','name'=>'get_outline','raw'=>''],
        ['type'=>'function_call','id'=>'fc_3','name'=>'read_chapter','raw'=>'{"chapter": 2'],
        ['type'=>'text','text'=>'Pronto.'],
    ])]));
    $answer=$client->send(['model'=>'gemini-4-argon','input'=>[]],function(string $kind,string $chunk) use (&$deltas){ $deltas[]=[$kind,$chunk]; },fn()=>false);
    check($requests[0]['url']===Gemini::URL&&in_array('x-goog-api-key: AIza-test-key',$requests[0]['headers'],true)&&$requests[0]['body']['stream']===true,'Gemini is called on the Interactions API with the key in its header, streaming');
    $s=$answer['steps'];
    check(count($s)===5&&$s[0]->type==='thought'&&$s[0]->signature==='SIG-A'&&$s[0]->summary[0]->text==='Planejando o capítulo.','a thought comes back with its summary and its signature, to be sent again as it came');
    check($s[1]->arguments->text==='Era uma vez um farol.'&&$s[1]->id==='fc_1','the arguments of a call stream in and are parsed');
    check(str_contains(json_encode($s[2]),'"arguments":{}'),'a call with no arguments goes back with an empty object, not a list');
    check(($answer['invalid'][3]??null)==='{"chapter": 2','arguments that are not JSON are kept raw for the writer to be told');
    check(in_array(['update','Planejando o capítulo.'],$deltas,true)&&in_array(['tool','write_chapter'],$deltas,true)&&in_array(['text','Pronto.'],$deltas,true)
        &&json_decode(implode('',array_column(array_filter($deltas,fn($d)=>$d[0]==='input'),1)),true)===null,'the page sees the thinking summary, the tool and its input as they stream, and the text');
    check($answer['status']==='completed'&&$answer['usage']['total_input_tokens']===1000&&$answer['model']==='gemini-4-argon','the status, the usage and the model come from the end of the stream');

    // A field the API doesn't take is dropped, once, and left out of the next requests
    Gemini::resetDropped();
    $requests=[];
    $unknown=['status'=>400,'body'=>json_encode(['error'=>['code'=>400,'message'=>'Invalid JSON payload received. Unknown name "safety_settings": Cannot find field.','status'=>'INVALID_ARGUMENT']])];
    $client=new Gemini('AIza-test-key',60,player($requests,[$unknown,geminiEvents([['type'=>'text','text'=>'Ok.']]),geminiEvents([['type'=>'text','text'=>'Ok de novo.']])]));
    $client->send(['model'=>'gemini-4-argon','input'=>[],'safety_settings'=>Gemini::safety()],fn()=>null,fn()=>false);
    $client->send(['model'=>'gemini-4-argon','input'=>[],'safety_settings'=>Gemini::safety()],fn()=>null,fn()=>false);
    check(count($requests)===3&&isset($requests[0]['body']['safety_settings'])&&!isset($requests[1]['body']['safety_settings'])&&!isset($requests[2]['body']['safety_settings']),'a field the API refuses is sent again without it, and not sent again after that');
    Gemini::resetDropped();
    check(array_column(Gemini::safety(),'threshold')===['OFF','OFF','OFF','OFF']&&in_array('HARM_CATEGORY_SEXUALLY_EXPLICIT',array_column(Gemini::safety(),'category'),true),'every adjustable filter goes off, the lowest level the API has');

    // Errors, in Google's two shapes; only codes and field names are shown
    $e=Gemini::failure(400,json_encode([['error'=>['code'=>400,'message'=>'API key not valid. Please pass a valid API key.','status'=>'INVALID_ARGUMENT','details'=>[['@type'=>'type.googleapis.com/google.rpc.ErrorInfo','reason'=>'API_KEY_INVALID']]]]]));
    check($e->getMessage()===Gemini::keyMessage(),'a key Google refuses says so, in either error shape');
    $e=Gemini::failure(429,json_encode(['error'=>['code'=>429,'message'=>'You exceeded your current quota, please check your plan and billing details.','status'=>'RESOURCE_EXHAUSTED']]));
    check($e->kind==='gemini-credit'&&$e->getMessage()===Gemini::noCreditMessage(),'a quota or billing limit is no credit, told apart from OpenAI\'s');
    $e=Gemini::failure(429,json_encode(['error'=>['code'=>429,'message'=>'Quota exceeded','status'=>'RESOURCE_EXHAUSTED','details'=>[['@type'=>'type.googleapis.com/google.rpc.QuotaFailure','violations'=>[['quotaId'=>'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]]]]));
    check($e->kind==='gemini-credit','a daily limit of the free tier is no credit too');
    $e=Gemini::failure(429,json_encode(['error'=>['code'=>429,'message'=>'Quota exceeded for metric: requests per minute','status'=>'RESOURCE_EXHAUSTED','details'=>[['@type'=>'type.googleapis.com/google.rpc.QuotaFailure','violations'=>[['quotaId'=>'GenerateRequestsPerMinutePerProjectPerModel']]]]]]));
    check($e->kind==='retry'&&str_contains($e->getMessage(),'por minuto'),'a limit per minute is waited out and tried again');
    $e=Gemini::failure(400,json_encode(['error'=>['code'=>400,'message'=>'Invalid value at \'generation_config.thinking_level\' (TYPE_ENUM), "secret story text"','status'=>'INVALID_ARGUMENT']]));
    check(str_contains($e->getMessage(),'generation_config.thinking_level')&&!str_contains($e->getMessage(),'secret'),'a refused request says which field, never what the conversation said');
    check(str_contains(Gemini::codeError('image_safety')->getMessage(),'não tem ajuste')&&str_contains(Gemini::codeError('prohibited_content')->getMessage(),'vale sempre')&&str_contains(Gemini::codeError('safety')->getMessage(),'ajustáveis desligados'),
        'a block says what blocked it and whether it has a setting');
    check(Gemini::failure(503,'{"error":{"code":503,"status":"UNAVAILABLE"}}')->kind==='retry','Google busy for a moment is tried again');

    // Prices change on their dates
    $u=['total_input_tokens'=>1000000,'total_cached_tokens'=>500000,'total_output_tokens'=>100000,'total_thought_tokens'=>100000,'total_tokens'=>1200000];
    check(abs(Gemini::cost('gemini-4-argon',$u,strtotime('2026-11-25T00:00:00Z'))-(0.5*2+0.5*0.10+0.2*10))<1e-9,'Gemini 4 Argon costs its November price, the cache read at a twentieth');
    check(abs(Gemini::cost('gemini-4-argon',$u,strtotime('2026-12-02T00:00:00Z'))-(0.5*4+0.5*0.20+0.2*20))<1e-9,'and doubles from 1 December 2026');
    check(abs(Gemini::cost('gemini-4-neon',$u,strtotime('2027-03-01T00:00:00Z'))-(0.5*3+0.5*0.15+0.2*15))<1e-9,'Gemini 4 Neon costs US$ 3 in, 0.15 from the cache and 15 out');
    check(Gemini::thinking('max','gemini-4-neon')==='xhigh'&&Gemini::thinking('xhigh','gemini-4-neon')==='xhigh'&&Gemini::thinking('minimal','gemini-4-neon')==='low'
        &&Gemini::thinking('xhigh','gemini-4-argon')==='high'&&Gemini::thinking('max')==='high','each model thinks up to its own top level: Neon to xhigh, the others to high');
    $u2=['total_input_tokens'=>100,'total_output_tokens'=>300,'total_thought_tokens'=>200,'total_tokens'=>400];
    check(Gemini::tokens($u2)===['input'=>100,'read'=>0,'output'=>100,'thinking'=>200],'thinking already inside the output (the total says so) is not counted twice');

    // The test in Configurações: the key and its models, then a tool call and its result
    $list=fn(string $url)=>['status'=>200,'errno'=>0,'body'=>json_encode(['models'=>array_map(fn($m)=>['name'=>"models/$m"],['gemini-4-neon','gemini-4-argon','gemini-4-hydrogen','gemini-3.8-flash','gemini-3.8-flash-tts','gemini-2.5-flash','gemini-nano-banana-2.1','gemini-embedding-2'])])];
    $requests=[];
    $result=Gemini::selfTest('AIza-test-key',$list,player($requests,[geminiEvents([['type'=>'thought','signature'=>'T1'],['type'=>'function_call','id'=>'p1','name'=>'ping','arguments'=>['word'=>'atelier']]],'requires_action',[],'gemini-3.8-flash'),
        geminiEvents([['type'=>'text','text'=>'pong']],'completed',['total_input_tokens'=>300,'total_output_tokens'=>2],'gemini-3.8-flash')]));
    check($result['ok']===true&&str_contains($result['steps'][0]['detail'],'gemini-3-pro-image')&&$result['steps'][1]['ok'],'the key test lists a model the key lacks and passes a tool round trip');
    check(str_contains($result['steps'][0]['detail'],'ainda não oferece: gemini-4-hydrogen (')&&!str_contains($result['steps'][0]['detail'],'gemini-2.5-flash')&&!str_contains($result['steps'][0]['detail'],'tts'),'and names the newer Gemini writers the key has that the app doesn\'t offer yet');
    $second=$requests[1]['body']['input'];
    check(array_column($second,'type')===['user_input','thought','function_call','function_result']&&$second[1]['signature']==='T1'&&$second[3]['call_id']==='p1'&&$second[3]['name']==='ping','the test sends the thought and the call back with the result, as the writer does');
    $bad=Gemini::selfTest('AIza-bad',fn($u)=>['status'=>400,'errno'=>0,'body'=>json_encode(['error'=>['code'=>400,'status'=>'INVALID_ARGUMENT','details'=>[['reason'=>'API_KEY_INVALID']]]])]);
    check($bad['ok']===false&&count($bad['steps'])===1&&$bad['steps'][0]['detail']===Gemini::keyMessage(),'a bad key stops the test at the first step, with the reason');

    // ---------------------------------------------------------------------------------------------------------------
    // Nano Banana
    $models=Images::models();
    check($models['gemini-nano-banana-2.1']['provider']==='gemini'&&$models['gpt-image-2.6-flare']['provider']==='openai'&&!isset($models['gemini-3-pro-image']['prices']),'the page gets every image model with its provider');
    check(Images::maxImages('gemini-nano-banana-2.1')===14&&Images::maxImages('gpt-image-2.6-flare')===16,'Nano Banana reads 14 images, GPT Image 16');
    rejects(fn()=>Images::options(['model'=>'gemini-nano-banana-2.1','background'=>'transparent','format'=>'png']),'Nano Banana refuses a transparent background',422);
    $o=Images::options(['model'=>'gemini-nano-banana-2.1','quality'=>'max','previews'=>2,'moderation'=>'auto']);
    check($o['quality']==='high'&&$o['previews']===0&&$o['model']==='gemini-nano-banana-2.1','a quality above high becomes high, and there are no previews');
    check(Images::options(['model'=>'gemini-3-pro-image','quality'=>'low'])['quality']==='high'&&Images::options(['model'=>'gemini-nano-banana-2.1','quality'=>'low'])['quality']==='low','Pro always thinks (high); 2.1 thinks little at low');
    check(GeminiImages::tier('gemini-nano-banana-2.1',1024,1024)==='1K'&&GeminiImages::tier('gemini-nano-banana-2.1',2048,2048)==='2K'&&GeminiImages::tier('gemini-nano-banana-2.1',3840,2160)==='4K'&&GeminiImages::tier('gemini-3-pro-image',1024,1024)==='2K','the resolution follows the size (Pro never below 2K, which costs the same)');
    check(GeminiImages::ratio('gemini-nano-banana-2.1',1536,864)==='16:9'&&GeminiImages::ratio('gemini-nano-banana-2.1',1536,512)==='21:9','the aspect ratio is the model\'s nearest');

    $job=['prompt'=>'Um farol na névoa','operation'=>'generate','references'=>[],'mask'=>null,'options'=>Images::options(['model'=>'gemini-nano-banana-2.1','size'=>'1536x1024','quality'=>'high','thinking'=>'off'])];
    $requests=[];
    $api=new GeminiImages('AIza-test-key',60,player($requests,[imageAnswer(png(96,96))]));
    $out=$api->generate($job);
    $b=$requests[0]['body'];
    check($b['model']==='gemini-nano-banana-2.1'&&$b['store']===false&&$b['input'][0]['type']==='user_input'&&$b['input'][0]['content'][0]['type']==='text'&&str_contains($b['input'][0]['content'][0]['text'],'Um farol na névoa'),'a picture is one request with the prompt, nothing kept at Google');
    check($b['response_format']===['type'=>'image','mime_type'=>'image/png','image_size'=>'1K','aspect_ratio'=>'3:2']&&$b['generation_config']===['thinking_level'=>'high']&&count($b['safety_settings'])===4,'it asks for an image at the resolution and ratio of the size, thinking as the quality says, with the filters off');
    $got=getimagesizefromstring(base64_decode($out['data'][0]['b64_json']));
    check($got[0]===96&&$got[1]===64,'a picture in another ratio than asked is cropped to it');
    check($out['usage']['provider']==='gemini'&&$out['usage']['input_tokens']===1500&&$out['usage']['output_tokens_details']['image_tokens']===1120&&$out['usage']['output_tokens_details']['text_tokens']===380&&$out['usage']['total_tokens']===3000,'its usage is kept in the app\'s shape: image tokens apart from the text and thinking');
    check(abs(GeminiImages::cost('gemini-nano-banana-2.1',$out['usage'],'1K')-(1500*1.5+1120*30+380*7.5)/1e6)<1e-12,'and priced by Google\'s rates');

    // An area painted: the copy that marks it goes last, in magenta, and the prompt says what it is
    $photo=png(64,64,[20,120,40]);
    $maskIm=imagecreatetruecolor(64,64); imagealphablending($maskIm,false); imagesavealpha($maskIm,true);
    imagefill($maskIm,0,0,imagecolorallocatealpha($maskIm,0,0,0,0)); imagefilledrectangle($maskIm,0,0,31,63,imagecolorallocatealpha($maskIm,0,0,0,127));
    ob_start(); imagepng($maskIm); $mask=(string)ob_get_clean();
    $edit=['prompt'=>'Troque o céu','operation'=>'edit','references'=>[dataUrl($photo),dataUrl(png(32,32))],'mask'=>dataUrl($mask),'options'=>Images::options(['model'=>'gemini-nano-banana-2.1','size'=>'1024x1024','quality'=>'medium','thinking'=>'off'])];
    $requests=[];
    (new GeminiImages('AIza-test-key',60,player($requests,[imageAnswer(png(64,64))])))->generate($edit);
    $content=$requests[0]['body']['input'][0]['content'];
    check(array_column($content,'type')===['text','image','image','image']&&str_starts_with($content[0]['text'],'About the attached images: the last one (image 3) is a copy of image 1'),'the marked copy goes after the references, and the prompt opens by saying what it is');
    $marked=base64_decode($content[3]['data']);
    [$r,$g,$bl]=pixel($marked,8,32); [$r2,$g2,$b2]=pixel($marked,56,32);
    check($r>200&&$bl>200&&$g<100&&$g2>80&&$r2<80,'the area to change is painted magenta, the rest is the picture');
    check(str_contains($content[0]['text'],'skin')||str_contains($content[0]['text'],'Skin'),'an edit carries the fidelity block, as with GPT Image');
    check($requests[0]['body']['generation_config']===['thinking_level'=>'medium'],'medium quality thinks at medium');
    // Expandir: the empty canvas is flat gray, not transparent
    $expand=['prompt'=>'Expanda','operation'=>'expand','references'=>[dataUrl(png(64,64,[200,30,30],true))],'mask'=>dataUrl($mask),'options'=>Images::options(['model'=>'gemini-nano-banana-2.1','size'=>'1024x1024','thinking'=>'off'])];
    $requests=[];
    (new GeminiImages('AIza-test-key',60,player($requests,[imageAnswer(png(64,64))])))->generate($expand);
    $first=base64_decode($requests[0]['body']['input'][0]['content'][1]['data']);
    [$r,$g,$bl]=pixel($first,8,32);
    check(abs($r-128)<6&&abs($g-128)<6&&abs($bl-128)<6&&str_contains($requests[0]['body']['input'][0]['content'][0]['text'],'flat gray: it is empty canvas'),'in an expansion the empty canvas goes as flat gray, and the prompt says so');
    rejects(fn()=>(new GeminiImages('k',60,player($requests,[])))->generate(['references'=>array_fill(0,14,dataUrl(png(16,16)))]+$edit),'more than 14 images in one request are refused before anything is sent',422);

    // WebP, and a picture refused by Google's own image filter
    $webp=['options'=>['format'=>'webp','compression'=>80]+$job['options']]+$job;
    $out=(new GeminiImages('k',60,player($requests,[imageAnswer(png(96,64))])))->generate($webp);
    check(!function_exists('imagewebp')||str_starts_with(base64_decode($out['data'][0]['b64_json']),'RIFF'),'WebP is made from the PNG Google returns');
    rejects(fn()=>(new GeminiImages('k',60,player($requests,[jsonAnswer(['status'=>'failed','errors'=>[['code'=>'image_safety']],'steps'=>[]])])))->generate($job),'a picture the image filter blocked says so, with its code',502,'image_safety');

    // Planning with Gemini 3.8 Flash, paid apart and kept in the app's shape
    $requests=[];
    $planned=['thinking'=>'medium']+$job['options'];
    $api=new GeminiImages('k',60,player($requests,[jsonAnswer(['status'=>'completed','steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>'A lighthouse in thick fog, cinematic.']]]],'usage'=>['total_input_tokens'=>900,'total_output_tokens'=>300,'total_thought_tokens'=>100,'total_tokens'=>1200]]),imageAnswer(png(96,64))]));
    $out=$api->generate(['options'=>$planned]+$job);
    check($requests[0]['body']['model']==='gemini-3.8-flash'&&$requests[0]['body']['system_instruction']===OpenAI::PLAN_INSTRUCTIONS&&$requests[0]['body']['generation_config']['thinking_level']==='medium','the prompt is planned by Gemini 3.8 Flash with the same instructions as GPT-6 Sol');
    check(str_contains($requests[1]['body']['input'][0]['content'][0]['text'],'A lighthouse in thick fog')&&$out['planned_prompt']==='A lighthouse in thick fog, cinematic.','the picture is made from the planned prompt');
    $paid=$api->paid();
    check(count($paid)===1&&$paid[0]['model']==='gemini-3.8-flash'&&$paid[0]['usage']['input_tokens']===900&&$paid[0]['usage']['output_tokens']===300&&$paid[0]['kind']==='planning','the planning is kept with what the request paid, in the app\'s shape');
    $api=new GeminiImages('k',60,player($requests,[jsonAnswer(['status'=>'completed','steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>"```json\n{\"variations\": [\"ao amanhecer\", \"sob tempestade\"]}\n```"]]]],'usage'=>['total_input_tokens'=>10,'total_output_tokens'=>10]])]));
    check($api->planVariations('Um farol',[],2,false)['variations']===['ao amanhecer','sob tempestade'],'the directions of a varied batch are read even inside a code fence');

    // ---------------------------------------------------------------------------------------------------------------
    // Requests in the app with Nano Banana
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>4]);
    $new=fn(array $extra=[])=>['id'=>bin2hex(random_bytes(16)),'prompt'=>'Um farol','options'=>['model'=>'gemini-nano-banana-2.1','size'=>'1024x1024','thinking'=>'off']]+$extra;
    rejects(fn()=>$jobs->create($new(),$key),'Nano Banana needs the Gemini key, and says so',422,'chave da API do Gemini');
    $vault->setGeminiKey('AIza-test-placeholder-key-for-unit-tests',$key);
    check($vault->geminiKey($key)==='AIza-test-placeholder-key-for-unit-tests'&&!str_contains((string)$vault->db->query('SELECT gemini_key FROM users')->fetchColumn(),'AIza'),'the Gemini key is kept sealed');
    $refs=array_fill(0,15,['data'=>dataUrl(png(16,16))]);
    rejects(fn()=>$jobs->create($new(['references'=>$refs]),$key),'more than 14 references are refused with Nano Banana',422,'Use até 14 referências');
    $created=$jobs->create($new(),$key)[0];
    $requests=[];
    $ran=$jobs->run($created['id'],$key,new GeminiImages('k',60,player($requests,[imageAnswer(png(64,64))])));
    check($ran['status']==='completed'&&count($ran['image_ids'])===1,'a Nano Banana request makes and saves its image');
    $image=$vault->image($ran['image_ids'][0],$key);
    check(($image['usage']['provider']??null)==='gemini'&&$image['options']['model']==='gemini-nano-banana-2.1','the image keeps its model and usage');
    $vault->setBilling(['balance'=>10,'topup'=>0,'day'=>1],$key);
    $vault->db->exec('UPDATE spend SET created=created+5');
    $q=$vault->db->prepare('SELECT data FROM spend WHERE job_id=?');$q->execute([$created['id']]);
    $entries=json_decode(Crypto::open((string)$q->fetchColumn(),$key,'spend:'.$created['id']),true)['entries'];$q->closeCursor();
    check(count($entries)===1&&$entries[0]['model']==='gemini-nano-banana-2.1','what it cost is kept with the request');
    check($vault->balance($key)['spent']===[],'but not counted in the OpenAI balance: Google is paid apart');
    check($vault->balance($key)['accounts']['gemini']['spent']===[],'nor in Gemini\'s before its balance is entered');
    $vault->setBilling(['balance'=>10,'topup'=>0,'day'=>1],$key,'gemini');
    $vault->db->exec('UPDATE spend SET created=created+5');
    $vault->db->prepare('UPDATE spend SET data=CAST(? AS BLOB) WHERE job_id=?')->execute([Crypto::seal(json_encode(['entries'=>array_map(fn($e)=>['at'=>$e['at']+5]+$e,$entries)]),$key,'spend:'.$created['id']),$created['id']]);
    $google=$vault->balance($key)['accounts']['gemini']['spent'];
    check(count($google)===1&&$google[0]['model']==='gemini-nano-banana-2.1'&&$google[0]['kind']==='image'&&$google[0]['requests']===1
        &&abs($google[0]['cost']-GeminiImages::cost('gemini-nano-banana-2.1',$image['usage'],(string)$image['usage']['tier']))<1e-6&&$google[0]['cost']>0,'it is counted in Gemini\'s balance once that is entered, priced from its tokens');
    $vault->setBilling(null,$key,'gemini');
    $failed=$jobs->create($new(),$key)[0];
    $ran=$jobs->run($failed['id'],$key,new GeminiImages('k',60,player($requests,[['status'=>429,'body'=>json_encode(['error'=>['code'=>429,'message'=>'check your plan and billing details','status'=>'RESOURCE_EXHAUSTED']])]])));
    check($ran['status']==='failed'&&$ran['error_kind']==='gemini-credit'&&$ran['error']===Gemini::noCreditMessage(),'no credit at Google fails the request with its own notice');

    // ---------------------------------------------------------------------------------------------------------------
    // Gemini as the writer
    $stories=new Stories($vault);
    $writer=new Writer($vault,$stories,$jobs,['api_timeout'=>60]);
    check(Writer::settings(null,['provider'=>'gemini'])['model']==='gemini-4-neon'&&Writer::settings(null,['provider'=>'gemini','model'=>'gemini-4-argon'])['model']==='gemini-4-argon'
        &&Writer::settings(null,['provider'=>'gemini','model'=>'gemini-3.8-flash'])['model']==='gemini-3.8-flash','the writer can be Gemini 4 Neon (the default), Gemini 4 Argon or Gemini 3.8 Flash');
    check(Writer::settings(null,['image_model'=>'gemini-3-pro-image'])['image_model']==='gemini-3-pro-image','a story can be illustrated with Nano Banana');
    check(Writer::catalog()['gemini']['gemini-4-neon']==='Gemini 4 Neon'&&Writer::catalog()['gemini']['gemini-4-argon']==='Gemini 4 Argon'
        &&Writer::catalog()['gemini_efforts']['gemini-4-neon']===['low','medium','high','xhigh']&&Writer::catalog()['gemini_efforts']['gemini-4-argon']===['low','medium','high'],'the page gets the Gemini writers and the reasoning levels of each');
    $tools=Writer::geminiTools();
    $byName=array_column($tools,null,'name');
    check(!isset($byName['get_outline']['parameters'])&&$byName['write_chapter']['parameters']['required']===['title','text']&&$byName['write_chapter']['type']==='function','tools without inputs go without parameters; the others with their schema');
    $vault->db->exec('DELETE FROM users WHERE 0'); // nothing: keeps the key
    $story=$stories->create('O Farol',$key);$sid=$story['id'];
    $stories->update($sid,['writer'=>Writer::settings(null,['provider'=>'gemini','image_model'=>'gemini-nano-banana-2.1'])],$key);
    $requests=[];
    $chapterText="A névoa cobria a ilha.\n\nMarina chegou.";
    $answers=[
        geminiEvents([['type'=>'thought','summary'=>'Vou escrever.','signature'=>'SIG-1'],
            ['type'=>'function_call','id'=>'call-1','name'=>'write_chapter','arguments'=>['title'=>'A névoa','text'=>$chapterText,'summary'=>'Marina chega.']]],'requires_action',
            ['total_input_tokens'=>5000,'total_cached_tokens'=>0,'total_output_tokens'=>400,'total_thought_tokens'=>100,'total_tokens'=>5500]),
        geminiEvents([['type'=>'text','text'=>'Escrevi o capítulo 1.']],'completed',['total_input_tokens'=>5600,'total_cached_tokens'=>5000,'total_output_tokens'=>20,'total_thought_tokens'=>0,'total_tokens'=>5620]),
    ];
    $w=$writer->withTransports(null,null,player($requests,$answers));
    $turn=$w->run($w->send($sid,'Escreva o primeiro capítulo.',[],$key)['id'],$key);
    check($turn['status']==='completed'&&$stories->chapters($sid,$key)[0]['title']==='A névoa','Gemini writes with the same tools');
    $g=$requests[0]['body'];
    check($requests[0]['url']===Gemini::URL&&$g['model']==='gemini-4-neon'&&$g['store']===false&&$g['system_instruction']===Writer::SYSTEM&&$g['generation_config']['thinking_level']==='high'
        &&$g['generation_config']['thinking_summaries']==='auto'&&count($g['tools'])===count(Writer::tools())&&count($g['safety_settings'])===4,'Gemini gets the same instructions and tools, nothing stored at Google, filters off');
    check($g['input'][0]['type']==='user_input'&&str_contains($g['input'][0]['content'][0]['text'],'<story_state>')&&str_contains($g['input'][0]['content'][0]['text'],'Google Gemini credit for illustrations: unknown (the person has not entered the balance in the app)')
        &&str_contains($g['input'][0]['content'][0]['text'],'pictures are made with Nano Banana 2.1 · Gemini (Google)'),'the writer is told the pictures are Nano Banana\'s, paid to Google');
    $in=$requests[1]['body']['input'];
    check(json_encode(array_slice($in,0,count($g['input'])))===json_encode($g['input'])&&array_column(array_slice($in,count($g['input'])),'type')===['thought','function_call','function_result'],'within the turn the input only grows, for the cache');
    $tail=array_slice($in,count($g['input']));
    check($tail[0]['signature']==='SIG-1'&&$tail[1]['id']==='call-1'&&$tail[2]['call_id']==='call-1'&&$tail[2]['name']==='write_chapter'&&str_contains($tail[2]['result'][0]['text'],'A névoa'),'the thought goes back with its signature, and the result answers the call by its id and name');
    $spent=$w->spent($sid,$key)['gemini'];
    check(abs($spent-round(((5000*3+500*15)+(600*3+5000*0.15+20*15))/1e6,4))<1e-9,'what Gemini cost is counted at its prices, the cache read at a twentieth');
    $usage=$w->usage($sid,$key)['total']['gemini'];
    check($usage['read']===5000&&$usage['input']===5600&&$usage['output']===520&&$usage['thinking']===100&&$usage['requests']===2,'the usage panel shows what came from the cache, the input, the output and the thinking');
    $chat=$w->chat($sid,$key);$reply=array_values(array_filter($chat,fn($m)=>$m['role']==='assistant'));
    check($reply[0]['updates']===['Vou escrever.']&&end($reply)['text']==='Escrevi o capítulo 1.','the chat shows the thinking summary and the reply');

    // Switching to Claude: Gemini's calls and results translated, its thoughts left out
    $vault->setClaudeKey('sk-ant-test-placeholder-key',$key);
    $claudeRequests=[];
    $claudeAnswer=[['message_start',['type'=>'message_start','message'=>['id'=>'m','model'=>'claude-opus-5-5','role'=>'assistant','content'=>[],'usage'=>['input_tokens'=>10,'output_tokens'=>5]]]],
        ['content_block_start',['type'=>'content_block_start','index'=>0,'content_block'=>['type'=>'thinking','thinking'=>'','signature'=>'']]],
        ['content_block_delta',['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'signature_delta','signature'=>'csig']]],['content_block_stop',['type'=>'content_block_stop','index'=>0]],
        ['content_block_start',['type'=>'content_block_start','index'=>1,'content_block'=>['type'=>'tool_use','id'=>'toolu_9','name'=>'read_notes','input'=>[]]]],
        ['content_block_delta',['type'=>'content_block_delta','index'=>1,'delta'=>['type'=>'input_json_delta','partial_json'=>'{}']]],['content_block_stop',['type'=>'content_block_stop','index'=>1]],
        ['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>'tool_use'],'usage'=>['output_tokens'=>5]]],['message_stop',['type'=>'message_stop']]];
    $claudeDone=[['message_start',['type'=>'message_start','message'=>['id'=>'m2','model'=>'claude-opus-5-5','role'=>'assistant','content'=>[],'usage'=>['input_tokens'=>10,'output_tokens'=>5]]]],
        ['content_block_start',['type'=>'content_block_start','index'=>0,'content_block'=>['type'=>'text','text'=>'']]],['content_block_delta',['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'Li a bíblia.']]],
        ['content_block_stop',['type'=>'content_block_stop','index'=>0]],['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>5]]],['message_stop',['type'=>'message_stop']]];
    $w=$writer->withTransports(player($claudeRequests,[$claudeAnswer,$claudeDone]),null,null);
    $turn=$w->run($w->send($sid,'Agora você, Claude.',['provider'=>'claude','model'=>'claude-opus-5-5'],$key)['id'],$key);
    $msgs=$claudeRequests[0]['body']['messages'];
    $flat=json_encode($msgs,JSON_UNESCAPED_UNICODE);
    $uses=[];$results=[];
    foreach($msgs as $m)foreach($m['content'] as $bk){ if($bk['type']==='tool_use')$uses[]=$bk; if($bk['type']==='tool_result')$results[]=$bk; }
    check($turn['status']==='completed'&&count($uses)===1&&$uses[0]['id']==='call-1'&&$uses[0]['name']==='write_chapter'&&$uses[0]['input']['title']==='A névoa'&&$results[0]['tool_use_id']==='call-1'
        &&!str_contains($flat,'SIG-1')&&str_contains($flat,'Escrevi o capítulo 1.')&&str_contains($flat,'you are now Claude Opus 5.5, taking over this conversation from Gemini 4 Neon'),'switching to Claude keeps the conversation: Gemini\'s calls and results translated, its thoughts left out');

    // And back to Gemini: its own steps as they were, Claude's translated, the calls named
    $requests=[];
    $w=$writer->withTransports(null,null,player($requests,[geminiEvents([['type'=>'text','text'=>'Voltei.']])]));
    $turn=$w->run($w->send($sid,'Volte, Gemini.',['provider'=>'gemini','model'=>'gemini-4-neon'],$key)['id'],$key);
    $in=$requests[0]['body']['input'];
    check(json_encode(array_slice($in,0,count($g['input'])+3))===json_encode(array_slice($requests[0]['body']['input'],0,count($g['input'])+3))&&$in[count($g['input'])]['signature']==='SIG-1','back on Gemini, its own thought goes back with its signature');
    $calls=array_values(array_filter($in,fn($s)=>$s['type']==='function_call'));$res=array_values(array_filter($in,fn($s)=>$s['type']==='function_result'));
    check(array_column($calls,'name')===['write_chapter','read_notes']&&$calls[1]['id']==='toolu_9'&&str_contains($requests[0]['raw'],'"id":"toolu_9","name":"read_notes","arguments":{}')&&$res[1]['call_id']==='toolu_9'&&$res[1]['name']==='read_notes'
        &&!str_contains(json_encode($in),'csig'),'Claude\'s calls go to Gemini as calls with their names and results, its thinking left out');
    $types=array_column($in,'type');
    for($i=1;$i<count($types);$i++) if($types[$i]==='user_input'&&$types[$i-1]==='user_input') throw new RuntimeException('two user steps in a row');
    check(end($in)['type']==='user_input'&&str_contains(json_encode(end($in),JSON_UNESCAPED_UNICODE),'Volte, Gemini.'),'what the person says in a row goes as one step, ending the request');

    // A switch to the other Gemini model leaves the thoughts out (only the model that made them reads them)
    $requests=[];
    $w=$writer->withTransports(null,null,player($requests,[geminiEvents([['type'=>'text','text'=>'Oi.']],'completed',['total_input_tokens'=>10,'total_output_tokens'=>1],'gemini-3.8-flash')]));
    $w->run($w->send($sid,'Oi, Flash.',['provider'=>'gemini','model'=>'gemini-3.8-flash'],$key)['id'],$key);
    check(!in_array('thought',array_column($requests[0]['body']['input'],'type'),true)&&$requests[0]['body']['model']==='gemini-3.8-flash','thoughts of another Gemini model stay behind');

    // Gemini refusing the translated calls: they go as text from then on
    $requests=[];
    $signature=['status'=>400,'body'=>json_encode(['error'=>['code'=>'missing_thought_signature','message'=>'Function call is missing a thought signature.']])];
    $w=$writer->withTransports(null,null,player($requests,[$signature,geminiEvents([['type'=>'text','text'=>'Segui.']]),geminiEvents([['type'=>'text','text'=>'De novo.']])]));
    $turn=$w->run($w->send($sid,'Siga.',['provider'=>'gemini','model'=>'gemini-4-argon'],$key)['id'],$key);
    $retry=$requests[1]['body']['input'];
    $names=array_column(array_filter($retry,fn($s)=>$s['type']==='function_call'),'name');
    check($turn['status']==='completed'&&$names===['write_chapter']&&str_contains(json_encode($retry,JSON_UNESCAPED_UNICODE),'[Called read_notes with {}]'),'a refused signature sends the other writers\' calls as text, Gemini\'s own stay calls');
    $w->run($w->send($sid,'Mais.',[],$key)['id'],$key);
    check(str_contains(json_encode($requests[2]['body']['input'],JSON_UNESCAPED_UNICODE),'[Called read_notes with {}]'),'and the conversation stays that way, so the cache holds');

    // A call Gemini couldn't write: the writer is told and goes on
    $requests=[];
    $w=$writer->withTransports(null,null,player($requests,[geminiEvents([],'failed',['total_input_tokens'=>10,'total_output_tokens'=>0],'gemini-4-argon',['errors'=>[['code'=>'malformed_function_call']]]),geminiEvents([['type'=>'text','text'=>'Corrigi.']])]));
    $turn=$w->run($w->send($sid,'Continue.',[],$key)['id'],$key);
    check($turn['status']==='completed'&&str_contains(json_encode(end($requests[1]['body']['input']),JSON_UNESCAPED_UNICODE),'could not be read'),'a malformed call is told to the writer, which writes it again');

    // A story written with Gemini goes to a file and back: its messages and the calls-as-text choice with them
    $pack=json_decode(json_encode(['conversation'=>$writer->exportConversation($sid,$key)]+$stories->export($sid,$key,$jobs),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);
    $talk=$pack['conversation'];unset($talk['messages']);
    $made=$stories->import($pack,Writer::settings(null,$pack['story']['writer']),$key);$iid=$made['id'];
    $writer->importConversation($iid,['title'=>'O Farol']+$talk,$made['map'],$key);
    $page=$writer->exportMessages($sid,0,$key)['messages'];
    $asText=array_map(fn($m)=>['content'=>json_encode($m['content'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]+$m,json_decode(json_encode($page,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true));
    check(in_array('gemini',array_column(array_column($page,'content'),'provider'),true)&&$writer->importMessages($iid,$asText,true,$key)['added']===count($page),'a Gemini conversation goes to a file and comes back');
    $requests=[];
    $w=$writer->withTransports(null,null,player($requests,[geminiEvents([['type'=>'text','text'=>'Na importada.']])]));
    $turn=$w->run($w->send($iid,'Siga.',[],$key)['id'],$key);
    check($turn['status']==='completed'&&count($requests)===1&&str_contains(json_encode($requests[0]['body']['input'],JSON_UNESCAPED_UNICODE),'[Called read_notes with {}]'),'and Gemini goes on there as before, the other writers\' calls still as text');
    $stories->delete($iid);

    // Without the key, Gemini can't write
    $vault->setGeminiKey(null,$key);
    rejects(fn()=>$w->send($sid,'Oi.',[],$key),'writing with Gemini needs the Gemini key',422,'chave da API do Gemini');

    echo "\n$count Gemini checks passed. No real API calls.\n";
} finally {
    if (is_dir($dir)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); rmdir($dir); }
}
