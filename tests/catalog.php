<?php
declare(strict_types=1);
// The model lists of the keys (Catalog): newer models offered like the closest known one, older and other kinds left out,
// fetched with each provider's own request. Fake transports: no real API calls.
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Vault,Users,HttpError,Writer,Claude,Gpt,Gemini,GeminiImages,OpenAI,Images,Catalog,KeyTest};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
$row=fn(string $id,string $name='',?array $methods=null)=>['id'=>$id,'name'=>$name,'methods'=>$methods];

$dir=sys_get_temp_dir().'/imagegen-catalog-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    // Nothing listed: only the app's own models
    Catalog::use(null);
    check(array_keys(Catalog::writers('claude'))===array_keys(Claude::MODELS)&&Catalog::images()===[]&&Catalog::fetched()===0,'without lists the app offers its own models');

    // Lists as the providers give them
    Catalog::use(['at'=>1795200000,'lists'=>[
        'claude'=>[$row('claude-opus-5-5','Claude Opus 5.5'),$row('claude-opus-5-5-20261001','Claude Opus 5.5'),$row('claude-opus-5-6','Claude Opus 5.6'),$row('claude-opus-5-6-20261201','Claude Opus 5.6'),
            $row('claude-haiku-5-5','Claude Haiku 5.5'),$row('claude-opus-4-8','Claude Opus 4.8'),$row('claude-sonnet-5-7-20261215')],
        'openai'=>[$row('gpt-6.2-sol'),$row('gpt-6.3-sol'),$row('gpt-6.3-astra'),$row('gpt-6-sol'),$row('gpt-6.3-sol-2026-11-01'),$row('gpt-6.3-audio'),$row('gpt-6.3-realtime'),
            $row('gpt-image-2.6-flare'),$row('gpt-image-2.7-flare'),$row('gpt-image-1'),$row('gpt-image-2-mini'),$row('chatgpt-image-latest'),$row('o9')],
        'gemini'=>[$row('gemini-4-neon','Gemini 4 Neon',['generateContent']),$row('gemini-4-hydrogen','Gemini 4 Hydrogen',['generateContent','countTokens']),$row('gemini-4-hydrogen-001','Gemini 4 Hydrogen',['generateContent']),
            $row('gemini-2.5-pro','Gemini 2.5 Pro',['generateContent']),$row('gemini-3.8-flash-tts','TTS',['generateContent']),$row('gemini-4-flash-lite','Gemini 4 Flash-Lite',['generateContent']),
            $row('gemini-embedding-2','Embedding',['embedContent']),$row('gemini-nano-banana-2.2','Nano Banana 2.2',['generateContent']),$row('gemini-nano-banana-2.1','Nano Banana 2.1',['generateContent']),
            $row('gemini-3.1-flash-image','Nano Banana 2',['generateContent']),$row('gemini-5-pro','Gemini 5 Pro',['countTokens'])],
    ]]);
    $claude=Catalog::writers('claude');
    check(array_slice(array_keys($claude),0,count(Claude::MODELS))===array_keys(Claude::MODELS)&&array_keys(array_diff_key($claude,Claude::MODELS))===['claude-opus-5-6','claude-haiku-5-5','claude-sonnet-5-7-20261215'],
        'Claude: the newer models after the app\'s, without dated copies or older generations');
    check($claude['claude-opus-5-6']['label']===Catalog::estimated('Claude Opus 5.6')&&$claude['claude-opus-5-6']['like']==='claude-opus-5-5'&&$claude['claude-opus-5-6']['output']===Claude::MODELS['claude-opus-5-5']['output']
        &&$claude['claude-sonnet-5-7-20261215']['like']==='claude-sonnet-5-5'&&$claude['claude-sonnet-5-7-20261215']['label']===Catalog::estimated('Claude Sonnet 5.7'),'a new one borrows the settings and prices of its family, and says its price is estimated');
    $gpt=Catalog::writers('openai');
    check(array_keys(array_diff_key($gpt,Gpt::MODELS))===['gpt-6.3-sol','gpt-6.3-astra']&&$gpt['gpt-6.3-astra']['like']==='gpt-6.2-astra'&&$gpt['gpt-6.3-sol']['label']===Catalog::estimated('GPT-6.3 Sol'),
        'GPT: newer writers only, not audio, realtime, dated or older ones, each like its family');
    $gemini=Catalog::writers('gemini');
    check(array_keys(array_diff_key($gemini,Gemini::MODELS))===['gemini-4-hydrogen','gemini-4-flash-lite']&&$gemini['gemini-4-hydrogen']['like']==='gemini-4-neon'&&$gemini['gemini-4-flash-lite']['like']==='gemini-3.8-flash'
        &&$gemini['gemini-4-hydrogen']['efforts']===Gemini::MODELS['gemini-4-neon']['efforts'],'Gemini: newer text models that generate, not speech, embeddings or older ones');
    $images=Catalog::images();
    check(array_keys($images)===['gpt-image-2.7-flare','gemini-nano-banana-2.2']&&$images['gpt-image-2.7-flare']['provider']==='openai'&&$images['gpt-image-2.7-flare']['like']==='gpt-image-2.6-flare'
        &&$images['gemini-nano-banana-2.2']['provider']==='gemini'&&$images['gemini-nano-banana-2.2']['like']==='gemini-nano-banana-2.1','images: newer GPT Image and Nano Banana models, each like the one it follows');

    // The rest of the app takes them
    $models=Images::models();
    check($models['gpt-image-2.7-flare']['label']===Catalog::estimated('GPT Image 2.7 · Flare')&&$models['gemini-nano-banana-2.2']['estimated']===true&&!isset($models['gemini-nano-banana-2.2']['prices'])
        &&Images::provider('gemini-nano-banana-2.2')==='gemini'&&Images::provider('gpt-image-2.7-flare')==='openai'&&Images::maxImages('gemini-nano-banana-2.2')===14,'the page gets the new image models, without Google\'s token prices');
    check(Images::options(['model'=>'gpt-image-2.7-flare','quality'=>'high','size'=>'1024x1024'])['model']==='gpt-image-2.7-flare'&&Images::options(['model'=>'gemini-nano-banana-2.2','quality'=>'high','size'=>'1024x1024'])['model']==='gemini-nano-banana-2.2'
        &&GeminiImages::meta('gemini-nano-banana-2.2')['per_image']===GeminiImages::MODELS['gemini-nano-banana-2.1']['per_image']&&OpenAI::meta('gemini-nano-banana-2.2')===null,'image requests accept them, with the settings of the closest model');
    check(Writer::settings(null,['provider'=>'claude','model'=>'claude-opus-5-6'])['model']==='claude-opus-5-6'&&Writer::settings(null,['provider'=>'openai','model'=>'gpt-6.3-sol'])['model']==='gpt-6.3-sol'
        &&Writer::catalog()['gemini']['gemini-4-hydrogen']===Catalog::estimated('Gemini 4 Hydrogen')&&isset(Writer::catalog()['gemini_efforts']['gemini-4-hydrogen'])&&Writer::catalog()['fetched']===1795200000,'the writer can be any of them');
    $u=['input_tokens'=>1000000,'output_tokens'=>1000000];
    check(abs(Claude::cost('claude-opus-5-6',$u)-Claude::cost('claude-opus-5-5',$u))<1e-9&&abs(Gpt::cost('gpt-6.3-astra',$u)-Gpt::cost('gpt-6.2-astra',$u))<1e-9
        &&abs(Gemini::cost('gemini-4-hydrogen',['total_input_tokens'=>1000000])-3.0)<1e-9&&Gemini::thinking('max','gemini-4-hydrogen')==='xhigh','and costs them at the borrowed prices');
    try { Writer::settings(null,['provider'=>'claude','model'=>'claude-opus-4-8']); check(false,'an older model is refused'); } catch (HttpError $e) { check($e->status===422,'an older model is refused'); }

    // Fetching, with each provider's request, pages and failures
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-openai-key-0123456789',$key);$vault->setClaudeKey('sk-ant-test-claude-key-0123456789',$key);
    $calls=[];
    $transport=function(string $provider,string $url,array $headers) use (&$calls){
        $calls[]=[$provider,$url,$headers];
        if($provider==='claude') return ['status'=>200,'errno'=>0,'body'=>json_encode(str_contains($url,'after_id=')
            ? ['data'=>[['id'=>'claude-fable-5-3','display_name'=>'Claude Fable 5.3']],'has_more'=>false]
            : ['data'=>[['id'=>'claude-opus-5-6','display_name'=>'Claude Opus 5.6']],'has_more'=>true,'last_id'=>'claude-opus-5-6'])];
        return ['status'=>200,'errno'=>0,'body'=>json_encode(['object'=>'list','data'=>[['id'=>'gpt-6.4-sol','object'=>'model'],['id'=>'whisper-2','object'=>'model']]])];
    };
    $stored=Catalog::refresh($vault,$key,$transport);
    check(array_column($calls,0)===['claude','claude','openai']&&in_array('x-api-key: sk-ant-test-claude-key-0123456789',$calls[0][2],true)&&in_array('anthropic-version: '.Claude::VERSION,$calls[0][2],true)
        &&str_ends_with($calls[1][1],'&after_id=claude-opus-5-6')&&in_array('Authorization: Bearer sk-test-openai-key-0123456789',$calls[2][2],true),'each provider is asked with its own key and headers, Claude a page at a time; no Gemini key, no Gemini request');
    check(array_column($stored['lists']['claude'],'id')===['claude-opus-5-6','claude-fable-5-3']&&array_column($stored['lists']['openai'],'id')===['gpt-6.4-sol']&&$stored['lists']['gemini']===[]
        &&$vault->modelLists($key)['lists']['claude'][1]['name']==='Claude Fable 5.3'&&Catalog::fetched()>=time()-5,'the lists are kept in the account, only the names that matter');
    check(isset(Catalog::writers('claude')['claude-fable-5-3'])&&isset(Catalog::writers('openai')['gpt-6.4-sol'])&&!isset(Catalog::writers('gemini')['gemini-4-hydrogen']),'and put to use at once');
    $vault->setGeminiKey('AIzaTestGeminiKey0123456789abcdef',$key);$calls=[];
    $failing=function(string $provider,string $url,array $headers) use (&$calls){
        $calls[]=[$provider,$url,$headers];
        if($provider==='gemini') return ['status'=>200,'errno'=>0,'body'=>json_encode(str_contains($url,'pageToken=')
            ? ['models'=>[['name'=>'models/gemini-4-hydrogen','displayName'=>'Gemini 4 Hydrogen','supportedGenerationMethods'=>['generateContent']]]]
            : ['models'=>[['name'=>'models/gemini-4-neon','displayName'=>'Gemini 4 Neon','supportedGenerationMethods'=>['generateContent']]],'nextPageToken'=>'p2'])];
        return $provider==='claude' ? ['status'=>500,'errno'=>0,'body'=>'{}'] : ['status'=>401,'errno'=>0,'body'=>'{}'];
    };
    $stored=Catalog::refresh($vault,$key,$failing);
    check(array_column($stored['lists']['claude'],'id')===['claude-opus-5-6','claude-fable-5-3']&&$stored['lists']['openai']===[['id'=>'gpt-6.4-sol','name'=>'','methods'=>null]]
        &&str_contains((string)$stored['errors']->claude,'HTTP 500')&&str_contains((string)$stored['errors']->openai,'não foi aceita'),'a provider that fails keeps its last list, and says why');
    check(in_array('x-goog-api-key: AIzaTestGeminiKey0123456789abcdef',$calls[2][2],true)&&str_ends_with($calls[3][1],'&pageToken=p2')&&array_column($stored['lists']['gemini'],'id')===['gemini-4-neon','gemini-4-hydrogen']
        &&isset(Catalog::writers('gemini')['gemini-4-hydrogen']),'Gemini\'s list comes a page at a time, with the Gemini key');
    $vault->setClaudeKey(null,$key);
    Catalog::refresh($vault,$key,$failing);
    check(array_keys(Catalog::writers('claude'))===array_keys(Claude::MODELS),'without the key, its newer models go away');
    Catalog::use(null);

    // Testar, next to the OpenAI and Claude keys: the key and the models the app uses (the free list), then one tiny
    // request that shows a key without credit
    $openaiList=fn(string $p,string $url,array $h)=>['status'=>200,'errno'=>0,'body'=>json_encode(['data'=>array_map(fn($id)=>['id'=>$id],
        array_merge(array_diff(array_keys(OpenAI::MODELS),['gpt-image-1-mini']),[OpenAI::PLANNER,'gpt-6.2-sol','gpt-6.2-astra-2026-10-01','o9']))])];
    $posted=[];
    $answer=function(int $status,array $body) use (&$posted){ return function(string $url,array $headers,array $sent) use (&$posted,$status,$body){ $posted[]=[$url,$headers,$sent]; return ['status'=>$status,'errno'=>0,'body'=>json_encode($body)]; }; };
    $r=KeyTest::openai('sk-test-key',$openaiList,$answer(200,['status'=>'completed','usage'=>['input_tokens'=>20,'output_tokens'=>30]]));
    check($r['ok']===true&&$r['steps'][0]['name']==='Chave e modelos'&&str_contains($r['steps'][0]['detail'],'não lista gpt-image-1-mini:')&&!str_contains($r['steps'][0]['detail'],'gpt-6.2-astra'),
        'the OpenAI test names a model the key does not list (a dated copy counts as listed)');
    check($posted[0][0]==='https://api.openai.com/v1/responses'&&$posted[0][1]===['Authorization: Bearer sk-test-key']&&$posted[0][2]['model']===OpenAI::PLANNER&&$posted[0][2]['store']===false&&$posted[0][2]['max_output_tokens']===400
        &&str_contains($r['steps'][1]['detail'],'US$ 0,0003'),'then asks the planner for one word, kept nowhere, and says what it cost');
    $r=KeyTest::openai('sk-test-key',$openaiList,$answer(429,['error'=>['code'=>'insufficient_quota','type'=>'insufficient_quota']]));
    check($r['ok']===false&&$r['steps'][0]['ok']===true&&$r['steps'][1]['detail']===OpenAI::noCreditMessage(),'a key without credit passes the first step and fails the second, saying so');
    $posted=[];
    $r=KeyTest::openai('sk-bad',fn($p,$u,$h)=>['status'=>401,'errno'=>0,'body'=>'{}'],$answer(200,[]));
    check($r['ok']===false&&count($r['steps'])===1&&str_contains($r['steps'][0]['detail'],'não foi aceita')&&$posted===[],'a refused key stops at the first step, with nothing paid');
    $claudeList=fn($p,$url,$h)=>['status'=>200,'errno'=>0,'body'=>json_encode(['data'=>array_map(fn($id)=>['id'=>$id],['claude-opus-5-5-20261001','claude-sonnet-5-5','claude-fable-5-2']),'has_more'=>false])];
    $posted=[];
    $r=KeyTest::claude('sk-ant-test',$claudeList,$answer(200,['content'=>[['type'=>'text','text'=>'OK']],'usage'=>['input_tokens'=>18,'output_tokens'=>2]]));
    check($r['ok']===true&&$r['steps'][0]['detail']==='Chave aceita, com todos os modelos que o app usa.'&&$posted[0][0]===Claude::URL&&in_array('x-api-key: sk-ant-test',$posted[0][1],true)
        &&$posted[0][2]['model']===KeyTest::CLAUDE_MODEL&&$posted[0][2]['max_tokens']===16,'the Claude test: every model listed, then one short answer from the cheaper writer');
    $r=KeyTest::claude('sk-ant-test',$claudeList,$answer(400,['error'=>['type'=>'invalid_request_error','message'=>'Your credit balance is too low to access the Anthropic API.']]));
    check($r['ok']===false&&$r['steps'][1]['detail']===Claude::noCreditMessage(),'and a Claude key without credit is told apart');
    $r=KeyTest::claude('sk-ant-test',$claudeList,fn()=>['status'=>0,'errno'=>6,'body'=>'']);
    check($r['ok']===false&&str_contains($r['steps'][1]['detail'],'erro de rede 6'),'a request that never reaches the provider says so');

    echo "\n$count catalog checks passed. No real API calls.\n";
} finally {
    Catalog::use(null);
    if (is_dir($dir)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); rmdir($dir); }
}
