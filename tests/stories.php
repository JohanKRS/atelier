<?php
declare(strict_types=1);
// Stories and the writing agent, with Claude and GPT played back by fake transports: no real API calls.
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Crypto,Vault,OpenAI,Jobs,Users,HttpError,Stories,Writer,Claude,Gpt,Cancelled,Presets};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function rejects(callable $fn,string $message,?int $status=null): void {
    try{$fn();}catch(Throwable $e){ if($status!==null && (!$e instanceof HttpError || $e->status!==$status)) throw new RuntimeException($message.' (got '.get_class($e).': '.$e->getMessage().')'); check(true,$message);return;}
    throw new RuntimeException($message);
}
/** The events of one Claude answer, as the API streams them. */
function claudeEvents(array $blocks,string $stop,array $usage=['input_tokens'=>1000,'output_tokens'=>200,'cache_read_input_tokens'=>0,'cache_creation_input_tokens'=>0],string $model='claude-opus-5-5'): array {
    $events=[['message_start',['type'=>'message_start','message'=>['id'=>'msg_1','model'=>$model,'role'=>'assistant','content'=>[],'usage'=>$usage]]]];
    foreach($blocks as $i=>$b){
        if($b['type']==='tool_use'){
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'tool_use','id'=>$b['id'],'name'=>$b['name'],'input'=>[]]]];
            $json=$b['raw']??json_encode($b['input'],JSON_UNESCAPED_UNICODE);
            foreach(str_split($json,7) as $part)$events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'input_json_delta','partial_json'=>$part]]];
        }elseif($b['type']==='text'){
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'text','text'=>'']]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'text_delta','text'=>$b['text']]]];
        }elseif($b['type']==='thinking'){
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'thinking','thinking'=>'','signature'=>'']]];
            if($b['thinking']!=='')$events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'thinking_delta','thinking'=>$b['thinking']]]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'signature_delta','signature'=>'sig-'.$i]]];
        }
        $events[]=['content_block_stop',['type'=>'content_block_stop','index'=>$i]];
    }
    $events[]=['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>$stop,'stop_details'=>null],'usage'=>['output_tokens'=>$usage['output_tokens']]]];
    $events[]=['message_stop',['type'=>'message_stop']];
    return $events;
}
/** A request body without its cache breakpoints, which move forward from one request to the next. */
function uncached(mixed $v): mixed { if(!is_array($v))return $v; unset($v['cache_control']); return array_map('uncached',$v); }
/** The cache breakpoints of a request: where each one is (system, tools, messages.N.content.M or the top level). */
function breakpoints(array $body,string $at=''): array {
    $found=[]; foreach($body as $k=>$v){ if($k==='cache_control'&&is_array($v))$found[]=$at===''?'top':$at; elseif(is_array($v))array_push($found,...breakpoints($v,$at===''?(string)$k:"$at.$k")); }
    return $found;
}
/** A fake transport that plays the given answers in order and keeps every request. */
function player(array &$requests,array $answers): Closure {
    $n=0;
    return function(string $url,array $headers,array $body,Closure $onEvent,Closure $tick,int $timeout) use (&$requests,&$n,$answers): array {
        // The body goes through JSON as it would on the wire.
        $requests[]=['url'=>$url,'headers'=>$headers,'body'=>json_decode(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),true),'raw'=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $answer=$answers[$n++]??throw new RuntimeException('No more fake answers');
        if($answer instanceof Closure)return $answer($onEvent,$tick);
        if(isset($answer['status']))return $answer+['sent'=>true,'errno'=>0,'retry_after'=>null];
        foreach($answer as [$event,$data])$onEvent($event,$data);
        return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
    };
}

$dir=sys_get_temp_dir().'/imagegen-stories-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $stories=new Stories($vault);$jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>4]);
    $writer=new Writer($vault,$stories,$jobs,['api_timeout'=>60]);

    // ---------------------------------------------------------------------------------------------------------------
    // The story itself
    $story=$stories->create('  O Farol   de Vidro ',$key);$sid=$story['id'];
    check($story['title']==='O Farol de Vidro'&&$story['chapters']===0,'a story is created with a clean title');
    $one=$stories->addChapter($sid,'A chegada',"Primeira linha.\r\n\r\nSegunda linha com Marina.",'',null,'person',$key);
    $two=$stories->addChapter($sid,'O mapa','Texto do mapa.','',null,'person',$key);
    $zero=$stories->addChapter($sid,'Prólogo','Antes de tudo.','',0,'person',$key);
    check(array_column($stories->chapters($sid,$key),'title')===['Prólogo','A chegada','O mapa'],'chapters keep their order, and one can be put first');
    check($one['text']==="Primeira linha.\n\nSegunda linha com Marina."&&Stories::words($one["text"])===6,'line ends are normalized and words counted');
    $stories->moveChapter($sid,$zero['id'],2,$key);
    check(array_column($stories->chapters($sid,$key),'title')===['A chegada','O mapa','Prólogo'],'a chapter moves to another place');
    $stories->moveChapter($sid,$zero['id'],0,$key);
    $saved=$stories->updateChapter($sid,$one['id'],['text'=>'Novo texto.'],'person',$key,$one['rev']);
    check($saved['rev']===$one['rev']+1,'an edit by hand makes a new revision');
    rejects(fn()=>$stories->updateChapter($sid,$one['id'],['text'=>'Outro.'],'person',$key,$one['rev']),'an edit from an old revision is refused, never overwriting',409);
    rejects(fn()=>Stories::applyEdits('a b a','a'===''?[]:[['old_text'=>'a','new_text'=>'c']]),'a replacement whose text appears twice is refused');
    rejects(fn()=>Stories::applyEdits('abc',[['old_text'=>'x','new_text'=>'y']]),'a replacement whose text is missing is refused');
    check(Stories::applyEdits('um dois três',[['old_text'=>'dois','new_text'=>'2'],['old_text'=>'três','new_text'=>'3']])==='um 2 3','replacements apply in order');
    $doc=$stories->writeDocument($sid,'notes',['text'=>"Marina: 30 anos, olhos verdes."],'person',$key);
    $doc=$stories->writeDocument($sid,'notes',['append'=>'Farol: ilha do Norte.'],'agent',$key);
    check($doc['text']==="Marina: 30 anos, olhos verdes.\n\nFarol: ilha do Norte."&&$doc['rev']===2&&$doc['by']==='agent','the bible takes text added at the end');
    rejects(fn()=>$stories->writeDocument($sid,'notes',['text'=>'x'],'person',$key,1),'an edit of the bible from an old revision is refused',409);
    $stories->writeDocument($sid,'plan',['text'=>'SEGREDO: o faroleiro é o pai de Marina.'],'person',$key);
    $page=$stories->get($sid,$key);
    check(!isset($page['plan']['text'])&&$page['plan']['words']>0&&$stories->get($sid,$key,null,true)['plan']['text']!=='','the plan stays out of the story unless asked for');
    foreach(['stories'=>'meta','chapters'=>'body'] as $table=>$column){
        $raw=implode('',$vault->db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_COLUMN, array_search($column,array_column($vault->db->query("PRAGMA table_info($table)")->fetchAll(),'name'))));
        check(!str_contains($raw,'Farol')&&!str_contains($raw,'Marina'),"the $table are sealed in the database");
    }
    $rawStory=implode('',array_map('strval',$vault->db->query('SELECT notes||plan FROM stories')->fetchAll(PDO::FETCH_COLUMN)));
    check(!str_contains($rawStory,'SEGREDO')&&!str_contains($rawStory,'olhos'),'the bible and the plan are sealed in the database');
    check(Stories::cleanLine("a\n  b\tc",3,'x')==='a b','a line is one line, cut to its length');
    $stories->deleteChapter($sid,$two['id']);
    check(count($stories->chapters($sid,$key))===2,'a chapter is deleted');

    // ---------------------------------------------------------------------------------------------------------------
    // Writer settings and tool inputs
    $defaults=Writer::settings(null);
    check($defaults['provider']==='claude'&&$defaults['model']==='claude-opus-5-5'&&$defaults['effort']==='high','the writer starts as Claude Opus 5.5, effort high');
    check(Writer::settings($defaults,['provider'=>'openai'])['model']==='gpt-6.2-sol','switching to GPT picks a GPT model');
    rejects(fn()=>Writer::settings(null,['provider'=>'openai','model'=>'claude-opus-5-5']),'a model of the other provider is refused',422);
    rejects(fn()=>Writer::settings(null,['effort'=>'extreme']),'an unknown effort is refused',422);
    rejects(fn()=>Writer::settings(null,['chapter_words'=>50]),'a chapter length out of range is refused',422);
    check($defaults['illustrate']==='ask'&&Writer::settings(null,['illustrate'=>'text'])['illustrate']==='text'&&Writer::settings(['illustrate'=>true])['illustrate']==='each'&&Writer::settings(['illustrate'=>false])['illustrate']==='ask','illustrations: only when asked by default, text only or one per chapter; a saved yes or no still reads');
    rejects(fn()=>Writer::settings(null,['illustrate'=>'sometimes']),'an unknown way to illustrate is refused',422);
    $tools=Writer::tools();
    check(Writer::invalid(['chapter'=>2],$tools['read_chapter'][1])===null&&Writer::invalid(['chapter'=>'2'],$tools['read_chapter'][1])!==null&&Writer::invalid([],$tools['read_chapter'][1])!==null,'tool inputs are checked against their schema');
    check(Writer::invalid(['chapter'=>1,'edits'=>[['old_text'=>'a']]],$tools['edit_chapter'][1])==='input.edits[0].new_text is required','nested tool inputs are checked too');
    check(str_contains(json_encode(Writer::claudeTools()),'"properties":{}')&&!str_contains(json_encode(Writer::claudeTools()),'"properties":[]'),'tools without inputs declare an empty object');
    check(count(array_filter(Writer::claudeTools(),fn($t)=>$t['eager_input_streaming']===true))===count($tools),'every Claude tool streams its input');

    // ---------------------------------------------------------------------------------------------------------------
    // Claude errors
    $e=Claude::failure(402,json_encode(['type'=>'error','error'=>['type'=>'billing_error','message'=>'x']]));
    check($e->status===402&&$e->kind==='claude-credit','no credit at Anthropic is told apart');
    $e=Claude::failure(400,json_encode(['type'=>'error','error'=>['type'=>'invalid_request_error','message'=>'Your credit balance is too low to access the Anthropic API.']]));
    check($e->kind==='claude-credit','a credit balance too low is no credit');
    check(Claude::failure(529,json_encode(['type'=>'error','error'=>['type'=>'overloaded_error','message'=>'Overloaded']]))->kind==='retry','an overloaded API is tried again');
    $e=Claude::failure(400,json_encode(['type'=>'error','error'=>['type'=>'invalid_request_error','message'=>'messages.3.content.0: secret story text here']]));
    check(str_contains($e->getMessage(),'messages.3.content.0')&&!str_contains($e->getMessage(),'secret'),'a refused request says where, never what the conversation said');
    // A failure worth retrying is said on the page while the client waits, and what streamed before it is dropped there.
    $deltas=[];$tries=[];
    $client=new Claude('sk-ant-x',60,player($tries,[['status'=>529,'body'=>json_encode(['type'=>'error','error'=>['type'=>'overloaded_error','message'=>'Overloaded']])],
        claudeEvents([['type'=>'tool_use','id'=>'toolu_r','name'=>'write_chapter','input'=>['title'=>'T','text'=>'Um dois três quatro cinco.']]],'tool_use')]));
    $client->send(['model'=>'claude-opus-5-5','messages'=>[]],function(string $kind,string $chunk) use (&$deltas){ $deltas[]=[$kind,$chunk]; },fn()=>false);
    check($deltas[0]===['retry','A Anthropic está sobrecarregada agora. Tentando de novo em 2 s (2ª tentativa).']&&count($tries)===2,'a retry is announced with its reason and wait');
    $input=implode('',array_column(array_filter($deltas,fn($d)=>$d[0]==='input'),1));
    check(in_array(['tool','write_chapter'],$deltas,true)&&json_decode($input,true)['text']==='Um dois três quatro cinco.','the tool input streams to the page as it comes');
    $deltas=[];$tries=[];
    $gpt=new Gpt('sk-x',60,player($tries,[['status'=>503,'body'=>'{"error":{"message":"x","type":"server_error"}}'],
        [['response.output_item.added',['type'=>'response.output_item.added','output_index'=>0,'item'=>['type'=>'function_call','name'=>'write_chapter']]],
         ['response.function_call_arguments.delta',['type'=>'response.function_call_arguments.delta','output_index'=>0,'delta'=>'{"text":"a b c"}']],
         ['response.completed',['type'=>'response.completed','response'=>['status'=>'completed','model'=>'gpt-6.2-sol','usage'=>[]]]]]]));
    $gpt->send(['model'=>'gpt-6.2-sol','input'=>[]],function(string $kind,string $chunk) use (&$deltas){ $deltas[]=[$kind,$chunk]; },fn()=>false);
    check($deltas[0][0]==='retry'&&str_starts_with($deltas[0][1],'A OpenAI falhou (HTTP 503). Tentando de novo em 2 s')&&in_array(['input','{"text":"a b c"}'],$deltas,true),'GPT announces its retries and streams tool input too');

    // ---------------------------------------------------------------------------------------------------------------
    // A turn with Claude: the plan, a chapter, a reply
    rejects(fn()=>$writer->send($sid,'Olá',[],$key),'writing with Claude needs a Claude key',422);
    $vault->setClaudeKey('sk-ant-test-placeholder-key',$key);
    check($vault->claudeKey($key)==='sk-ant-test-placeholder-key'&&!str_contains((string)$vault->db->query('SELECT claude_key FROM users')->fetchColumn(),'sk-ant'),'the Claude key is kept sealed');
    $requests=[];
    $chapterText="A névoa cobria a ilha.\n\n— Quem está aí? — perguntou Marina.";
    $answers=[
        claudeEvents([
            ['type'=>'thinking','thinking'=>'Vou planejar a obra e escrever o capítulo.'],
            ['type'=>'tool_use','id'=>'toolu_1','name'=>'update_plan','input'=>['text'=>'PLANO: o faroleiro é o pai. Capítulo 3: revelação.']],
            ['type'=>'tool_use','id'=>'toolu_2','name'=>'write_chapter','input'=>['title'=>'A névoa','text'=>$chapterText,'summary'=>'Marina chega à ilha.']],
        ],'tool_use'),
        claudeEvents([['type'=>'text','text'=>'Escrevi o capítulo 3, “A névoa”.']],'end_turn',['input_tokens'=>50,'output_tokens'=>40,'cache_read_input_tokens'=>2000,'cache_creation_input_tokens'=>100]),
    ];
    $w=$writer->withTransports(player($requests,$answers),null);
    $turn=$w->send($sid,'Planeje e escreva o próximo capítulo.',[],$key);
    check($turn['status']==='queued','the message queues a turn');
    $done=$w->run($turn['id'],$key);
    check($done['status']==='completed'&&$done['steps']===2,'the turn runs the tools and ends with a reply');
    $chapters=$stories->chapters($sid,$key);
    check(count($chapters)===3&&$chapters[2]['title']==='A névoa'&&$chapters[2]['text']===$chapterText&&$chapters[2]['summary']==='Marina chega à ilha.'&&$chapters[2]['by']==='agent','the agent wrote a chapter with its summary');
    check(str_starts_with($stories->readDocument($sid,'plan',$key)['text'],'PLANO')&&$stories->readDocument($sid,'plan',$key)['by']==='agent','the agent wrote its plan');
    $first=$requests[0];
    check($first['url']===Claude::URL&&in_array('anthropic-version: 2026-10-21',$first['headers'],true)&&in_array('x-api-key: sk-ant-test-placeholder-key',$first['headers'],true)
        &&count(array_filter($first['headers'],fn($h)=>str_starts_with($h,'anthropic-beta: ')&&str_contains($h,'server-side-fallback-2026-07-01')&&str_contains($h,'thinking-display-updates-2026-08-18')))===1,'Claude gets the version, the betas and the key');
    $b=$first['body'];
    check($b['model']==='claude-opus-5-5'&&$b['stream']===true&&$b['max_tokens']===128000&&$b['thinking']===['type'=>'adaptive','display'=>'updates','block_binding'=>['prefix_mismatch_behavior'=>'drop_block']]
        &&$b['output_config']===['effort'=>'high']&&$b['fallbacks']==='default'&&$b['cache_control']===['type'=>'ephemeral','ttl'=>'1h']&&$b['system'][0]['cache_control']===['type'=>'ephemeral','ttl'=>'1h']&&!isset($b['temperature'])&&!isset($b['tool_choice']),'the request asks for adaptive thinking with progress notes, effort, fallbacks and caching');
    check($b['system'][0]['text']===Writer::SYSTEM&&$requests[1]['body']['system']===$b['system']&&$requests[1]['body']['tools']===$b['tools'],'the system prompt and the tools stay the same between requests');
    check(str_contains(Writer::SYSTEM,'## What each character knows')&&str_contains(Writer::SYSTEM,'knowledge ledger')&&str_contains(Writer::SYSTEM,'## Prose discipline')&&str_contains(Writer::SYSTEM,'Tics to watch')
        &&str_contains(Writer::SYSTEM,'## Revision')&&str_contains(Writer::SYSTEM,'call read_chapter only when it isn\'t in this conversation')&&str_contains(Writer::system(true),'note it per route in the ledger'),
        'the writer keeps to what each character knows, watches its tics and revises each chapter from the text it just wrote');
    $state=$b['messages'][0]['content'][0]['text'];
    check(count($b['messages'])===1&&str_starts_with($state,'<story_state>')&&str_contains($state,'SEGREDO')&&str_contains($state,'Marina: 30 anos')&&str_contains($state,'2. "O mapa"')===false
        &&$b['messages'][0]['content'][1]['text']==='Planeje e escreva o próximo capítulo.','the first message shows the agent the whole story, the bible and the plan, then the person\'s message');
    $second=$requests[1]['body']['messages'];
    check(json_encode(uncached($second[0]))===json_encode($b['messages'][0])&&$second[1]['role']==='assistant'&&$second[2]['role']==='user','the next request repeats the conversation unchanged and adds to it');
    // The cache: one breakpoint after the instructions and tools, one where the previous request ended (found there even
    // when an answer added more than the 20 blocks the end's breakpoint looks back), and the automatic one at the end.
    check(breakpoints($b)===['system.0','top']&&breakpoints($requests[1]['body'])===['system.0','messages.0.content.1','top']
        &&$second[0]['content'][1]['cache_control']===['type'=>'ephemeral','ttl'=>'1h'],'the cache is read from where the previous request ended');
    check($second[1]['content'][0]===['type'=>'thinking','thinking'=>'Vou planejar a obra e escrever o capítulo.','signature'=>'sig-0']&&$second[1]['content'][1]['input']===['text'=>'PLANO: o faroleiro é o pai. Capítulo 3: revelação.'],'thinking and tool calls go back exactly as they came');
    check(array_column($second[2]['content'],'tool_use_id')===['toolu_1','toolu_2']&&!isset($second[2]['content'][0]['is_error']),'each tool call gets its result');
    $chat=$w->chat($sid,$key);
    check(array_column($chat,'role')===['user','assistant','tools','assistant'],'the chat shows the message, the work and the reply, without the story state');
    check($chat[3]['text']==='Escrevi o capítulo 3, “A névoa”.'&&$chat[1]['updates']===['Vou planejar a obra e escrever o capítulo.'],'the reply and the progress notes are kept for the chat');
    $labels=array_column($chat[2]['results'],'label');
    check($labels[0]==='Atualizou o planejamento (oculto)'&&str_starts_with($labels[1],'Escreveu o capítulo 3 · “A névoa”')&&!str_contains(json_encode($chat),'faroleiro é o pai'),'what the agent did is said without spoiling the plan');
    $spent=$w->spent($sid,$key);
    check(abs($spent['claude']-Claude::cost('claude-opus-5-5',['input_tokens'=>1000,'output_tokens'=>200])-Claude::cost('claude-opus-5-5',['input_tokens'=>50,'output_tokens'=>40,'cache_read_input_tokens'=>2000,'cache_creation_input_tokens'=>100]))<1e-9,'what the turn cost is added up');
    $q=$vault->db->prepare('SELECT data FROM spend WHERE job_id=?');$q->execute([$turn['id']]);
    $paid=json_decode(Crypto::open((string)$q->fetchColumn(),$key,'spend:'.$turn['id']),true)['entries'];$q->closeCursor();
    check(count($paid)===2&&array_unique(array_column($paid,'kind'))===['writing']&&Vault::payer($paid[0]['model'])==='claude'&&abs(array_sum(array_column($paid,'cost'))-$spent['claude'])<1e-6,
        'each step of the Claude writer is kept with what the account paid, with its cost (the balance of Claude)');
    check(abs(Claude::cost('claude-opus-5-5',['input_tokens'=>1000000,'output_tokens'=>1000000,'cache_read_input_tokens'=>1000000,'cache_creation_input_tokens'=>1000000])-(4+20+0.2+5))<1e-9,'Claude prices: input, output, cache read and cache write');
    check(abs(Claude::cost('claude-opus-5-5',['input_tokens'=>0,'output_tokens'=>0,'cache_creation_input_tokens'=>1000000,'cache_creation'=>['ephemeral_5m_input_tokens'=>250000,'ephemeral_1h_input_tokens'=>750000]])-(0.25*5+0.75*8))<1e-9,'an hour in the cache costs twice the input price to write');
    $raw=implode('',$vault->db->query('SELECT content FROM story_messages')->fetchAll(PDO::FETCH_COLUMN));
    check(!str_contains($raw,'névoa')&&!str_contains($raw,'faroleiro'),'the conversation is sealed in the database');

    // ---------------------------------------------------------------------------------------------------------------
    // The next turn: what the person changed by hand, and the conversation only grows
    $stories->updateChapter($sid,$chapters[0]['id'],['text'=>'Texto reescrito pela pessoa.'],'person',$key);
    $stories->update($sid,['writer'=>Writer::settings($stories->get($sid,$key)['writer'],['illustrate'=>'text'])],$key);
    $stories->writeDocument($sid,'plan',['append'=>'Nota da pessoa: o pai deve morrer no fim.'],'person',$key);
    $requests=[];
    $answers=[
        claudeEvents([['type'=>'tool_use','id'=>'toolu_3','name'=>'edit_chapter','raw'=>'{"chapter": 3, "edits": [{"old_text": "névoa"']],'tool_use'),
        claudeEvents([['type'=>'tool_use','id'=>'toolu_4','name'=>'read_chapter','input'=>['chapter'=>9]],['type'=>'tool_use','id'=>'toolu_5','name'=>'append_to_chapter','input'=>['chapter'=>3,'text'=>'Mais um parágrafo.']]],'tool_use'),
        claudeEvents([['type'=>'tool_use','id'=>'toolu_6','name'=>'append_to_chapter','raw'=>'{"chapter": 3, "text": "Par\u00e1grafo inteiro que chegou.\n\nCortado no fi']],'max_tokens'),
        claudeEvents([['type'=>'text','text'=>'Pronto.']],'end_turn'),
    ];
    $w=$writer->withTransports(player($requests,$answers),null);
    $turn=$w->run($w->send($sid,'Continue.',[],$key)['id'],$key);
    $msgs=$requests[0]['body']['messages'];
    $update=$msgs[count($msgs)-1]['content'][count($msgs[count($msgs)-1]['content'])-2]['text'];
    check(str_starts_with($update,'<story_update>')&&str_contains($update,'Chapter 1 "Prólogo" was edited by the person')&&str_contains($update,'Nota da pessoa: o pai deve morrer no fim.')&&!str_contains($update,'Chapter 3'),'the next turn tells the agent what the person changed, not what it changed itself');
    check(str_contains($state,'; illustrations only when the person asks')&&str_contains($update,'text only for now: don\'t make, offer or suggest illustrations'),'the agent knows how to illustrate, and hears when the person asks for text only');
    check(json_encode(uncached(array_slice($msgs,0,3)),JSON_UNESCAPED_UNICODE)===json_encode(uncached($second),JSON_UNESCAPED_UNICODE)&&$msgs[3]['role']==='assistant'&&$msgs[3]['content'][0]['text']==='Escrevi o capítulo 3, “A névoa”.','earlier messages are sent again unchanged, the reply included');
    $r=$requests[1]['body']['messages'];$results=$r[count($r)-1]['content'];
    check($results[0]['tool_use_id']==='toolu_3'&&$results[0]['is_error']===true&&str_contains($results[0]['content'],'INVALID_JSON')&&str_contains($results[0]['content'],'old_text'),'a tool input that is not valid JSON is sent back as INVALID_JSON, and not run');
    $r=$requests[2]['body']['messages'];$results=$r[count($r)-1]['content'];
    check($results[0]['is_error']===true&&str_contains($results[0]['content'],'no chapter 9')&&!isset($results[1]['is_error']),'a tool error goes back to the agent, the other calls still run');
    $r=$requests[3]['body']['messages'];$results=$r[count($r)-1]['content'];
    check($results[0]['tool_use_id']==='toolu_6'&&!isset($results[0]['is_error'])&&str_contains($results[0]['content'],'output limit')&&str_contains($results[0]['content'],'«…Parágrafo inteiro que chegou.»')
        &&str_contains($results[0]['content'],'append_to_chapter'),'a chapter cut off by the output limit keeps what arrived, and the writer is told where it ends');
    $text=$stories->chapters($sid,$key)[2]['text'];
    check(str_ends_with($text,'Mais um parágrafo.'."\n\n".'Parágrafo inteiro que chegou.')&&!str_contains($text,'Cortado'),'up to its last whole paragraph');
    check($turn['status']==='completed'&&$turn['steps']===4,'the turn ends after the reply');

    // An answer whose thinking took the whole output limit: the writer goes on by itself, and the thinking that was cut
    // (no signature) is not sent back
    $cutThinking=[['message_start',['type'=>'message_start','message'=>['id'=>'msg_c','model'=>'claude-opus-5-5','role'=>'assistant','content'=>[],'usage'=>['input_tokens'=>10,'output_tokens'=>0]]]],
        ['content_block_start',['type'=>'content_block_start','index'=>0,'content_block'=>['type'=>'thinking','thinking'=>'','signature'=>'']]],
        ['content_block_delta',['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'thinking_delta','thinking'=>'Pensando no capítulo inteiro...']]],
        ['content_block_stop',['type'=>'content_block_stop','index'=>0]],
        ['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>'max_tokens','stop_details'=>null],'usage'=>['output_tokens'=>128000]]],['message_stop',['type'=>'message_stop']]];
    $requests=[];
    $w=$writer->withTransports(player($requests,[$cutThinking,claudeEvents([['type'=>'thinking','thinking'=>'Agora sim.'],['type'=>'text','text'=>'Pronto, sem pensar demais.']],'end_turn')]),null);
    $turn=$w->run($w->send($sid,'Escreva o próximo.',[],$key)['id'],$key);
    $again=$requests[1]['body']['messages'];$lastMsg=end($again);
    check($turn['status']==='completed'&&$turn['steps']===2&&$turn['note']===null&&end($lastMsg['content'])['text']===(new ReflectionClassConstant(Writer::class,'CUT'))->getValue()
        &&!str_contains(json_encode($again,JSON_UNESCAPED_UNICODE),'Pensando no capítulo inteiro'),'an answer cut off while thinking goes on by itself, without sending back the cut thinking');
    $chat=$w->chat($sid,$key);$notices=array_column(array_filter($chat,fn($m)=>$m['role']==='notice'),'text');
    check(in_array('A resposta do escritor chegou ao limite de tamanho, e ele seguiu sozinho de onde parou.',$notices,true)&&end($chat)['text']==='Pronto, sem pensar demais.','the chat says it went on by itself');
    // A reply cut off midway is kept and continued; after three cuts in a turn it stops and says so
    $requests=[];
    $w=$writer->withTransports(player($requests,array_fill(0,4,claudeEvents([['type'=>'thinking','thinking'=>'x'],['type'=>'text','text'=>'Texto cortado']],'max_tokens'))),null);
    $turn=$w->run($w->send($sid,'Mais.',[],$key)['id'],$key);
    check(count($requests)===4&&$turn['steps']===4&&str_contains((string)$turn['note'],'3 vezes')&&$requests[1]['body']['messages'][count($requests[1]['body']['messages'])-2]['content'][1]['text']==='Texto cortado','a cut reply stays in the conversation, and the writer goes on by itself up to three times');

    // ---------------------------------------------------------------------------------------------------------------
    // A refusal is discarded; a stopped turn leaves the conversation valid
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Parcial recusado']],'refusal')]),null);
    $turn=$w->run($w->send($sid,'Algo recusado.',[],$key)['id'],$key);
    check($turn['status']==='completed'&&$turn['note']==='O Claude recusou este pedido.','a refusal ends the turn with a note');
    $chat=$w->chat($sid,$key);$last=end($chat);
    check($last['role']==='assistant'&&!empty($last['refused'])&&$last['text']==='','the chat says it was refused, without the partial text');
    $requests=[];
    $stop=function(Closure $onEvent,Closure $tick){ foreach(claudeEvents([['type'=>'tool_use','id'=>'toolu_7','name'=>'get_outline','input'=>new stdClass()]],'tool_use') as [$e,$d])$onEvent($e,$d); return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null]; };
    $cancel=function(){ throw new Cancelled(); };
    $w=$writer->withTransports(player($requests,[$stop,$cancel]),null);
    $turn=$w->run($w->send($sid,'Mais uma.',[],$key)['id'],$key);
    check($turn['status']==='cancelled','a turn can be stopped');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $w->run($w->send($sid,'Seguimos.',[],$key)['id'],$key);
    $msgs=$requests[0]['body']['messages'];
    $roles=array_column($msgs,'role');$ok=true;for($i=1;$i<count($roles);$i++)if($roles[$i]===$roles[$i-1])$ok=false;
    check($ok&&!str_contains(json_encode($msgs),'Parcial recusado'),'messages alternate, and the refused text never goes back');
    $calls=0;$answered=0;foreach($msgs as $m)foreach($m['content'] as $c){ if(($c['type']??'')==='tool_use')$calls++; if(($c['type']??'')==='tool_result')$answered++; }
    check($calls===$answered&&$calls===7,'every tool call in the conversation has its result');

    // ---------------------------------------------------------------------------------------------------------------
    // Illustrations
    $stories->updateChapter($sid,$stories->chapters($sid,$key)[1]['id'],['text'=>"Parágrafo um.\n\nParágrafo dois com o barco.\n\nParágrafo três."],'person',$key);
    $requests=[];
    $answers=[
        claudeEvents([['type'=>'tool_use','id'=>'toolu_8','name'=>'illustrate','input'=>['prompt'=>'A small boat in fog, watercolor','caption'=>'O barco [na névoa]','chapter'=>2,'after_text'=>'com o barco','aspect'=>'16:9']],
            ['type'=>'tool_use','id'=>'toolu_9','name'=>'illustrate','input'=>['prompt'=>'x','caption'=>'y','chapter'=>2,'after_text'=>'não existe']],
            ['type'=>'tool_use','id'=>'toolu_10','name'=>'illustrate','input'=>['prompt'=>'Cover art','caption'=>'Capa','cover'=>true]]],'tool_use'),
        claudeEvents([['type'=>'text','text'=>'Pedi a ilustração.']],'end_turn'),
    ];
    $w=$writer->withTransports(player($requests,$answers),null);
    $turn=$w->run($w->send($sid,'Ilustre o barco.',[],$key)['id'],$key);
    check(count($turn['jobs'])===4,'the illustrations asked for are requests of the turn, the cover three of them (one in each style)');
    $text=$stories->chapters($sid,$key)[1]['text'];
    check(preg_match('/Parágrafo dois com o barco\.\n\n!\[O barco \(na névoa\)\]\(job:([a-f0-9]{32})\)\n\nParágrafo três\./',$text,$m)===1&&$m[1]===$turn['jobs'][0],'the illustration is placed after the paragraph it belongs to');
    $r=$requests[1]['body']['messages'];$results=$r[count($r)-1]['content'];
    check(!empty($results[1]['is_error'])&&str_contains($results[1]['content'],'after_text was not found')&&(int)$vault->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===4,'a wrong place is refused before any image is paid for');
    check(str_contains($results[2]['content'],'3 covers requested')&&str_contains($results[2]['content'],'The first one ready becomes the cover'),'the writer hears it asked for three covers to choose from');
    $job=array_values(array_filter($jobs->list($key,'active',[$turn['jobs'][0]]),fn($j)=>$j['id']===$turn['jobs'][0]))[0];
    check($job['options']['size']===OpenAI::SIZES['16:9']&&$job['options']['thinking']==='off'&&$job['label']==='O barco (na névoa)','the image request uses the writer\'s image settings and the caption');
    $im=imagecreatetruecolor(64,48);imagefill($im,0,0,imagecolorallocate($im,20,40,200));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
    $api=new OpenAI('test',60,fn($path,$body)=>['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]]);
    foreach($turn['jobs'] as $j)$jobs->run($j,$key,$api);
    $page=$stories->get($sid,$key,$jobs);
    check(preg_match('/!\[O barco \(na névoa\)\]\(img:([a-f0-9]{32})\)/',$page['chapters'][1]['text'],$m)===1&&$vault->image($m[1],$key)['label']==='O barco (na névoa)','a finished illustration becomes the image in the chapter');
    check($page['cover']!==null&&$page['cover_job']===null&&$page['images']===1,'a finished cover becomes the story cover');
    check(count($page['cover_choices'])===3&&$page['cover']===$page['cover_choices'][0]['image']&&count(array_filter(array_column($page['cover_choices'],'image')))===3
        &&count(array_unique(array_column($page['cover_choices'],'style')))===3&&$page['cover_choices'][0]['size']===[64,48],'the first one ready, and all three stay to choose from, each in another style');
    $imageId=$m[1];

    // view_image and search_story, straight
    $ctx=new Studio\ToolContext($sid,str_repeat('e',32),Writer::settings(null),$key);
    [$ok,$out]=$writer->runTool('view_image',['image_id'=>$imageId],$ctx);
    check($ok&&$out[1]['type']==='image'&&$out[1]['source']['media_type']==='image/jpeg'&&imagecreatefromstring(base64_decode($out[1]['source']['data']))!==false,'the agent can look at an illustration');
    [$ok,$out]=$writer->runTool('search_story',['query'=>'BARCO'],$ctx);
    check($ok&&str_contains($out,'[chapter 2]')&&str_contains($out,'Parágrafo dois com o barco'),'search_story finds a detail, ignoring case');
    [$ok,$out]=$writer->runTool('write_chapter',['title'=>'x'],$ctx);
    check(!$ok&&str_contains($out,'input.text is required'),'a tool input missing a field is refused before running');

    // The story's album: its illustrations go there, out of the library
    $title=$stories->get($sid,$key)['title'];
    $album=array_values(array_filter($vault->albums($key),fn($a)=>$a['name']===$title));
    check(count($album)===1&&$album[0]['count']===4&&$album[0]['kind']==='story'&&$album[0]['story']===$sid&&$album[0]['library']===false,'the story has an album with its title, holding its illustrations (and the covers to choose from)');
    $inAlbum=array_column($vault->library($key,['album'=>$album[0]['id']])['items'],0);$inLibrary=array_column($vault->library($key,[])['items'],0);
    check(count($inAlbum)===4&&in_array($imageId,$inAlbum,true)&&!array_intersect($inAlbum,$inLibrary)&&!array_intersect($inAlbum,array_column($vault->library($key,['group'=>true])['items'],0)),'they show only in the album, not in the library');
    check(!array_diff($inAlbum,array_column($vault->gallery($key,'',false,false,0,300)['items'],'id'))&&$stories->get($sid,$key)['albums']===['book'=>$album[0]['id'],'comic'=>null],'the reference picker still has them, and the story knows its album');
    $stories->update($sid,['title'=>'O Farol de Vidro'],$key);
    check(in_array('O Farol de Vidro',array_column($vault->albums($key),'name'),true),'the album follows the story\'s title');
    $stories->update($sid,['title'=>$title],$key);

    // A new version of an illustration: it takes the old one's place when ready, with the app's ready-made instructions
    [$ok,$out]=$writer->runTool('edit_illustration',['image_id'=>str_repeat('a',32),'instruction'=>'x'],$ctx);
    check(!$ok&&str_contains($out,'not an illustration, the cover'),'only a picture of the story can be edited');
    [$ok,$out]=$writer->runTool('edit_illustration',['image_id'=>$imageId],$ctx);
    check(!$ok&&str_contains($out,'Say what to change'),'an edit needs an instruction');
    [$ok,$out,$label]=$writer->runTool('edit_illustration',['image_id'=>$imageId,'kind'=>'photoreal','instruction'=>'golden hour light','caption'=>'O barco, em foto'],$ctx);
    $editJob=end($ctx->jobs);
    $job=array_values(array_filter($jobs->list($key,'active',[$editJob]),fn($j)=>$j['id']===$editJob))[0];
    check($ok&&str_contains($out,'chapter 2')&&$label==='Pediu a versão fotorrealista da ilustração · “O barco, em foto” (capítulo 2)','the writer asks for a photorealistic version of an illustration');
    check($job['prompt']===Presets::withNotes(Presets::PHOTOREAL,'golden hour light')&&$job['operation']==='edit'&&$job['parent_id']===$imageId&&$job['reference_count']===1
        &&$job['options']['thinking']==='off'&&$job['label']==='Fotorrealista: golden hour light','with the Fotorrealista instruction of the edit panel, its notes first, as a version of the illustration');
    check(str_contains($stories->chapters($sid,$key)[1]['text'],'(img:'.$imageId.')')&&$stories->get($sid,$key)['editing'][$editJob]===['image'=>$imageId,'failed'=>false],'the old picture stays while the new one is made');
    [$ok,$out]=$writer->runTool('edit_illustration',['image_id'=>$imageId,'instruction'=>'make the boat red'],$ctx);
    check(!$ok&&str_contains($out,'already being made'),'one new version of an illustration at a time');
    $jobs->run($editJob,$key,$api);
    $page=$stories->get($sid,$key,$jobs);
    check(preg_match('/!\[O barco, em foto\]\(img:([a-f0-9]{32})\)/',$page['chapters'][1]['text'],$m)===1&&$m[1]!==$imageId&&$page['editing']===[],'when it is ready, the new version takes the old one\'s place, with the new caption');
    check(in_array($m[1],array_column($vault->library($key,['album'=>$album[0]['id']])['items'],0),true)&&!in_array($m[1],array_column($vault->library($key,[])['items'],0),true),'the new version goes into the story\'s album, out of the library');
    $imageId=$m[1];
    [$ok,$out]=$writer->runTool('edit_illustration',['image_id'=>$imageId,'kind'=>'upscale'],$ctx);
    $editJob=end($ctx->jobs);
    $job=array_values(array_filter($jobs->list($key,'active',[$editJob]),fn($j)=>$j['id']===$editJob))[0];
    $sizes=Studio\Mcp::upscaleSizes(64,48);[$w2,$h2]=$sizes['2']??$sizes['max'];
    check($ok&&$job['operation']==='upscale'&&$job['options']['size']==="{$w2}x{$h2}"&&$job['prompt']===Presets::UPSCALE&&$job['label']==="Ampliada para $w2 × $h2",'Ampliar doubles it (or makes it as large as the API allows) with the edit panel\'s instruction');
    $jobs->run($editJob,$key,new OpenAI('test',60,function(){ throw new HttpError(400,'Recusado.'); }));
    $page=$stories->get($sid,$key,$jobs);
    check(str_contains($page['chapters'][1]['text'],'(img:'.$imageId.')')&&$page['editing'][$editJob]['failed']===true,'a new version that fails leaves the illustration as it was');

    // The OpenAI credit: the writer knows it, and an illustration it can't pay for is not asked for
    $requests=[];
    $answers=[
        claudeEvents([['type'=>'tool_use','id'=>'toolu_11','name'=>'illustrate','input'=>['prompt'=>'The lighthouse at dusk','caption'=>'O farol','chapter'=>2]],
            ['type'=>'tool_use','id'=>'toolu_12','name'=>'illustrate','input'=>['prompt'=>'The keeper\'s house','caption'=>'A casa','chapter'=>2]]],'tool_use'),
        claudeEvents([['type'=>'text','text'=>'Só deu para uma ilustração: o saldo da OpenAI acabou.']],'end_turn'),
    ];
    $w=$writer->withTransports(player($requests,$answers),null);
    $turn=$w->run($w->send($sid,'Ilustre o farol e a casa.',[],$key,['balance'=>0.30,'image'=>0.19])['id'],$key);
    $m=$requests[0]['body']['messages'];$update=json_encode(end($m),JSON_UNESCAPED_UNICODE);
    check(str_contains($update,'OpenAI credit for illustrations (the app\'s estimate): about US$ 0.30; one illustration with the current settings costs about US$ 0.19, so about 1 more.'),'the writer is told the OpenAI credit with each message');
    $r=$requests[1]['body']['messages'];$results=end($r)['content'];
    check(!isset($results[0]['is_error'])&&str_contains($results[0]['content'],'About US$ 0.11 of credit for pictures left after it')
        &&$results[1]['is_error']===true&&str_contains($results[1]['content'],'Not enough OpenAI credit for an illustration')&&count($turn['jobs'])===1,'an illustration the credit can\'t pay for is refused before it costs anything');
    $chat=$w->chat($sid,$key);$tools=array_values(array_filter($chat,fn($x)=>$x['role']==='tools'));
    check(end($tools)['results'][1]['label']==='Sem saldo na OpenAI para ilustrar','the chat says why');
    $failing=new OpenAI('test',60,function(){ throw new HttpError(402,OpenAI::noCreditMessage(),'credit'); });
    $jobs->run($turn['jobs'][0],$key,$failing);
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Entendido.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $w->run($w->send($sid,'E agora?',[],$key)['id'],$key);
    $m=$requests[0]['body']['messages'];$update=json_encode(end($m),JSON_UNESCAPED_UNICODE);
    check(str_contains($update,'The illustration \"O farol\" failed: OpenAI has no credit left.')&&!str_contains($update,'OpenAI credit for illustrations'),'an illustration that failed for lack of credit is reported at the next message');
    $w->run($w->send($sid,'Seguimos.',[],$key)['id'],$key);
    $m=$requests[1]['body']['messages'];
    check(!str_contains(json_encode(end($m),JSON_UNESCAPED_UNICODE),'failed'),'only once');

    // ---------------------------------------------------------------------------------------------------------------
    // GPT as the writer, in the same conversation
    $illustrated=end($requests)['body']['messages'];
    $requests=[];
    $gptAnswer=function(array $items,array $usage,string $status='completed'){
        return function(Closure $onEvent) use ($items,$usage,$status){
            foreach($items as $i=>$item){
                if($item['type']==='function_call')$onEvent('response.output_item.added',['type'=>'response.output_item.added','output_index'=>$i,'item'=>['type'=>'function_call','name'=>$item['name']]]);
                if($item['type']==='message')$onEvent('response.output_text.delta',['type'=>'response.output_text.delta','delta'=>$item['content'][0]['text']]);
                $onEvent('response.output_item.done',['type'=>'response.output_item.done','output_index'=>$i,'item'=>$item]);
            }
            $onEvent('response.completed',['type'=>'response.completed','response'=>['status'=>$status,'model'=>'gpt-6.2-sol','usage'=>$usage,'output'=>$items]]);
            return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
        };
    };
    $answers=[
        $gptAnswer([['type'=>'reasoning','id'=>'rs_1','summary'=>[],'encrypted_content'=>'ENC1'],
            ['type'=>'function_call','id'=>'fc_1','call_id'=>'call_1','name'=>'update_notes','arguments'=>json_encode(['append'=>'Barco: azul.'])]],['input_tokens'=>3000,'input_tokens_details'=>['cached_tokens'=>1000],'output_tokens'=>100]),
        $gptAnswer([['type'=>'message','id'=>'msg_1','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Anotei na bíblia.','annotations'=>[]]]]],['input_tokens'=>3200,'input_tokens_details'=>['cached_tokens'=>3000],'output_tokens'=>20]),
    ];
    $w=$writer->withTransports(null,player($requests,$answers));
    $turn=$w->run($w->send($sid,'Anote a cor do barco.',['provider'=>'openai'],$key)['id'],$key);
    check($turn['status']==='completed'&&str_ends_with($stories->readDocument($sid,'notes',$key)['text'],'Barco: azul.'),'GPT writes with the same tools');
    $g=$requests[0]['body'];
    check($requests[0]['url']===Gpt::URL&&$g['model']==='gpt-6.2-sol'&&$g['store']===false&&$g['include']===['reasoning.encrypted_content']&&$g['reasoning']===['effort'=>'high']
        &&$g['input'][0]['role']==='developer'&&$g['input'][0]['content']===Writer::SYSTEM&&$g['tools'][0]['type']==='function','GPT gets the same instructions and tools, nothing stored at OpenAI');
    check($g['prompt_cache_retention']==='24h'&&str_starts_with($g['prompt_cache_key'],'story-'),'GPT keeps the conversation in its cache for 24 hours');
    $in=$g['input'];$types=array_column($in,'type');
    $calls=array_values(array_filter($in,fn($i)=>$i['type']==='function_call'));$outputs=array_column(array_filter($in,fn($i)=>$i['type']==='function_call_output'),'call_id');
    check(!in_array('reasoning',$types,true)&&!str_contains(json_encode($in),'thinking')&&count($calls)===12&&array_column($calls,'call_id')===$outputs
        &&json_decode($calls[0]['arguments'],true)!==null&&str_contains($in[1]['content'][0]['text'],'<story_state>'),'switching to GPT keeps the conversation: Claude\'s messages, calls and results translated, its thinking left out');
    $said=array_map(fn($i)=>$i['content'][0]['text']??'',array_filter($in,fn($i)=>$i['type']==='message'));
    check(in_array('Seguimos.',$said,true)&&in_array('Pedi a ilustração.',$said,true)&&in_array('Anote a cor do barco.',$said,true),'what the person and Claude said goes along');
    $handover=array_values(array_filter($said,fn($t)=>str_contains($t,'The writer changed')));
    check(count($handover)===1&&str_contains($handover[0],'you are now GPT-6.2 Sol, taking over this conversation from Claude Opus 5.5'),'the new writer is told it takes over the conversation');
    $in=$requests[1]['body']['input'];
    check(json_encode(array_slice($in,0,count($g['input'])))===json_encode($g['input'])&&array_column(array_slice($in,count($g['input'])),'type')===['reasoning','function_call','function_call_output']
        &&$in[count($g['input'])]['encrypted_content']==='ENC1','within the turn the input only grows (the cache keeps working); the reasoning and the call go back unchanged');
    $chat=$w->chat($sid,$key);$notice=array_values(array_filter($chat,fn($m)=>$m['role']==='notice'&&str_starts_with($m['text'],'Agora quem escreve')));
    check(count($notice)===1&&$notice[0]['text']==='Agora quem escreve é o GPT-6.2 Sol. A conversa continua.'&&count(array_unique(array_column($chat,'conversation')))===1,'the chat says who writes now, in the same conversation');
    $balance=$vault->balance($key);
    check($balance['planning']===[],'what GPT spent writing does not change the planning estimates');
    $vault->setBilling(['balance'=>10,'topup'=>0,'day'=>1],$key);
    $vault->db->exec('UPDATE spend SET created=created+5');
    $q=$vault->db->prepare('SELECT data FROM spend WHERE job_id=?');$q->execute([$turn['id']]);
    $entries=json_decode(Crypto::open((string)$q->fetchColumn(),$key,'spend:'.$turn['id']),true)['entries'];$q->closeCursor();
    check(count($entries)===2&&$entries[0]['kind']==='writing'&&$entries[0]['model']==='gpt-6.2-sol','what GPT spent writing is counted in the OpenAI balance');
    check(abs($w->spent($sid,$key)['openai']-(2000*2+1000*0.1+100*10+200*2+3000*0.1+20*10)/1e6)<1e-9,'GPT prices count the cached input');

    // Back to Claude: its own messages go back exactly as they were (thinking and cache intact), GPT's translated
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Voltei.']],'end_turn')]),null);
    $back=$w->run($w->send($sid,'Volte, Claude.',['provider'=>'claude','model'=>'claude-opus-5-5'],$key)['id'],$key);
    $msgs=$requests[0]['body']['messages'];
    check(json_encode(uncached(array_slice($msgs,0,count($illustrated))),JSON_UNESCAPED_UNICODE)===json_encode(uncached($illustrated),JSON_UNESCAPED_UNICODE),'back on Claude, everything Claude saw before is sent unchanged');
    $tail=array_slice($msgs,count($illustrated));
    check(array_column($tail,'role')===['assistant','user','assistant','user','assistant','user']&&$tail[2]['content'][0]['type']==='tool_use'&&$tail[2]['content'][0]['id']==='call_1'
        &&$tail[2]['content'][0]['input']===['append'=>'Barco: azul.']&&$tail[3]['content'][0]['tool_use_id']==='call_1'&&$tail[4]['content'][0]['text']==='Anotei na bíblia.'
        &&!str_contains(json_encode($tail),'ENC1'),'GPT\'s turn reaches Claude as its calls, results and reply, without its reasoning');
    check(str_contains(json_encode($tail[5],JSON_UNESCAPED_UNICODE),'taking over this conversation from GPT-6.2 Sol')&&$back['status']==='completed','Claude is told it takes over again');

    // Keeping Claude's cache warm while the story is open
    $due=$w->warmIn($sid,$key);
    check($due!==null&&$due>=52*60-5&&$due<=52*60,'a keep-alive is due a little before the cache\'s hour runs out');
    $requests=[];
    $usage=['input_tokens'=>12,'cache_read_input_tokens'=>90000,'cache_creation_input_tokens'=>300,'cache_creation'=>['ephemeral_5m_input_tokens'=>0,'ephemeral_1h_input_tokens'=>300],'output_tokens'=>0];
    $w=$writer->withTransports(player($requests,[['status'=>200,'body'=>json_encode(['content'=>[],'stop_reason'=>'max_tokens','usage'=>$usage])]]),null);
    check($w->warm($sid,$key)['warmed']===false&&$requests===[],'nothing is sent before it is due');
    $age=function(int $seconds) use ($vault,$key,$back){
        $q=$vault->db->prepare('SELECT data FROM story_turns WHERE id=?');$q->execute([$back['id']]);
        $d=json_decode(Crypto::open((string)$q->fetchColumn(),$key,'story-turn:'.$back['id']),true);
        $d['sent_at']-=$seconds;$d['last_real']-=$seconds;
        $vault->db->prepare('UPDATE story_turns SET data=? WHERE id=?')->execute([Crypto::seal(json_encode($d),$key,'story-turn:'.$back['id']),$back['id']]);
    };
    $age(52*60);
    $spentBefore=$w->spent($sid,$key)['claude'];
    $warm=$w->warm($sid,$key);$b=$requests[0]['body'];
    check($warm['warmed']===true&&$b['max_tokens']===0&&!isset($b['stream'])&&!isset($b['cache_control']),'the keep-alive is the same request with max_tokens 0 and no stream');
    $kinds=[];foreach($vault->db->query('SELECT job_id, data FROM spend')->fetchAll() as $row) foreach(json_decode(Crypto::open((string)$row['data'],$key,'spend:'.$row['job_id']),true)['entries'] as $e) $kinds[]=[$e['kind'],$e['cost']??null];
    check(in_array(['warm',$warm['cost']],$kinds,true),'what keeping the conversation ready cost is counted in Claude\'s balance too');
    $last=end($b['messages']);$prev=$b['messages'][count($b['messages'])-2];
    check($last['role']==='user'&&str_contains($last['content'][0]['text'],'No reply needed')&&$prev['role']==='assistant'&&$prev['content'][0]['text']==='Voltei.'
        &&json_encode(uncached(array_slice($b['messages'],0,count($msgs))))===json_encode(uncached($msgs)),'it sends the conversation as it was, and a note after the writer\'s last reply');
    $end=count($msgs)-1;
    check(breakpoints($b)===['system.0','messages.'.$end.'.content.'.(count($msgs[$end]['content'])-1),'messages.'.count($msgs).'.content.0'],
        'it reads the cache where the last real request ended, which restarts that entry\'s hour, and keeps the writer\'s last reply in it, not the note');
    check(abs($w->spent($sid,$key)['claude']-$spentBefore-Claude::cost('claude-opus-5-5',$usage))<1e-4&&$warm['warm_in']>=52*60-5,'what it cost is counted, and the next one is due an hour later');
    // While the person uses the app, for most of a day after the writer's last request: a miss costs some 40 keep-alives
    $since=function(int $sent,int $real) use ($vault,$key,$back){
        $q=$vault->db->prepare('SELECT data FROM story_turns WHERE id=?');$q->execute([$back['id']]);
        $d=json_decode(Crypto::open((string)$q->fetchColumn(),$key,'story-turn:'.$back['id']),true);
        $d['sent_at']=time()-$sent;$d['last_real']=time()-$real;
        $vault->db->prepare('UPDATE story_turns SET data=? WHERE id=?')->execute([Crypto::seal(json_encode($d),$key,'story-turn:'.$back['id']),$back['id']]);
    };
    $since(52*60,7*3600);
    check($w->warmIn($sid,$key)===0,'seven hours after the last message the cache is still kept');
    $since(52*60,9*3600);
    check($w->warmIn($sid,$key)===null,'nine hours after, it is let go');
    $since(52*60,52*60);
    $age(3*3600);
    check($w->warmIn($sid,$key)===null,'an expired cache is not warmed');

    // A new reasoning level goes in as a message of the app, after the writer's last reply: the top of the request stays
    // as it was, so the conversation is still read from the cache
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Pensei mais.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'De novo.']],'end_turn'),
        claudeEvents([['type'=>'text','text'=>'Mais leve.']],'end_turn')]),null);
    $w->run($w->send($sid,'Pense bastante nesta.',['effort'=>'max'],$key)['id'],$key);
    $b=$requests[0]['body'];$m=$b['messages'];$at=count($msgs)+1;
    check($b['output_config']===['effort'=>'high']&&$m[$at-1]['content'][0]['text']==='Voltei.'&&$m[$at]===['role'=>'system','content'=>[],'output_config'=>['effort'=>'max']]
        &&$m[$at+1]['role']==='user'&&str_contains(json_encode($m[$at+1],JSON_UNESCAPED_UNICODE),'Pense bastante nesta.')
        &&json_encode(uncached(array_slice($m,0,$at)))===json_encode(uncached(array_merge($msgs,[$m[$at-1]])))&&breakpoints($b)===['system.0','messages.'.$end.'.content.'.(count($msgs[$end]['content'])-1),'top'],
        'a new reasoning level is a message after the last reply, and the top of the request keeps the old one');
    check(in_array('anthropic-beta: '.Claude::BETAS,$requests[0]['headers'],true)&&str_contains(Claude::BETAS,'mid-conversation-output-config-2026-07-01'),'with the beta that lets a message set the reasoning level');
    $w->run($w->send($sid,'Continue assim.',[],$key)['id'],$key);
    $m2=$requests[1]['body']['messages'];
    check($requests[1]['body']['output_config']===['effort'=>'high']&&count(array_filter($m2,fn($x)=>$x['role']==='system'))===1,'the next message at the same level adds nothing');
    $w->run($w->send($sid,'Agora mais leve.',['effort'=>'high'],$key)['id'],$key);
    $m3=$requests[2]['body']['messages'];$systems=array_values(array_filter($m3,fn($x)=>$x['role']==='system'));
    check($requests[2]['body']['output_config']===['effort'=>'high']&&count($systems)===2&&$systems[1]['output_config']===['effort'=>'high']
        &&json_encode(uncached(array_slice($m3,0,count($m2))))===json_encode(uncached($m2)),'going back is another message, and nothing earlier changes');
    check(Writer::claudeToGpt((object)['role'=>'system','content'=>[],'output_config'=>(object)['effort'=>'max']])===[],'GPT doesn\'t get the reasoning level messages');
    // A model that doesn't take the level in a message (a fallback model): once more with it at the top
    $tries=[];
    $client=new Claude('sk-ant-x',60,player($tries,[['status'=>400,'body'=>json_encode(['type'=>'error','error'=>['type'=>'invalid_request_error','message'=>'output_config.effort requires a model that supports per-turn effort; this model does not']])],
        claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]));
    $client->send(['model'=>'claude-opus-5-5','output_config'=>['effort'=>'high'],'messages'=>[(object)['role'=>'user','content'=>'a'],(object)['role'=>'assistant','content'=>'b'],
        (object)['role'=>'system','content'=>[],'output_config'=>(object)['effort'=>'max']],(object)['role'=>'user','content'=>'c']]],fn()=>null,fn()=>false);
    check(count($tries)===2&&$tries[1]['body']['output_config']===['effort'=>'max']&&array_column($tries[1]['body']['messages'],'role')===['user','assistant','user'],'a refused level message is sent again as the level at the top');
    $tries=[];
    $client=new Claude('sk-ant-x',60,player($tries,[['status'=>400,'body'=>json_encode(['type'=>'error','error'=>['type'=>'invalid_request_error','message'=>'messages.3: something else']])]]));
    rejects(fn()=>$client->send(['model'=>'claude-opus-5-5','messages'=>[(object)['role'=>'user','content'=>'a']]],fn()=>null,fn()=>false),'other refusals are not sent again');
    check(count($tries)===1,'only once');

    // A model switch within GPT leaves out reasoning the new model can't read
    $requests=[];
    $w=$writer->withTransports(null,player($requests,[['status'=>400,'body'=>'{"error":{"message":"Unsupported parameter: prompt_cache_retention","type":"invalid_request_error","param":"prompt_cache_retention"}}'],
        $gptAnswer([['type'=>'message','id'=>'msg_2','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Ok','annotations'=>[]]]]],['input_tokens'=>10,'output_tokens'=>1])]));
    $astra=$w->run($w->send($sid,'Oi.',['provider'=>'openai','model'=>'gpt-6.2-astra'],$key)['id'],$key);
    check($astra['status']==='completed'&&count($requests)===2&&!isset($requests[1]['body']['prompt_cache_retention'])&&$requests[1]['body']['input']===$requests[0]['body']['input'],'a model without the 24-hour cache gets the same request with the default one');
    check(!in_array('reasoning',array_column($requests[1]['body']['input'],'type'),true)&&in_array('function_call',array_column($requests[1]['body']['input'],'type'),true)
        &&!in_array('system',array_column($requests[1]['body']['input'],'role'),true),'reasoning of another GPT model is left out, the rest goes back (not Claude\'s reasoning levels)');
    check($w->warmIn($sid,$key)===null,'there is no Claude cache to keep when GPT writes');

    // A new conversation by hand: the next message shows the whole story again
    $w->newConversation($sid,$key);
    $requests=[];
    $w=$writer->withTransports(null,player($requests,[$gptAnswer([['type'=>'message','id'=>'msg_3','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Oi','annotations'=>[]]]]],['input_tokens'=>10,'output_tokens'=>1])]));
    $w->run($w->send($sid,'Recomeçando.',[],$key)['id'],$key);
    $in=$requests[0]['body']['input'];
    check(count($in)===3&&str_contains($in[1]['content'][0]['text'],'<story_state>')&&str_contains($in[1]['content'][0]['text'],'Person: Oi.'),'a new conversation starts from the story, with the last messages');

    // Gallery images with a message: the writer sees each one with its id, in the order sent, on GPT and on Claude
    rejects(fn()=>$w->send($sid,'Olha.',[],$key,null,array_map(fn($i)=>str_repeat(dechex($i),32),range(1,7))),'at most 6 images with a message',422);
    rejects(fn()=>$w->send($sid,'Olha.',[],$key,null,['../x']),'an attached image must be an image id',422);
    // GPT cut off by its output limit: a chapter keeps what arrived, a reply goes on by itself
    $requests=[];
    $w=$writer->withTransports(null,player($requests,[
        $gptAnswer([['type'=>'function_call','id'=>'fc_c','call_id'=>'call_c','name'=>'write_chapter','arguments'=>'{"title":"Cortado","text":"Começo inteiro.\n\nMeio cor','status'=>'incomplete']],['input_tokens'=>10,'output_tokens'=>64000],'incomplete'),
        $gptAnswer([['type'=>'message','id'=>'msg_c1','role'=>'assistant','status'=>'incomplete','content'=>[['type'=>'output_text','text'=>'Resposta cort','annotations'=>[]]]]],['input_tokens'=>10,'output_tokens'=>64000],'incomplete'),
        $gptAnswer([['type'=>'message','id'=>'msg_c2','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Continuei.','annotations'=>[]]]]],['input_tokens'=>10,'output_tokens'=>5])]));
    $cutSid=$stories->create('Cortes',$key)['id'];
    $turn=$w->run($w->send($cutSid,'Escreva.',['provider'=>'openai'],$key)['id'],$key);
    $saved=array_values(array_filter($stories->chapters($cutSid,$key),fn($c)=>$c['title']==='Cortado'));
    $in=$requests[1]['body']['input'];$out=null;foreach($in as $item)if(($item['type']??'')==='function_call_output'&&$item['call_id']==='call_c')$out=$item['output'];
    check($saved&&$saved[0]['text']==='Começo inteiro.'&&str_contains((string)$out,'«…Começo inteiro.»'),'GPT: a chapter cut off by the output limit keeps what arrived');
    $last=end($requests[2]['body']['input']);
    check($turn['status']==='completed'&&$turn['steps']===3&&str_contains(json_encode($last),'output limit'),'GPT: a reply cut off goes on by itself');
    $stories->delete($cutSid);
    $requests=[];
    $w=$writer->withTransports(null,player($requests,[$gptAnswer([['type'=>'message','id'=>'msg_4','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Vi a imagem.','annotations'=>[]]]]],['input_tokens'=>10,'output_tokens'=>1])]));
    $w->run($w->send($sid,'Esta é a Marina.',[],$key,null,[$imageId])['id'],$key);
    $in=$requests[0]['body']['input'];$parts=end($in)['content'];
    check(array_column($parts,'type')===['input_text','input_text','input_text','input_image']&&$parts[0]['text']==='Esta é a Marina.'&&str_starts_with($parts[2]['text'],'Image '.$imageId)
        &&str_starts_with($parts[3]['image_url'],'data:image/jpeg;base64,'),'GPT sees the attached image after the message, with its id');
    $chat=$w->chat($sid,$key);$mine=array_values(array_filter($chat,fn($m)=>$m['role']==='user'));
    check(end($mine)['images']===[$imageId]&&end($mine)['text']==='Esta é a Marina.','the chat shows the attached image with the message');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Certo.']],'end_turn')]),null);
    $w->run($w->send($sid,'E agora o Claude.',['provider'=>'claude','model'=>'claude-opus-5-5'],$key)['id'],$key);
    $withImage=array_values(array_filter($requests[0]['body']['messages'],fn($m)=>in_array('image',array_column($m['content'],'type'),true)));
    // The conversation's size, told before each message, joins the person's message into one for Claude.
    $sizeFirst=count($withImage)===1&&str_starts_with($withImage[0]['content'][0]['text'],'<story_update>')&&str_contains($withImage[0]['content'][0]['text'],'Conversation size:');
    if($sizeFirst)$withImage[0]['content']=array_slice($withImage[0]['content'],1);
    check($sizeFirst&&array_column($withImage[0]['content'],'type')===['text','text','text','image']&&$withImage[0]['content'][0]['text']==='Esta é a Marina.','back on Claude, the attached image goes in the same place');
    $back=Writer::claudeToGpt(json_decode(json_encode($withImage[0])));
    check(count($back)===1&&array_column($back[0]->content,'type')===['input_text','input_text','input_text','input_image'],'and translates back to GPT the same way');

    // ---------------------------------------------------------------------------------------------------------------
    // Messages sent while the writer works: they wait, go in at its next step, and are answered in the same turn
    $ok200=['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
    $requests=[];$queued=null;$late=null;
    $answers=[
        function(Closure $onEvent,Closure $tick) use ($writer,$sid,$key,&$queued,$imageId,$ok200): array {
            $queued=$writer->send($sid,'Ah, e a Marina tem medo do mar.',[],$key,null,[$imageId]);
            foreach(claudeEvents([['type'=>'tool_use','id'=>'toolu_in1','name'=>'get_outline','input'=>new stdClass()]],'tool_use') as [$e,$d])$onEvent($e,$d);
            return $ok200;
        },
        function(Closure $onEvent,Closure $tick) use ($writer,$sid,$key,&$late,$ok200): array {
            // This one comes while the reply streams: the turn goes on to answer it.
            $late=$writer->send($sid,'Mais uma coisa: chove.',[],$key);
            foreach(claudeEvents([['type'=>'text','text'=>'Anotado o medo do mar.']],'end_turn') as [$e,$d])$onEvent($e,$d);
            return $ok200;
        },
        claudeEvents([['type'=>'text','text'=>'E a chuva também.']],'end_turn'),
    ];
    $w=$writer->withTransports(player($requests,$answers),null);
    $turn=$w->send($sid,'Escreva a próxima cena.',[],$key);
    $done=$w->run($turn['id'],$key);
    check(($queued['waiting']??false)===true&&$queued['id']===$turn['id']&&($late['waiting']??false)===true,'a message sent while the writer works waits for it, without a new turn');
    check($done['status']==='completed'&&$done['steps']===3&&count($requests)===3,'the writer reads it at its next step and answers it in the same turn');
    $msgs=$requests[1]['body']['messages'];$lastUser=end($msgs);
    $texts=array_values(array_filter($lastUser['content'],fn($c)=>($c['type']??'')==='text'));
    check($lastUser['role']==='user'&&$lastUser['content'][0]['type']==='tool_result'&&str_contains($texts[0]['text'],'while you were working')&&str_contains($texts[0]['text'],'medo do mar')
        &&in_array('image',array_column($lastUser['content'],'type'),true),'it goes in after the tool results, marked as written during the work, with its image');
    $msgs=$requests[2]['body']['messages'];
    check(end($msgs)['role']==='user'&&str_contains(json_encode(end($msgs)['content'],JSON_UNESCAPED_UNICODE),'chove')&&$msgs[count($msgs)-2]['role']==='assistant','one sent during the reply comes after the reply, and the writer answers again');
    $roles=array_column($msgs,'role');$alternate=true;for($i=1;$i<count($roles);$i++)if($roles[$i]===$roles[$i-1])$alternate=false;
    check($alternate&&json_encode(uncached(array_slice($msgs,0,count($requests[1]['body']['messages']))),JSON_UNESCAPED_UNICODE)===json_encode(uncached($requests[1]['body']['messages']),JSON_UNESCAPED_UNICODE),'the conversation still alternates and only grows');
    $view=$w->view($sid,$key);$during=array_values(array_filter($view['messages'],fn($m)=>!empty($m['during'])));
    check(count($during)===2&&$during[0]['text']==='Ah, e a Marina tem medo do mar.'&&$during[0]['images']===[$imageId]&&$view['inbox']===[]&&$view['turn']['id']===$turn['id'],'the chat shows both as sent during the work, and nothing is left waiting');

    // An answer with more blocks than the end's breakpoint looks back over (a progress note before each of 14 calls):
    // the next request still reads the cache from where the previous one ended
    $requests=[];$many=[];
    for($i=0;$i<14;$i++){$many[]=['type'=>'thinking','thinking'=>"Leio o capítulo $i."];$many[]=['type'=>'tool_use','id'=>"toolu_m$i",'name'=>'read_chapter','input'=>['chapter'=>1]];}
    $w=$writer->withTransports(player($requests,[claudeEvents($many,'tool_use',['input_tokens'=>10,'output_tokens'=>900,'cache_read_input_tokens'=>50000,'cache_creation_input_tokens'=>3000,
        'cache_creation'=>['ephemeral_5m_input_tokens'=>0,'ephemeral_1h_input_tokens'=>3000],'output_tokens_details'=>['thinking_tokens'=>700]]),
        claudeEvents([['type'=>'text','text'=>'Li tudo.']],'end_turn',['input_tokens'=>10,'output_tokens'=>20,'cache_read_input_tokens'=>53000,'cache_creation_input_tokens'=>4000,
        'cache_creation'=>['ephemeral_5m_input_tokens'=>0,'ephemeral_1h_input_tokens'=>4000],'output_tokens_details'=>['thinking_tokens'=>0]])]),null);
    $manyTurn=$w->run($w->send($sid,'Releia o primeiro capítulo com calma.',[],$key)['id'],$key);
    $before=$requests[0]['body']['messages'];$after=$requests[1]['body']['messages'];$end=count($before)-1;
    check($manyTurn['status']==='completed'&&count($after[$end+1]['content'])===28&&breakpoints($requests[1]['body'])===['system.0','messages.'.$end.'.content.'.(count($before[$end]['content'])-1),'top']
        &&json_encode(uncached(array_slice($after,0,count($before))))===json_encode(uncached($before)),'after an answer of 28 blocks the cache is read where the previous request ended, and nothing earlier changed');
    // What the cache did, for the person to see: the story's totals and each turn's
    $usage=$w->usage($sid,$key);$u=$usage['turns'][0]['usage']['claude'];
    check($usage['turns'][0]['steps']===2&&$u['read']===103000&&$u['write']===7000&&$u['write_hour']===7000&&$u['input']===20&&$u['output']===920&&$u['thinking']===700
        &&abs($u['cost']-(103000*0.2+7000*8+20*4+920*20)/1e6)<1e-4&&abs($u['costs']['write']-7000*8/1e6)<1e-4&&$usage['turns'][0]['writer']==='Claude Opus 5.5'&&$usage['turns'][0]['effort']==='high','the usage of a turn: read from the cache, written to it for an hour, input, output and reasoning');
    $t=$usage['total']['claude'];
    check(abs($t['cost']-$w->spent($sid,$key)['claude'])<1e-3&&$t['requests']>=10&&$t['thinking']===null&&$t['warms']===1&&$t['warm_cost']>0
        &&abs($usage['total']['openai']['cost']-$w->spent($sid,$key)['openai'])<1e-3&&$usage['total']['openai']['read']===4000,'the story\'s totals match what it cost, by provider, with the keep-alives apart and the reasoning unknown where the API didn\'t say');
    rejects(fn()=>$w->usage(str_repeat('0',32),$key),'a story that does not exist has no usage',404);

    // One that lands as the turn ends (after its last check): a new turn answers it in the same process
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Feito.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Sobre o farol: sim.']],'end_turn')]),null);
    $turn=$w->send($sid,'Revise a cena.',[],$key);
    $iid=bin2hex(random_bytes(16));
    $sealed=bin2hex(Crypto::seal(json_encode(['text'=>'E o farol?','images'=>[]]),$key,"story-inbox:$iid"));
    $vault->db->exec("CREATE TEMP TRIGGER late_message AFTER UPDATE OF status ON story_turns WHEN NEW.id='{$turn['id']}' AND NEW.status='completed' BEGIN INSERT INTO story_inbox(id,story_id,user_id,created,content) VALUES('$iid','$sid','{$owner['id']}',0,X'$sealed'); END");
    $first=$w->run($turn['id'],$key);
    $vault->db->exec('DROP TRIGGER late_message');
    $last=$w->lastTurn($sid,$key);
    check($first['status']==='completed'&&count($requests)===2&&$last['id']!==$turn['id']&&$last['status']==='completed','a message that lands as the turn ends gets a new turn, run at once');
    $msgs=$requests[1]['body']['messages'];
    check(end($msgs)['role']==='user'&&str_contains(json_encode(end($msgs),JSON_UNESCAPED_UNICODE),'E o farol?')&&$w->inbox($sid,$key)===[],'and the writer reads it there');

    // One left waiting by a turn that stopped goes in with the next request, before the new message
    $requests=[];
    $w=$writer->withTransports(player($requests,[function(Closure $onEvent,Closure $tick) use ($writer,$sid,$key): array { $writer->send($sid,'Esquece o farol.',[],$key); throw new Cancelled(); },
        claudeEvents([['type'=>'text','text'=>'Certo.']],'end_turn')]),null);
    $stopped=$w->run($w->send($sid,'Outra cena.',[],$key)['id'],$key);
    $view=$w->view($sid,$key);$lastMessage=end($view['messages']);
    check($stopped['status']==='cancelled'&&$view['inbox']===[]&&!empty($lastMessage['during'])&&$lastMessage['text']==='Esquece o farol.','a stopped turn keeps the message in the chat, unanswered, and starts nothing');
    $w->run($w->send($sid,'Seguimos.',[],$key)['id'],$key);
    $all=json_encode(end($requests)['body']['messages'],JSON_UNESCAPED_UNICODE);
    check(strpos($all,'Esquece o farol.')<strrpos($all,'Seguimos.'),'the next request carries it, before the new message');
    $insert=(new ReflectionMethod(Writer::class,'enqueue'));
    $insert->invoke($w,$sid,'Mensagem esquecida.',[],$key);
    check(count($w->inbox($sid,$key))===1,'a message can be left waiting when no turn reads it');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $w->run($w->send($sid,'Nova mensagem.',[],$key)['id'],$key);
    $all=json_encode(end($requests[0]['body']['messages']),JSON_UNESCAPED_UNICODE);
    check(strpos($all,'Mensagem esquecida.')!==false&&strpos($all,'Mensagem esquecida.')<strpos($all,'Nova mensagem.')&&$w->inbox($sid,$key)===[],'a new turn first delivers what was left waiting');

    // A process that PHP stops (out of memory) fails its turn with the reason instead of going silent
    $tid=$w->send($sid,'Mais.',[],$key)['id'];
    $vault->db->prepare("UPDATE story_turns SET status='running', started=? WHERE id=?")->execute([time()-130,$tid]);
    (new ReflectionMethod(Writer::class,'died'))->invoke($w,$tid,['type'=>E_ERROR,'message'=>'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)','file'=>'x','line'=>1],$key);
    $dead=$w->turn($tid,$key);
    check($dead['status']==='failed'&&str_contains($dead['error'],'sem memória')&&str_contains($dead['error'],'2 min')&&$dead['error_kind']==='server','a turn whose process ran out of memory says so, and for how long it worked');
    $vault->db->prepare("UPDATE story_turns SET status='completed' WHERE id=?")->execute([$tid]);
    check(Writer::duration(45)==='45 s'&&Writer::duration(3900)==='1 h 05 min','durations read as the page says them');

    // ---------------------------------------------------------------------------------------------------------------
    // A turn in a separate process (bin/worker.php through Spawn), which the web server can't stop after two minutes
    if(Studio\Spawn::unavailable()===null){
        $tid=$writer->send($sid,'Num processo separado.',[],$key)['id'];
        $claimed=$writer->runSeparately($tid,$key,__DIR__.'/worker_fixture.php');
        check($claimed!==null&&$claimed['id']===$tid&&$claimed['status']!=='queued','a separate process claims the turn');
        for($i=0;$i<80&&$writer->turn($tid,$key)['status']!=='completed';$i++)usleep(100000);
        $chat=$writer->chat($sid,$key);$last=end($chat);
        check($writer->turn($tid,$key)['status']==='completed'&&str_starts_with((string)$last['text'],'Escrito num processo separado (pid ')&&!str_contains($last['text'],'(pid '.getmypid().')'),'and runs it to the end in another process, with the key it got through a pipe');
        check($writer->runSeparately($tid,$key)['status']==='completed','a turn already taken is not started again');
        check(Studio\Worker::main(['worker.php','story',$tid,$owner['id'],$dir],fopen('php://memory','r'))===2,'the worker refuses to run without a key');
        check(!Studio\Spawn::failedRecently($dir)&&Studio\Spawn::webLimit()===null,'a separate process that worked leaves the server marked as able');
        $version=new ReflectionMethod(Studio\Spawn::class,'version');
        check($version->invoke(null,Studio\Spawn::php())===PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION&&$version->invoke(null,'/opt/alt/php84/usr/bin/php')==='8.4'&&$version->invoke(null,'/bin/true')===null,'the separate process uses the command line PHP of the same version as the page');
        // Image requests too: the one the page launches, one left in line when there is no room, and MCP's, one after another
        $fixture=__DIR__.'/worker_fixture.php';$jid=bin2hex(random_bytes(16));
        $jobs->create(['id'=>$jid,'prompt'=>'Uma montanha num processo separado','options'=>[]],$key);
        $taken=$jobs->runSeparately($jid,$key,$fixture);
        check($taken!==null&&$taken['id']===$jid&&$taken['status']!=='queued','a separate process takes the image request the page launched');
        for($i=0;$i<80&&$jobs->freshJob($jid,$key)['status']!=='completed';$i++)usleep(100000);
        check($jobs->freshJob($jid,$key)['status']==='completed'&&count($jobs->freshJob($jid,$key)['image_ids'])===1,'and saves its image in the gallery');
        $busy=[];for($i=0;$i<4;$i++){$busy[]=$b=bin2hex(random_bytes(16));$vault->db->prepare("INSERT INTO jobs (id,payload,status,created,updated,user_id) VALUES (?,X'00','running',?,?,?)")->execute([$b,time(),time(),'someone-else']);}
        $waiting=bin2hex(random_bytes(16));$jobs->create(['id'=>$waiting,'prompt'=>'Na fila','options'=>[]],$key);
        $left=$jobs->runSeparately($waiting,$key,$fixture);
        check($left!==null&&$left['status']==='queued'&&!Studio\Spawn::failedRecently($dir),'with no room the request stays in line, and the server is not taken for one that cannot run them');
        $vault->db->exec("DELETE FROM jobs WHERE user_id='someone-else'");
        $second=bin2hex(random_bytes(16));$jobs->create(['id'=>$second,'prompt'=>'Outra','options'=>[]],$key);
        check($jobs->startSeparately([$waiting,$second],$key,$fixture),'MCP\'s requests start in one separate process');
        for($i=0;$i<100&&($jobs->freshJob($waiting,$key)['status']!=='completed'||$jobs->freshJob($second,$key)['status']!=='completed');$i++)usleep(100000);
        check($jobs->freshJob($waiting,$key)['status']==='completed'&&$jobs->freshJob($second,$key)['status']==='completed','which runs them one after another');
        // A separate process that can't work here (an extension missing in the command line PHP, say) leaves the work to
        // the web request, and the hosting test says why
        $failing=$dir.'/failing-worker.php';
        file_put_contents($failing,'<?php require '.var_export(dirname(__DIR__).'/app/bootstrap.php',true).'; Studio\Spawn::tell($argv[4],$argv[5],"failed: faltam extensões no PHP de linha de comando (gd)");');
        $tid=$writer->send($sid,'Sem processo separado.',[],$key)['id'];
        check($writer->runSeparately($tid,$key,$failing)===null&&$writer->turn($tid,$key)['status']==='queued','a separate process that cannot work leaves the turn to the web request');
        check(Studio\Spawn::lastFailure($dir)==='faltam extensões no PHP de linha de comando (gd)'&&$writer->runSeparately($tid,$key)===null,'and for an hour the server is left alone, with the reason kept');
        check((new Studio\HostTest($dir))->begin(5)['info']['separate_failed']==='faltam extensões no PHP de linha de comando (gd)','the hosting test shows why');
        Studio\Spawn::outcome($dir,null);unlink($failing);
        $vault->db->prepare("UPDATE story_turns SET status='cancelled' WHERE id=?")->execute([$tid]);
        // The hosting test's separate process
        $test=new Studio\HostTest($dir);$run=$test->begin(5);
        check(Studio\Spawn::start(dirname(__DIR__).'/bin/worker.php',['host-test',$run['id'],$dir]),'the hosting test starts its separate process');
        $test->unavailable($run['id'],'web','sem como responder antes');
        for($i=0;$i<120&&($test->status()['kinds']['separate']['state']??'')!=='completed';$i++)usleep(100000);
        $status=$test->status();
        check($status['kinds']['separate']['state']==='completed'&&$status['kinds']['separate']['seconds']>=5&&$status['kinds']['web']['state']==='unavailable'&&$status['kinds']['web']['why']==='sem como responder antes'
            &&$status['kinds']['web-plain']['state']==='waiting'&&isset($status['info']['max_execution_time'])&&$status['kinds']['separate']['php']===PHP_VERSION&&$status['kinds']['separate']['extensions']===[]
            &&array_key_exists('limit',$status['info']),'it reports how long each process lived, why one could not start, and the PHP of the separate one');
    }else echo "SKIP separate process: ".Studio\Spawn::unavailable()."\n";

    // Where the person stopped reading is kept with the story, sealed, and isn't a change to it
    $chapterId=$stories->get($sid,$key)['chapters'][0]['id'];$before=$stories->stamp($sid);
    check($stories->get($sid,$key)['reading']===null,'a story not read yet has no reading place');
    $stories->setReading($sid,['chapter'=>$chapterId,'block'=>3,'offset'=>0.4567],$key);
    $place=$stories->get($sid,$key)['reading'];
    check($place['chapter']===$chapterId&&$place['block']===3&&$place['offset']===0.457&&$place['at']>=time()-5&&$stories->stamp($sid)===$before,'the reading place is kept, and the story keeps its place in the list');
    $stories->setReading($sid,['chapter'=>$chapterId,'block'=>-4,'offset'=>7],$key);
    $place=$stories->get($sid,$key)['reading'];
    check($place['block']===0&&(float)$place['offset']===1.0,'a reading place out of range is brought back in');
    rejects(fn()=>$stories->setReading($sid,['chapter'=>'../x','block'=>1],$key),'a reading place needs a real chapter id',400);
    rejects(fn()=>$stories->setReading($sid,'nada',$key),'and a place at all',422);
    rejects(fn()=>$stories->setReading(str_repeat('0',32),['chapter'=>$chapterId],$key),'a story that does not exist has no reading place',404);
    check(!str_contains((string)$vault->db->query("SELECT meta FROM stories WHERE id='$sid'")->fetchColumn(),$chapterId),'the reading place is sealed with the story');

    // Duplicating: a copy to change on its own, with the conversation with the writer
    $orig=$stories->get($sid,$key,null,true);$origChat=$writer->chat($sid,$key);$origMessages=(int)$vault->db->query("SELECT COUNT(*) FROM story_messages WHERE story_id='$sid'")->fetchColumn();
    $copy=$writer->duplicate($sid,'  O Farol,   outra versão ',$key);$cid=$copy['id'];
    $dup=$stories->get($cid,$key,null,true);
    check($cid!==$sid&&$copy['title']==='O Farol, outra versão'&&$copy['chapters']===count($orig['chapters'])&&$copy['words']===$orig['words']&&$copy['cover']===$orig['cover']&&!$copy['favorite'],'a copy has its own id and title, the same chapters and cover');
    check(array_column($dup['chapters'],'text')===array_column($orig['chapters'],'text')&&array_column($dup['chapters'],'title')===array_column($orig['chapters'],'title')
        &&array_column($dup['chapters'],'rev')===array_column($orig['chapters'],'rev')&&!array_intersect(array_column($dup['chapters'],'id'),array_column($orig['chapters'],'id')),'its chapters are new rows with the same text, illustrations included');
    check($dup['notes']['text']===$orig['notes']['text']&&$dup['plan']['text']===$orig['plan']['text']&&$dup['plan']['rev']===$orig['plan']['rev']&&$dup['cast']===$orig['cast']&&$dup['writer']===$orig['writer']&&$dup['synopsis']===$orig['synopsis'],'and the bible, the plan, the sheets and the writer\'s settings');
    $strip=fn(array $chat)=>array_map(fn($m)=>array_diff_key($m,['seq'=>1,'turn_id'=>1]),$chat);
    $dupChat=$writer->chat($cid,$key);
    check($strip($dupChat)===$strip($origChat)&&(int)$vault->db->query("SELECT COUNT(*) FROM story_messages WHERE story_id='$cid'")->fetchColumn()===$origMessages,'the conversation with the writer comes along, every message of it');
    check(count(array_filter(array_column($dupChat,'turn_id')))===count(array_filter(array_column($origChat,'turn_id')))&&!array_intersect(array_filter(array_column($dupChat,'turn_id')),array_filter(array_column($origChat,'turn_id'))),'with turns of its own');
    check($writer->spent($cid,$key)===['claude'=>0.0,'openai'=>0.0,'gemini'=>0.0]&&$writer->usage($cid,$key)['total']===[]&&$writer->warmIn($cid,$key)===null&&$writer->spent($sid,$key)['claude']>0,'what the original cost stays with it, and the copy\'s cache is not kept warm');
    check($dup['reading']===null&&$orig['reading']!==null&&$stories->get($cid,$key)['conversation']===$orig['conversation'],'the copy is read from the start');
    $raw=(string)$vault->db->query("SELECT meta FROM stories WHERE id='$cid'")->fetchColumn();
    check(!str_contains($raw,'outra versão')&&!str_contains(implode('',$vault->db->query("SELECT body FROM chapters WHERE story_id='$cid'")->fetchAll(PDO::FETCH_COLUMN)),'Marina'),'the copy is sealed in the database');
    check($stories->album($cid,$key)!==$stories->album($sid,$key),'each story has its own album');
    // Changing the copy leaves the original as it was
    $stories->updateChapter($cid,$dup['chapters'][0]['id'],['text'=>'Só na cópia.'],'person',$key);
    $stories->writeDocument($cid,'plan',['append'=>'Outro final.'],'person',$key);
    $stories->update($cid,['title'=>'A cópia','synopsis'=>'Outra sinopse.'],$key);
    $stories->updateCast($cid,['character'=>'Personagem da cópia'],'person',$key);
    $after=$stories->get($sid,$key,null,true);
    check($after['chapters'][0]['text']===$orig['chapters'][0]['text']&&$after['plan']['text']===$orig['plan']['text']&&$after['title']===$orig['title']&&$after['synopsis']===$orig['synopsis']&&$after['cast']===$orig['cast'],'changing the copy leaves the original as it was');
    check($stories->get($cid,$key)['chapters'][0]['text']==='Só na cópia.','and the copy keeps its change');
    // The copy's writer goes on from the same conversation, told once that this is a copy
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Na cópia.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Na original.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'De novo.']],'end_turn')]),null);
    $claudeWriter=['provider'=>'claude','model'=>'claude-opus-5-5'];
    $w->run($w->send($cid,'Siga.',$claudeWriter,$key)['id'],$key);
    $w->run($w->send($sid,'Siga.',$claudeWriter,$key)['id'],$key);
    [$inCopy,$inOrig]=[$requests[0]['body']['messages'],$requests[1]['body']['messages']];
    $lastCopy=json_encode(end($inCopy),JSON_UNESCAPED_UNICODE);
    check(count($inCopy)>3&&json_encode(uncached(array_slice($inCopy,0,-1)))===json_encode(uncached(array_slice($inOrig,0,-1))),'the copy\'s writer gets the same conversation as the original\'s');
    check(str_contains($lastCopy,'The person duplicated the story: this is now a copy of \"'.$orig['title'].'\"')&&str_contains($lastCopy,'Title: A cópia')&&str_contains($lastCopy,'was edited by the person')
        &&!str_contains(json_encode(end($inOrig),JSON_UNESCAPED_UNICODE),'duplicated'),'and hears that it is a copy and what changed in it');
    $w->run($w->send($cid,'E agora?',$claudeWriter,$key)['id'],$key);
    check(!str_contains(json_encode(end($requests[2]['body']['messages']),JSON_UNESCAPED_UNICODE),'duplicated'),'only once');
    check($writer->spent($cid,$key)['claude']>0&&count($writer->usage($cid,$key)['turns'])===2,'from then on the copy counts what it costs');
    $plain=$writer->duplicate($sid,null,$key);
    check($plain['title']===$orig['title'].' (cópia)','without a title the copy is called after the original');
    $stories->delete($plain['id']);
    $busy=$w->send($sid,'Espere.',[],$key);
    rejects(fn()=>$writer->duplicate($sid,'X',$key),'a story is not duplicated while the writer works on it',409);
    $w->cancel($busy['id'],$key);
    rejects(fn()=>$writer->duplicate(str_repeat('0',32),'X',$key),'a story that does not exist is not duplicated',404);

    // The whole story in a file and back (the page makes the zip): the story, then its conversation a page at a time
    $orig=$stories->get($sid,$key,null,true);$origChat=$writer->chat($sid,$key);
    // A picture still being made (a job) isn't a picture yet: its line stays out of the file.
    $texts=fn(array $chapters)=>array_map(fn($t)=>trim(preg_replace('/\n{3,}/',"\n\n",preg_replace('/^!\[[^\]\n]*\]\(job:[a-f0-9]{32}\)[ \t]*$/m','',$t))),array_column($chapters,'text'));
    check(str_contains(implode('',array_column($orig['chapters'],'text')),'](job:'),'(the story has a picture being made)');
    $stories->update($sid,['cover_design'=>['format'=>'light_novel','author'=>'R. Sato','volume'=>'3']],$key);
    $pack=['conversation'=>$writer->exportConversation($sid,$key)]+$stories->export($sid,$key,$jobs);
    check($pack['format']==='atelier-story'&&$pack['version']===1&&$pack['story']['id']===$sid&&$pack['story']['title']===$orig['title']&&$pack['plan']['text']===$orig['plan']['text']&&$pack['notes']['text']===$orig['notes']['text']
        &&array_column($pack['chapters'],'text')===$texts($orig['chapters'])&&array_column($pack['chapters'],'rev')===array_column($orig['chapters'],'rev')&&$pack['cast']['characters']===$orig['cast']['characters'],'a story goes to a file whole: chapters, bible, plan (with its spoilers) and sheets');
    check(isset($pack['images'][$imageId])&&array_keys($pack['images'][$imageId])===['title','prompt']&&!isset($pack['images'][$sid])&&!isset($pack['images'][$orig['chapters'][0]['id']]),'with the pictures it names, and only pictures');
    check($pack['conversation']['messages']===(int)$vault->db->query("SELECT COUNT(*) FROM story_messages WHERE story_id='$sid'")->fetchColumn()&&$pack['conversation']['current']===$orig['conversation']
        &&count($pack['conversation']['turns'])===(int)$vault->db->query("SELECT COUNT(*) FROM story_turns WHERE story_id='$sid'")->fetchColumn()&&!array_filter($pack['conversation']['turns'],fn($t)=>isset($t['costs'])||isset($t['usage'])),'the conversation says how many messages it has, and its turns go without their costs');
    $page=$writer->exportMessages($sid,0,$key);
    check(count($page['messages'])===$pack['conversation']['messages']&&$page['next']===null&&$page['messages'][0]['content'] instanceof stdClass,'its messages come as they were kept, objects as objects');
    $firstSeq=(int)$vault->db->query("SELECT MIN(seq) FROM story_messages WHERE story_id='$sid'")->fetchColumn();
    check(count($writer->exportMessages($sid,$firstSeq,$key)['messages'])===$pack['conversation']['messages']-1,'from a message on');
    // Back, as the page sends it: the file's story, the conversation without its messages, then the messages as JSON text
    $file=json_decode(json_encode($pack,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);
    $asText=fn(array $messages)=>array_map(fn($m)=>['content'=>json_encode($m['content'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]+$m,$messages);
    $talk=$file['conversation'];unset($talk['messages']);
    $made=$stories->import($file,Writer::settings(null,$file['story']['writer']),$key);$iid=$made['id'];
    $writer->importConversation($iid,['title'=>$orig['title']]+$talk,$made['map'],$key);
    check($stories->get($iid,$key)['conversation']===$orig['conversation']+1&&$writer->chat($iid,$key)===[],'until its messages are in, the imported story shows a new conversation');
    $messages=$asText($page['messages']);$half=intdiv(count($messages),2);
    check($writer->importMessages($iid,array_slice($messages,0,$half),false,$key)['added']===$half&&$writer->importMessages($iid,array_slice($messages,$half),true,$key)['added']===count($messages)-$half,'the messages come a page at a time');
    $imp=$stories->get($iid,$key,null,true);
    check($iid!==$sid&&$made['dropped']===0&&$imp['title']===$orig['title']&&array_column($imp['chapters'],'text')===$texts($orig['chapters'])&&array_column($imp['chapters'],'title')===array_column($orig['chapters'],'title')
        &&array_column($imp['chapters'],'rev')===array_column($orig['chapters'],'rev')&&!array_intersect(array_column($imp['chapters'],'id'),array_column($orig['chapters'],'id')),'the file comes back as a new story with the same chapters, under new ids');
    check($imp['notes']===$orig['notes']||($imp['notes']['text']===$orig['notes']['text']&&$imp['notes']['rev']===$orig['notes']['rev'])&&$imp['plan']['text']===$orig['plan']['text']&&$imp['plan']['rev']===$orig['plan']['rev']&&$imp['cast']['characters']===$orig['cast']['characters']
        &&$imp['cover']===$orig['cover']&&$imp['writer']===$orig['writer']&&$imp['synopsis']===$orig['synopsis']&&$imp['reading']===null,'with its bible, plan, sheets, cover and writer');
    check($imp['cover_design']['chosen']==='light_novel'&&$imp['cover_design']['author']==='R. Sato'&&$imp['cover_design']['volume']==='3','and how its covers are designed');
    check($strip($writer->chat($iid,$key))===$strip($origChat)&&$stories->get($iid,$key)['conversation']===$orig['conversation'],'and the whole conversation with the writer');
    check($writer->spent($iid,$key)===['claude'=>0.0,'openai'=>0.0,'gemini'=>0.0]&&!str_contains((string)$vault->db->query("SELECT meta FROM stories WHERE id='$iid'")->fetchColumn(),$orig['title']),'starting at no cost, sealed');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Na importada.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Na original.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'De novo.']],'end_turn')]),null);
    $w->run($w->send($iid,'Siga.',$claudeWriter,$key)['id'],$key);
    $w->run($w->send($sid,'Siga.',$claudeWriter,$key)['id'],$key);
    [$inImport,$inOrig]=[$requests[0]['body']['messages'],$requests[1]['body']['messages']];
    $lastImport=json_encode(end($inImport),JSON_UNESCAPED_UNICODE);
    check(count($inImport)>3&&json_encode(uncached(array_slice($inImport,0,-1)))===json_encode(uncached(array_slice($inOrig,0,-1))),'the imported story\'s writer gets the same conversation, with the new ids');
    check(str_contains($lastImport,'imported it into the app again, as a new story (\"'.$orig['title'].'\")')&&!str_contains($lastImport,'was added by the person')&&!str_contains($lastImport,'was edited by the person')&&!str_contains($lastImport,'The person edited'),'and hears once that the story came from a file, with nothing else new');
    $w->run($w->send($iid,'E agora?',$claudeWriter,$key)['id'],$key);
    check(!str_contains(json_encode(end($requests[2]['body']['messages']),JSON_UNESCAPED_UNICODE),'imported it'),'only once');
    rejects(fn()=>$writer->importMessages($iid,$messages,true,$key),'messages can be added only while the story is being imported',409);
    // An import that stops halfway leaves a story whose writer starts over from it
    $half2=$stories->import($file,Writer::settings(null),$key);
    $writer->importConversation($half2['id'],['title'=>'X','turns'=>[['id'=>str_repeat('9',32),'conversation'=>1,'status'=>'running','created'=>1,'updated'=>1]]]+$talk,$half2['map'],$key);
    $writer->importMessages($half2['id'],array_slice($messages,0,2),false,$key);
    check($vault->db->query("SELECT status FROM story_turns WHERE story_id='{$half2['id']}' ORDER BY created LIMIT 1")->fetchColumn()==='interrupted'&&(int)$vault->db->query("SELECT COUNT(*) FROM story_turns WHERE story_id='{$half2['id']}' AND status IN ('queued','running','cancelling')")->fetchColumn()===0,'a turn that was working in the file comes back interrupted');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Do zero.']],'end_turn')]),null);
    $w->run($w->send($half2['id'],'Siga.',$claudeWriter,$key)['id'],$key);
    check(count($requests[0]['body']['messages'])<count($inImport)&&!str_contains(json_encode($requests[0]['body']['messages'],JSON_UNESCAPED_UNICODE),'imported it'),'an import that stopped halfway leaves the writer a new conversation, not half of one');
    rejects(fn()=>$writer->importMessages($half2['id'],array_slice($messages,2),true,$key),'and the rest of the messages no longer go in',409);
    $stories->delete($half2['id']);
    // What a file can't bring
    $bad=$file;$bad['story']['cover']=str_repeat('0',32);$bad['chapters'][0]['text']="Antes.\n\n![x](img:".str_repeat('0',32).")\n\n![y](job:".str_repeat('1',32).")\n\nDepois.";
    $bad['cast']['style']['images'][]=['image'=>str_repeat('2',32),'caption'=>'Não é daqui'];
    $odd=$stories->import($bad,Writer::settings(null),$key);$oddStory=$stories->get($odd['id'],$key);
    check($odd['dropped']===3&&$oddStory['cover']!==str_repeat('0',32)&&$oddStory['chapters'][0]['text']==="Antes.\n\nDepois."&&$oddStory['cast']['style']['images']===[],'pictures that are not the account\'s, and requests for pictures, are left out and counted');
    $stories->delete($odd['id']);
    rejects(fn()=>$stories->import(['story'=>['title'=>'  '],'chapters'=>[]],Writer::settings(null),$key),'a file with no title and no chapters is no story',422);
    rejects(fn()=>$stories->import(['story'=>['title'=>'X'],'chapters'=>['texto']],Writer::settings(null),$key),'a chapter must be an object',422);
    $long=$stories->import(['story'=>['title'=>str_repeat('a',300)]],Writer::settings(null),$key);
    check(mb_strlen($stories->get($long['id'],$key)['title'])===200,'a title too long is cut as in the app');
    $stories->delete($long['id']);
    rejects(fn()=>$stories->import(['story'=>['title'=>['a']]],Writer::settings(null),$key),'a title must be text',422);
    $titled=$stories->import(['chapters'=>[['title'=>'Um','text'=>'Texto.']]],Writer::settings(null),$key);
    check($stories->get($titled['id'],$key)['title']==='História importada'&&$titled['map']===[],'a file with chapters and no title gets one');
    rejects(fn()=>$writer->importMessages($titled['id'],[],true,$key),'a story not being imported takes no messages',409);
    $writer->importConversation($titled['id'],['current'=>1],[],$key);
    rejects(fn()=>$writer->importMessages($titled['id'],[['role'=>'system','content'=>'{}']],true,$key),'a message with a role the app doesn\'t have is refused',422);
    rejects(fn()=>$writer->importMessages($titled['id'],[['role'=>'user','content'=>'{"provider":"claude"}']],true,$key),'a message without what the app keeps is refused',422);
    $stories->delete($titled['id']);$stories->delete($iid);
    $stories->delete($sid);
    check(count($stories->get($cid,$key)['chapters'])===count($orig['chapters'])&&$writer->chat($cid,$key)!==[],'deleting the original leaves the copy');
    $stories->delete($cid);

    // Deleting the story takes its chapters and conversation, not its illustrations
    check((int)$vault->db->query('SELECT COUNT(*) FROM chapters')->fetchColumn()===0&&(int)$vault->db->query('SELECT COUNT(*) FROM story_messages')->fetchColumn()===0&&$vault->image($imageId,$key)['id']===$imageId,'deleting a story keeps its images in the gallery');

    // ---------------------------------------------------------------------------------------------------------------
    // The cover, the sheets of characters and places, the visual style and the lettering
    $jobOf=fn(string $id)=>array_values(array_filter($jobs->list($key,'active',[$id]),fn($j)=>$j['id']===$id))[0];
    $defaults=Writer::settings(null);
    check($defaults['cover']==='auto'&&$defaults['sheets']==='auto'&&$defaults['image_scale']==='1','by default the writer makes the cover and the sheets on its own, at the usual size');
    rejects(fn()=>Writer::settings(null,['cover'=>'sometimes']),'an unknown way to make the cover is refused',422);
    rejects(fn()=>Writer::settings(null,['image_scale'=>'3']),'only the usual size, 1.5× or 2×',422);
    $flex=Writer::settings(null,['image_model'=>'gpt-image-2','image_scale'=>'2']);
    check(Writer::sizeFor($flex,'2:3')==='2048x3072'&&Writer::sizeFor($flex,'3:2')==='3072x2048'&&Writer::sizeFor(['image_scale'=>'1']+$flex,'2:3')===OpenAI::SIZES['2:3']
        &&Writer::sizeFor(['image_scale'=>'1.5']+$flex,'2:3')==='1536x2304','pictures come out at the usual size, 1.5× or 2× it');
    check(Writer::sizeFor(Writer::settings(null,['image_model'=>'gpt-image-1-mini','image_scale'=>'2']),'2:3')==='1024x1536','a model with fixed sizes makes its usual one');

    $cs=$stories->create('As Irmãs do Vale',$key)['id'];
    $stories->update($cs,['synopsis'=>'Duas irmãs atravessam o vale.','writer'=>Writer::settings(null,['image_model'=>'gpt-image-2'])],$key);
    $stories->addChapter($cs,'Um','Ana olhou o vale.','',null,'person',$key);
    $lib=[];
    foreach(['a','b','c','d'] as $n){ $one=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>"imagem $n",'options'=>['model'=>'gpt-image-2','quality'=>'low','size'=>'1024x1024','n'=>1,'previews'=>1]],$key)[0]; $lib[$n]=$jobs->run($one['id'],$key,$api)['image_ids'][0]; }
    $done=$stories->updateCast($cs,['character'=>'Ana','note'=>'a irmã mais velha','add'=>[['image'=>$lib['a'],'caption'=>'Aos 20 anos'],['image'=>$lib['b'],'caption'=>'Aos 40 anos']]],'person',$key);
    check($done['character']['kind']==='character'&&count($done['character']['images'])===2&&$done['cast']['rev']===1&&$done['cast']['by']==='person','a sheet is made with pictures from the gallery, several forms of the same character');
    $stories->updateCast($cs,['character'=>'ana','main'=>$lib['b'],'captions'=>[$lib['a']=>'Jovem']],'person',$key);
    $ana=$stories->cast($cs,$key)['characters'][0];
    check($ana['images'][0]['image']===$lib['b']&&$ana['images'][1]['caption']==='Jovem','the main picture goes first, and captions change; a name is found ignoring case');
    $stories->updateCast($cs,['character'=>'O Vale','kind'=>'place','add'=>[['image'=>$lib['c']]]],'person',$key);
    rejects(fn()=>$stories->updateCast($cs,['character'=>'O Vale','new_name'=>'ANA'],'person',$key),'two sheets can\'t have the same name',422);
    rejects(fn()=>$stories->updateCast($cs,['character'=>'Ana','kind'=>'monster'],'person',$key),'a sheet is of a character or a place',422);
    rejects(fn()=>$stories->updateCast($cs,['character'=>'Ana','add'=>[['image'=>str_repeat('f',32)]]],'person',$key),'only an image of the gallery goes in a sheet',404);
    check(str_contains(Writer::castLines($stories->cast($cs,$key)),"- Place: O Vale: image ".$lib['c'])&&str_contains(Writer::castLines($stories->cast($cs,$key)),'- Ana (a irmã mais velha): image '.$lib['b'].' "Aos 40 anos" (main)'),'the writer reads the sheets with their image ids');
    $summary=array_values(array_filter($stories->all($key),fn($s)=>$s['id']===$cs))[0];
    check($summary['faces']===[['name'=>'Ana','image'=>$lib['b']]]&&$summary['cover']===null&&$summary['cover_chosen']===false&&$summary['cover_size']===null,'the list knows each character\'s main picture, for a cover made from the page');

    // The visual style goes with every new picture: its description after the prompt, its pictures after the references
    $cast=$stories->updateStyle($cs,['text'=>'Watercolor with ink lines.','add'=>[['image'=>$lib['d'],'caption'=>'o traço']]],'person',$key);
    check($cast['style']['text']==='Watercolor with ink lines.'&&$cast['style']['images'][0]['image']===$lib['d']&&$cast['rev']===4,'the style has a description and pictures');
    $ctx=new Studio\ToolContext($cs,str_repeat('e',32),Writer::settings(null,['image_model'=>'gpt-image-2','image_scale'=>'2']),$key);
    $wi=$writer->withImageApi($api);
    [$ok,$out,$label]=$wi->runTool('character_sheet',['character'=>'Bia','prompt'=>'Bia, 17, @1 is her sister','caption'=>'Aos 17 anos','note'=>'a caçula','references'=>[$lib['b']]],$ctx);
    $sheetJob=end($ctx->jobs);$job=$jobOf($sheetJob);
    check($ok&&$label==='Fez a ficha de Bia · “Aos 17 anos”'&&str_contains($out,'request '.$sheetJob),'the writer makes a sheet for a new character');
    check(str_contains($job['prompt'],"The story's visual style, the same in all of its pictures: Watercolor with ink lines. Attached image 2 is a style reference")&&$job['reference_count']===2
        &&$job['options']['size']==='3072x2048'&&$job['label']==='Ficha de Bia · Aos 17 anos','with the style after its own references, at the story\'s size');
    $bia=$stories->cast($cs,$key)['characters'][2];
    check($bia['name']==='Bia'&&$bia['note']==='a caçula'&&$bia['images'][0]['job']===$sheetJob&&$bia['images'][0]['image']===null&&$bia['images'][0]['by']==='agent','the sheet waits for its picture');
    // In the same turn, the request id works as a reference: the tool makes the picture (or waits for it) first
    [$ok,$out]=$wi->runTool('illustrate',['prompt'=>'Bia @1 in the valley','caption'=>'Bia no vale','chapter'=>1,'references'=>[$sheetJob]],$ctx);
    $page=$stories->get($cs,$key,$jobs);
    check($ok&&$jobOf($sheetJob)['status']==='completed'&&is_string($page['cast']['characters'][2]['images'][0]['image']),'a sheet asked for in this turn is ready as a reference, and becomes the sheet\'s picture');
    $illustration=end($ctx->jobs);
    check($jobOf($illustration)['reference_count']===2&&!str_contains($jobOf($illustration)['prompt'],'Lettering'),'an illustration takes it, with the style; no lettering without text');
    [$ok,$out]=$wi->runTool('update_character',['character'=>'Bia','main_image'=>$page['cast']['characters'][2]['images'][0]['image'],'new_name'=>'Beatriz'],$ctx);
    check($ok&&str_contains($out,'Beatriz')&&$stories->cast($cs,$key)['characters'][2]['name']==='Beatriz','the writer renames a sheet');
    rejects(fn()=>$stories->updateCast($cs,['character'=>'Ninguém','delete'=>true],'person',$key),'a sheet that isn\'t there can\'t be deleted',404);

    // The lettering: a description and a sample, with every picture that has text in it
    [$ok,$out]=$wi->runTool('set_style',['make_lettering_sample'=>true],$ctx);
    check(!$ok&&str_contains($out,'Describe the lettering first'),'a sample needs the lettering described');
    [$ok,$out,$label]=$wi->runTool('set_style',['lettering'=>'Hand-lettered comic capitals, black ink.','make_lettering_sample'=>true],$ctx);
    $sample=end($ctx->jobs);$job=$jobOf($sample);
    check($ok&&$label==='Pediu uma amostra das letras da história'&&str_contains($job['prompt'],'Hand-lettered comic capitals, black ink.')&&str_contains($job['prompt'],'CUIDADO!')
        &&$stories->cast($cs,$key)['style']['lettering']['job']===$sample,'the writer describes the lettering and makes its sample sheet');
    $jobs->run($sample,$key,$api);$page=$stories->get($cs,$key,$jobs);$letters=$page['cast']['style']['lettering'];
    check(is_string($letters['image'])&&$letters['job']===null&&str_contains(Writer::castLines($page['cast']),'Lettering (the app adds it to pictures with text in them): "Hand-lettered comic capitals, black ink."; sample image '.$letters['image']),'the sample becomes the lettering\'s picture');
    [$ok]=$wi->runTool('illustrate',['prompt'=>'A sign that says "VALE"','caption'=>'A placa','chapter'=>1,'lettering'=>true],$ctx);
    $job=$jobOf(end($ctx->jobs));
    check($ok&&str_contains($job['prompt'],'Attached image 1 is a style reference')&&str_contains($job['prompt'],'Lettering: every text drawn in this picture')&&str_contains($job['prompt'],'Attached image 2 is the lettering reference')&&$job['reference_count']===2,'a picture with text takes the lettering and its sample, last');

    foreach($ctx->jobs as $j) if($jobOf($j)['status']==='queued') $jobs->run($j,$key,$api);
    $stories->get($cs,$key,$jobs);

    // The covers from the page: a few at once, each in another design direction, as the front cover of the story's
    // format, the characters' sheets as likenesses, the title and the names lettered; the first one ready becomes the
    // cover, and all of them stay to choose from
    rejects(fn()=>$writer->makeCover($cs,['aspect'=>'7:1'],$key),'a cover needs a known format',422);
    rejects(fn()=>$writer->makeCover($cs,['prompt'=>'x','design'=>['format'=>'poster']],$key),'a cover is of a known kind',422);
    rejects(fn()=>$writer->makeCover($cs,['prompt'=>'x','styles'=>['classic','pop']],$key),'and of known styles',422);
    rejects(fn()=>$writer->makeCover($cs,['prompt'=>'x','styles'=>['classic','literal','modern','minimal','retro']],$key),'at most four styles at once',422);
    rejects(fn()=>$writer->makeCover($cs,['prompt'=>'x','count'=>5],$key),'and at most four covers',422);
    $auto=$stories->get($cs,$key)['cover_design'];
    check($auto['format']==='illustrated'&&$auto['chosen']===null&&$auto['why']==='illustrated'&&$auto['volume']===''&&$auto['author']==='','an illustrated story gets an illustrated novel\'s cover, with no volume');
    $covers=$writer->makeCover($cs,['prompt'=>'The two sisters on a hill','aspect'=>'2:3','lettering'=>true,'references'=>[$lib['b']],'design'=>['format'=>'manga','author'=>'M. Duarte','volume'=>'Vol. 2']],$key);
    $cover=$covers[0];
    check(count($covers)===3&&count(array_unique(array_column($covers,'prompt')))===1&&str_contains($cover['prompt'],'The front cover of a manga volume (tankōbon) titled "As Irmãs do Vale".')&&str_contains($cover['prompt'],'Duas irmãs atravessam o vale.')&&str_contains($cover['prompt'],'The cover shows: The two sisters on a hill')
        &&str_contains($cover['prompt'],'designed manga logo')&&str_contains($cover['prompt'],'the title "As Irmãs do Vale", the largest text, as the cover\'s title logo')&&str_contains($cover['prompt'],'the volume "Vol. 2"')
        &&str_contains($cover['prompt'],'the author\'s name "M. Duarte", small')&&str_contains($cover['prompt'],'show how the story\'s characters look, and only that')&&str_contains($cover['prompt'],'no mockup or 3D book')
        &&str_contains($cover['prompt'],'Watercolor with ink lines.')&&!str_contains($cover['prompt'],'Lettering: every text')
        &&$cover['reference_count']===2&&$cover['options']['size']==='1024x1536','three covers from the page: the format, the synopsis, the description, the faces as likenesses, the style and its words (not the balloons\' lettering)');
    check(str_contains($cover['prompt'],'Typography is half of the design')&&str_contains($cover['prompt'],'Avoid the commonplace')&&str_contains($cover['prompt'],'This is one of 3 covers made at once for the same book')
        &&str_contains($cover['prompt'],'other than the one given'),'with worked typography, away from the commonplace, each its own design');
    $labels=array_map(fn($j)=>($j['variation']['written'] ?? false) ? $j['variation']['label'] : '',$covers);
    check(preg_match('/^(Classic|Literal)\. /',$labels[0])===1&&preg_match('/^(Modern|Minimal|Retro)\. /',$labels[1])===1&&preg_match('/^(Abstract|Typographic)\. /',$labels[2])===1
        &&$covers[0]['variation']['count']===3,'each in a direction of its own: classic or literal, a graphic one, abstract or typographic');
    $drawn=[];for($i=0;$i<80;$i++)foreach(Writer::coverStyles(null) as $style)$drawn[$style]=true;
    check(count($drawn)===count(Writer::COVER_STYLES),'drawn at random, so each set is new');
    check(Writer::coverStyles(['typographic','classic','classic'])===['typographic','classic']&&count(Writer::coverStyles(null,1))===1&&count(array_unique(Writer::coverStyles(null,4)))===4&&count(Writer::coverStyles(null,'2'))===2,'or the styles asked for, or another number of covers');
    $directions=(new ReflectionClassConstant(Writer::class,'COVER_DIRECTIONS'))->getValue();
    check(array_keys($directions)===Writer::COVER_STYLES&&max(array_map('mb_strlen',$directions))<=1000&&!array_filter($directions,fn($d)=>str_contains($d,"\n")),'every direction fits in one line of a request');
    $design=$stories->get($cs,$key)['cover_design'];
    check($design['format']==='manga'&&$design['chosen']==='manga'&&$design['author']==='M. Duarte'&&$design['volume']==='2','the format, the author and the volume typed are kept for the next covers');
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check($summary['cover_making']==='queued'&&$summary['cover_job']===$cover['id']&&array_column(array_slice($summary['cover_choices'],0,3),'job')===array_column($covers,'id')
        &&array_column(array_slice($summary['cover_choices'],0,3),'status')===['queued','queued','queued'],'the list says the covers are being made');
    $refuse=new OpenAI('test',60,function(){ throw new HttpError(400,'Recusado.'); });
    $jobs->run($covers[0]['id'],$key,$refuse);$jobs->run($covers[1]['id'],$key,$refuse);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check($summary['cover_making']==='queued'&&array_column(array_slice($summary['cover_choices'],0,3),'status')===['failed','failed','queued']&&$summary['cover']!==null&&!$summary['cover_chosen'],'two that fail don\'t stop the set');
    $sent=[];$capture=new OpenAI('test',60,function($path,$body) use (&$sent,$png){ $sent[]=$body['prompt']; return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]]; });
    $jobs->run($covers[2]['id'],$key,$capture);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check($summary['cover_chosen']&&$summary['cover_by']==='person'&&$summary['cover']===$summary['cover_choices'][2]['image']&&!isset($summary['cover_making'])&&$summary['cover_job']===null,'the first one ready becomes the cover, made by the person');
    check(str_contains($sent[0],'Direction for this image: '.$labels[2])&&str_contains($sent[0],'one image of a set of 3')&&strpos($sent[0],'Watercolor with ink lines.')<strpos($sent[0],'Direction for this image'),'its direction goes last, after the style');
    $again=$writer->retryCover($cs,$key,$covers[0]['id']);
    check(count($again)===1&&$again[0]['variation']['label']===$labels[0]&&$stories->get($cs,$key)['cover_choices'][0]['job']===$again[0]['id'],'one that failed is made again, in its own direction');
    rejects(fn()=>$writer->retryCover($cs,$key,$covers[2]['id']),'one that came out is not made again',409);
    $jobs->run($again[0]['id'],$key,$api);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check(is_string($summary['cover_choices'][0]['image'])&&$summary['cover']===$summary['cover_choices'][2]['image'],'it joins the covers to choose from, without taking the cover');
    $stories->dropCoverJob($cs,$key,$covers[1]['id']);
    $choices=$stories->get($cs,$key)['cover_choices'];
    check(!in_array($covers[1]['id'],array_column($choices,'job'),true)&&count(array_filter(array_column($choices,'image')))===count($choices),'the other is left out');
    $stories->update($cs,['cover'=>$choices[0]['image']],$key);
    check($stories->get($cs,$key)['cover']===$choices[0]['image'],'choosing another one makes it the cover');
    // A set that fails whole is tried again whole; the cover chosen before stays among the choices
    $set=$writer->makeCover($cs,['prompt'=>'The valley at night','styles'=>['minimal','retro']],$key);
    foreach($set as $j)$jobs->run($j['id'],$key,$refuse);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check(count($set)===2&&$summary['cover_making']==='failed'&&$summary['cover_error']!==''&&$summary['cover']===$choices[0]['image'],'a set that doesn\'t come out says so, keeping the cover');
    $again=$writer->retryCover($cs,$key);
    check(count($again)===2&&array_map(fn($j)=>$j['variation']['label'],$again)===array_map(fn($j)=>$j['variation']['label'],$set),'tried again, each cover in its direction');
    $jobs->run($again[1]['id'],$key,$api);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check($summary['cover']===$summary['cover_choices'][1]['image']&&!isset($summary['cover_making']),'and the first ready becomes the cover');
    $jobs->run($again[0]['id'],$key,$api);$stories->all($key,$jobs);
    $stories->update($cs,['cover'=>$lib['b']],$key);
    $one=$writer->makeCover($cs,['prompt'=>'Only the title','count'=>1],$key);
    $choices=$stories->get($cs,$key)['cover_choices'];
    check(count($one)===1&&preg_match('/\n\nDirection for this image: [A-Z][a-z]+\. /',$one[0]['prompt'])===1&&!str_contains($one[0]['prompt'],'This is one of')&&$one[0]['variation']===null
        &&$choices[0]['job']===$one[0]['id']&&$choices[1]['style']==='before'&&$choices[1]['image']===$lib['b'],'a single cover takes its direction in its prompt; the cover chosen before stays to go back to');
    $stories->dropCoverJob($cs,$key);
    check($stories->get($cs,$key)['cover_job']===null&&!in_array($one[0]['id'],array_column($stories->get($cs,$key)['cover_choices'],'job'),true)&&$stories->get($cs,$key)['cover']===$lib['b'],'a set set aside leaves the cover as it was');
    $made=$summary['cover'];
    $madeImage=$vault->image($made,$key);
    $stories->update($cs,['cover'=>$made],$key);
    check((int)$madeImage['width']>0&&$stories->get($cs,$key)['cover_size']===[(int)$madeImage['width'],(int)$madeImage['height']],'the list knows the cover\'s size, to show it whole at one height');

    // A new version of the cover changes the cover alone, not the same picture in a chapter
    $stories->update($cs,['cover'=>null],$key);
    $firstPicture=$stories->get($cs,$key)['cover'];
    check($firstPicture!==null&&$firstPicture!==$made&&!$stories->get($cs,$key)['cover_chosen'],'with no cover chosen, the story shows its first illustration');
    $firstImage=$vault->image($firstPicture,$key);
    check($stories->get($cs,$key)['cover_size']===[(int)$firstImage['width'],(int)$firstImage['height']],'with its size');
    rejects(fn()=>$writer->editCover($cs,['kind'=>'edit','instruction'=>''],$key),'an edit of the cover says what to change',422);
    $edit=$writer->editCover($cs,['kind'=>'edit','instruction'=>'Make it night'],$key);
    $page=$stories->get($cs,$key);
    check($page['cover_chosen']&&$page['cover']===$firstPicture&&$edit['operation']==='edit'&&$edit['parent_id']===$firstPicture&&$page['editing'][$edit['id']]['image']===$firstPicture,'editing the first illustration as the cover chooses it, then makes a new version');
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check($summary['cover_making']==='queued','the list shows the new version being made');
    $jobs->run($edit['id'],$key,$api);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    $page=$stories->get($cs,$key);
    check($summary['cover']!==$firstPicture&&!isset($summary['cover_making'])&&str_contains($page['chapters'][0]['text'],'img:'.$firstPicture),'ready, it is the new cover; the chapter keeps its illustration');
    $edit=$writer->editCover($cs,['kind'=>'upscale'],$key);
    $jobs->run($edit['id'],$key,new OpenAI('test',60,function(){ throw new HttpError(400,'Recusado.'); }));
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check($summary['cover_making']==='failed','a new version of the cover that fails shows in the list');
    $retried=$writer->retryCover($cs,$key)[0];
    $page=$stories->get($cs,$key);
    check(isset($page['editing'][$retried['id']])&&!isset($page['editing'][$edit['id']])&&$page['editing'][$retried['id']]['failed']===false,'it can be tried again');
    $jobs->run($retried['id'],$key,new OpenAI('test',60,function(){ throw new HttpError(400,'Recusado.'); }));
    $stories->all($key,$jobs);$stories->dropCoverJob($cs,$key);
    $summary=array_values(array_filter($stories->all($key,$jobs),fn($s)=>$s['id']===$cs))[0];
    check(!isset($summary['cover_making'])&&$stories->get($cs,$key)['editing']===[],'or set aside');

    // The writer's covers follow the same format and words, three styles to choose from, or the styles, the number or
    // the format the person asks for, or no text at all
    [$ok,$out,$label]=$wi->runTool('illustrate',['prompt'=>'Ana and Beatriz on the ridge at dawn, the valley below','caption'=>'Capa nova','cover'=>true],$ctx);
    $three=array_slice($ctx->jobs,-3);$job=$jobOf($three[0]);
    check($ok&&str_contains($out,'3 covers requested (requests '.implode(', ',$three).'), designed as the front cover of a manga volume (tankōbon) in the directions ')
        &&str_contains($out,'lettered with the title, the volume "Vol. 2", the author\'s name "M. Duarte"')&&$label==='Pediu 3 capas para escolher · “Capa nova”'
        &&str_starts_with($job['prompt'],'The front cover of a manga volume (tankōbon) titled "As Irmãs do Vale".')&&str_contains($job['prompt'],'The cover\'s art: Ana and Beatriz on the ridge at dawn')
        &&!str_contains($job['prompt'],'Duas irmãs atravessam')&&str_contains($job['prompt'],'the volume "Vol. 2"')&&$stories->get($cs,$key)['cover_job']===$three[0]
        &&array_column(array_slice($stories->get($cs,$key)['cover_choices'],0,3),'job')===$three,'the writer\'s covers are designed by the app in the story\'s format, with its words, three to choose from');
    $stories->dropCoverJob($cs,$key);
    [$ok,$out]=$wi->runTool('illustrate',['prompt'=>'The valley','caption'=>'Capa','cover'=>true,'cover_format'=>'light_novel','lettering'=>false,'variants'=>1],$ctx);
    $job=$jobOf(end($ctx->jobs));
    check($ok&&str_contains($out,'Cover requested (request '.end($ctx->jobs).'), designed as the front cover of a Japanese light novel volume in the ')&&str_contains($out,', with no text')
        &&str_contains($job['prompt'],'anime-style illustration')&&str_contains($job['prompt'],'No text, title or lettering anywhere')&&!str_contains($job['prompt'],'Typography is half')
        &&$stories->get($cs,$key)['cover_design']['format']==='manga','or one, in another format for once, without text');
    $stories->dropCoverJob($cs,$key);
    [$ok,$out]=$wi->runTool('illustrate',['prompt'=>'The valley','caption'=>'Capa','cover'=>true,'cover_styles'=>['typographic','abstract']],$ctx);
    $two=array_slice($ctx->jobs,-2);
    check($ok&&str_contains($out,'in the directions typographic, abstract')&&str_starts_with($jobOf($two[0])['variation']['label'],'Typographic. ')&&str_starts_with($jobOf($two[1])['variation']['label'],'Abstract. '),'or in the styles the person asked for');
    [$ok,$out]=$wi->runTool('illustrate',['prompt'=>'x','caption'=>'Capa','cover'=>true,'cover_format'=>'poster'],$ctx);
    check(!$ok,'a cover format the app doesn\'t know is refused');
    [$ok,$out]=$wi->runTool('illustrate',['prompt'=>'x','caption'=>'Capa','cover'=>true,'cover_styles'=>['pop']],$ctx);
    check(!$ok,'and so is a style');
    $stories->dropCoverJob($cs,$key);

    // The format of the cover read from the story: text, a light novel, a comic adaptation; or the person's
    $ln=$stories->create('Renascido em Outro Mundo',$key)['id'];
    $d=$stories->get($ln,$key)['cover_design'];
    check($d['format']==='novel'&&$d['why']==='text'&&$d['volume']==='','a story in text gets a novel\'s cover');
    $stories->writeDocument($ln,'plan',['text'=>'Uma light novel isekai em 3 volumes.'],'agent',$key);
    $d=$stories->get($ln,$key)['cover_design'];
    check($d['format']==='light_novel'&&$d['why']==='light_novel'&&$d['volume']==='','a story whose plan speaks of a light novel gets its cover, with no volume (a story is one book)');
    // A design saved when a light novel, a manga or a comic got "1" on its own loses it; one typed since stays
    $row=$vault->db->query("SELECT meta FROM stories WHERE id='$ln'")->fetchColumn();
    $meta=json_decode(Studio\Crypto::open($row,$key,"story:$ln"),true);$meta['cover_design']=['format'=>'manga','author'=>'','volume'=>'1'];
    $vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([Studio\Crypto::seal(json_encode($meta),$key,"story:$ln"),$ln]);
    check($stories->get($ln,$key)['cover_design']['volume']==='','the volume 1 given on its own before goes away');
    $stories->update($ln,['cover_design'=>['format'=>'manga','volume'=>'1']],$key);
    check($stories->get($ln,$key)['cover_design']['volume']==='1','a volume the person types stays');
    $stories->update($ln,['cover_design'=>['format'=>null,'volume'=>'']],$key);
    $stories->comics()->setSettings($ln,['kind'=>'comic'],'person',$key);
    $d=array_values(array_filter($stories->all($key),fn($s)=>$s['id']===$ln))[0]['cover_design'];
    check($d['format']==='comic'&&$d['why']==='comic','a comic adaptation gives its kind, in the list too');
    $stories->update($ln,['cover_design'=>['format'=>'novel','author'=>'  Ana  Lima ','volume'=>'']],$key);
    $d=$stories->get($ln,$key)['cover_design'];
    check($d['format']==='novel'&&$d['chosen']==='novel'&&$d['auto']==='comic'&&$d['author']==='Ana Lima'&&$d['volume']==='','the person\'s choice wins, with no volume when emptied');
    rejects(fn()=>$stories->update($ln,['cover_design'=>['volume'=>str_repeat('9',13)]],$key),'a volume is short',422);
    $stories->update($ln,['cover_design'=>['format'=>null]],$key);
    check($stories->get($ln,$key)['cover_design']['format']==='comic','back to automatic');
    $stories->delete($ln);

    // The writer hears what the person changed in the sheets and the cover, and what its settings say
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Oi.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Visto.']],'end_turn')]),null);
    $w->run($w->send($cs,'Oi',[],$key)['id'],$key);
    $first=json_encode($requests[0]['body']['messages'],JSON_UNESCAPED_UNICODE);
    check(str_contains($first,'you make the cover while planning a story that has none')&&str_contains($first,'you build the character sheets gradually')&&str_contains($first,'- Beatriz (a caçula)'),'the writer starts with the sheets and its settings for the cover and the sheets');
    check(str_contains($first,'Cover format: a manga volume (tankōbon) (chosen by the person); lettered on it: the title, the volume \\"Vol. 2\\", the author\'s name \\"M. Duarte\\".'),'and with the format of its covers and their words');
    $stories->updateCast($cs,['character'=>'Carlos','note'=>'o pai'],'person',$key);
    $stories->update($cs,['writer'=>Writer::settings($stories->get($cs,$key)['writer'],['illustrate'=>'text'])],$key);
    $w->run($w->send($cs,'E agora?',[],$key)['id'],$key);
    $m=$requests[1]['body']['messages'];$update=json_encode(end($m),JSON_UNESCAPED_UNICODE);
    check(str_contains($update,'The person changed the visual style or the sheets')&&str_contains($update,'- Carlos (o pai): no pictures yet')&&str_contains($update,'a cover only when the person asks; character sheets only when the person asks'),'then hears of a new sheet, and that text only means no cover or sheet unasked');
    $stories->delete($cs);

    // ---------------------------------------------------------------------------------------------------------------
    // The stories over MCP (mcp.php): a client works with the writer's tools, as another author ('mcp')
    $mcpCalls=[];$mcpApi=new OpenAI('test',60,function($path,$body)use(&$mcpCalls,$png){$mcpCalls[]=[$path,$body];return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]];});
    $mcp=new Studio\Mcp($vault,$jobs,$key,'https://example.test/',['api_timeout'=>60],$mcpApi);
    $call=fn(string $name,array $arguments)=>$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$arguments]])['result'];
    $said=fn(array $r)=>implode("\n",array_column(array_filter($r['content'],fn($c)=>$c['type']==='text'),'text'));
    $listed=array_column($mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'])['result']['tools'],null,'name');
    check(isset($listed['list_stories'],$listed['create_story'],$listed['open_story'])&&!array_diff(array_keys(Writer::tools()),array_keys($listed))&&json_encode($listed)!==false,'MCP lists the stories and every tool of the writer');
    check($listed['read_chapter']['inputSchema']['required']===['story_id','opened','chapter']&&$listed['get_outline']['inputSchema']['required']===['story_id','opened']&&!isset($listed['open_story']['inputSchema']['properties']['opened'])&&$listed['read_plan']['annotations']['readOnlyHint']===true
        &&$listed['delete_chapter']['annotations']['destructiveHint']===true&&$listed['illustrate']['annotations']['openWorldHint']===true,'each writer tool takes the story id, with hints for the client');
    check(Studio\Mcp::isLong(['method'=>'tools/call','params'=>['name'=>'draw_comic_page']])&&!Studio\Mcp::isLong(['method'=>'tools/call','params'=>['name'=>'read_chapter']]),'story tools that may wait for a picture are streamed');
    $ms=json_decode($call('create_story',['title'=>'Pelo MCP','synopsis'=>'Uma história escrita de fora.'])['content'][0]['text'],true)['story_id'];
    check($stories->get($ms,$key)['synopsis']==='Uma história escrita de fora.','create_story makes a story in the app');
    $open=$said($call('open_story',['story_id'=>$ms]));
    check(str_contains($open,'You are now this story\'s writer')&&str_contains($open,Writer::SYSTEM)&&str_contains($open,'<story_state>')&&str_contains($open,'Title: Pelo MCP')&&str_contains($open,'story_id: '.$ms),'open_story gives the writer\'s instructions and the story as it is');
    $op=preg_match('/^opened: (\S+)/m',$open,$mm)?$mm[1]:'';
    check($op!==''&&str_contains($said($call('open_story',['story_id'=>$ms,'include_guide'=>false])),'You are now'),'the instructions always come with the opened value the story tools take');
    // The story tools refuse until open_story was called for that story: the writer's instructions come first.
    $r=$call('write_chapter',['story_id'=>$ms,'title'=>'Antes','text'=>'Sem abrir.']);
    check(!empty($r['isError'])&&str_contains($said($r),'Call open_story for this story first')&&$stories->chapters($ms,$key)===[]&&!empty($call('get_outline',['story_id'=>$ms])['isError']),'without open_story the story tools refuse, reading ones too');
    $other=json_decode($call('create_story',['title'=>'Outra'])['content'][0]['text'],true)['story_id'];
    $otherOp=preg_match('/^opened: (\S+)/m',$said($call('open_story',['story_id'=>$other])),$mm)?$mm[1]:'';
    $old=(new ReflectionMethod($mcp,'opened'))->invoke($mcp,$ms,time()-86401);
    $r=$call('get_outline',['story_id'=>$ms,'opened'=>$otherOp]);
    check(!empty($r['isError'])&&str_contains($said($r),'not one open_story gave for this story')&&!empty($call('get_outline',['story_id'=>$ms,'opened'=>substr($op,0,-1).(substr($op,-1)==='a'?'b':'a')])['isError'])
        &&empty($call('get_outline',['story_id'=>$other,'opened'=>$otherOp])['isError']),'the opened value is for one story and can\'t be made up');
    $r=$call('get_outline',['story_id'=>$ms,'opened'=>$old]);
    check(!empty($r['isError'])&&str_contains($said($r),'opened more than a day ago'),'after a day the story has to be opened again');
    $stories->delete($other);
    $r=$call('write_chapter',['story_id'=>$ms,'opened'=>$op,'title'=>'Começo','text'=>"Era uma vez.\n\nO barco partiu.",'summary'=>'O começo.']);
    check(empty($r['isError'])&&str_contains($said($r),'Chapter 1 "Começo" saved')&&$stories->chapters($ms,$key)[0]['by']==='mcp','write_chapter writes into the app, marked as done via MCP');
    $call('update_plan',['story_id'=>$ms,'opened'=>$op,'text'=>'SEGREDO do MCP.']);$call('update_notes',['story_id'=>$ms,'opened'=>$op,'append'=>'Ana: 20 anos.']);
    check($stories->readDocument($ms,'plan',$key)['by']==='mcp'&&$stories->readDocument($ms,'notes',$key)['text']==='Ana: 20 anos.'&&str_contains($said($call('read_plan',['story_id'=>$ms,'opened'=>$op])),'SEGREDO do MCP.'),'the plan and the bible are kept in the app');
    check(!empty($call('read_chapter',['story_id'=>$ms,'opened'=>$op,'chapter'=>5])['isError'])&&!empty($call('read_chapter',['story_id'=>str_repeat('0',32),'chapter'=>1])['isError'])&&!empty($call('read_chapter',['story_id'=>$ms,'opened'=>$op])['isError']),'a missing chapter, story or argument is a tool error');
    $r=$call('illustrate',['story_id'=>$ms,'opened'=>$op,'chapter'=>1,'prompt'=>'A boat leaving at dawn','caption'=>'A partida','after_text'=>'O barco partiu.']);
    $page=$stories->get($ms,$key,$jobs);
    check(empty($r['isError'])&&preg_match('/O barco partiu\.\n\n!\[A partida\]\(img:([a-f0-9]{32})\)/',$page['chapters'][0]['text'],$mm)===1&&count($mcpCalls)===1,'illustrate makes the picture, which takes its place in the chapter');
    $v=$call('view_image',['story_id'=>$ms,'opened'=>$op,'image_id'=>$mm[1]]);
    check($v['content'][1]['type']==='image'&&$v['content'][1]['mimeType']==='image/jpeg'&&!isset($v['content'][1]['source']),'pictures come back as MCP images');
    $queued=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'A sheet still in line','options'=>['previews'=>0]],$key)[0]['id'];
    $c2=new Studio\ToolContext($ms,'',Writer::settings(null),$key);$c2->by='mcp';
    [$ok,$out]=$writer->forRequest(time(),false)->runTool('illustrate',['chapter'=>1,'prompt'=>'X','caption'=>'Y','references'=>[$queued]],$c2);
    check(!$ok&&str_contains($out,'still being made')&&$jobs->freshJob($queued,$key)['status']==='queued'&&!$c2->jobs,'over MCP a reference still being made is waited for only as long as the request may last, and not made in it');
    $vault->db->prepare("UPDATE jobs SET status='cancelled' WHERE id=?")->execute([$queued]);
    // The app's writer hears what was done over MCP; open_story shows what the person last said to it.
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Oi.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Visto.']],'end_turn')]),null);
    $w->run($w->send($ms,'Oi',[],$key)['id'],$key);
    $call('append_to_chapter',['story_id'=>$ms,'opened'=>$op,'chapter'=>1,'text'=>'E voltou.']);
    $call('update_plan',['story_id'=>$ms,'opened'=>$op,'append'=>'Mais.']);
    $w->run($w->send($ms,'E agora?',[],$key)['id'],$key);
    $m=$requests[1]['body']['messages'];$update=json_encode(end($m),JSON_UNESCAPED_UNICODE);
    check(str_contains($update,'Chapter 1 \"Começo\" was edited by an assistant the person connected to the app')&&str_contains($update,'An assistant the person connected to the app edited your plan'),'the app\'s writer is told what was changed over MCP');
    $open=$said($call('open_story',['story_id'=>$ms]));
    check(str_contains($open,'<app_writer_messages>')&&str_contains($open,'Person: E agora?')&&str_contains($open,'App writer: Visto.'),'open_story shows the last messages with the app\'s writer');
    $all=json_decode($call('list_stories',[])['content'][0]['text'],true)['stories'];
    check(in_array('Pelo MCP',array_column($all,'title'),true)&&$all[0]['app_writer_working']===false,'list_stories lists the stories');
    check(empty($call('find_repetitions',['story_id'=>$ms,'opened'=>$op])['isError'])&&str_contains($said($call('review_book',['story_id'=>$ms,'opened'=>$op])),'<chapter number="1">'),'the repetitions and the whole book are there over MCP too');
    $stories->delete($ms);

    // ---------------------------------------------------------------------------------------------------------------
    // Literary quality: the beat log, the clichés, the repeated phrases the app counts, and the review of the whole book
    check(str_contains(Writer::SYSTEM,'## Keeping a long book fresh')&&str_contains(Writer::SYSTEM,'beat log')&&str_contains(Writer::SYSTEM,'## Clichés')
        &&str_contains(Writer::SYSTEM,'## Reviewing the whole book')&&str_contains(Writer::SYSTEM,'call find_repetitions with the chapter')&&str_contains(Writer::SYSTEM,'Sex and desire are held to the same craft'),
        'the writer is told to keep a beat log, avoid clichés, count repeated phrases, review the whole book and write intimate scenes with the same craft');
    $found=Studio\Repetitions::find(["She let out a breath she didn't know she was holding. The ghost of a smile touched his lips. She looked up at him.",
        "The ghost of a smile touched her lips. She let out a breath she didn't know she was holding. She looked up at him.","The ghost of a smile. For a long moment, neither of them spoke. For a long moment, neither of them spoke."]);
    $by=array_column($found,null,'phrase');
    check(($by['the ghost of a smile']['count']??0)===3&&($by["she let out a breath she didn't know she was holding"]['chapters']??[])===[1=>1,2=>1]
        &&($by['for a long moment neither of them spoke']['chapters']??[])===[3=>2]&&!isset($by['ghost of a smile'])&&!isset($by['she looked up at him']),
        'repeated phrases are counted at their longest, by chapter, leaving out runs of grammar');
    check(Studio\Repetitions::find(['Um dia ele voltou para casa.','Ela nunca mais voltou.'])===[]&&array_column(Studio\Repetitions::find(['A b c d.','x'],1),'phrase')===[],'no repetition, nothing listed');
    $rs=$stories->create('Revisão',$key)['id'];
    foreach(['One'=>"The ghost of a smile touched her lips.\n\nShe let out a breath she didn't know she was holding.",'Two'=>'He came back at dawn. The ghost of a smile touched her lips.','Three'=>'Rain all night. The ghost of a smile, again.'] as $t=>$x)$stories->addChapter($rs,$t,$x,'',null,'person',$key);
    $ctx=new Studio\ToolContext($rs,str_repeat('f',32),Writer::settings(null),$key);
    [$ok,$out,$label]=$writer->runTool('find_repetitions',['chapter'=>3],$ctx);
    check($ok&&str_contains($out,'"the ghost of a smile" ×3: chapters 1, 2, 3')&&!str_contains($out,'touched her lips" ×2')&&$label==='Procurou frases repetidas no capítulo 3','find_repetitions lists what a chapter shares with the book');
    [$ok,$out]=$writer->runTool('find_repetitions',['chapter'=>9],$ctx);
    check(!$ok&&str_contains($out,'There is no chapter 9'),'a chapter that doesn\'t exist is refused');
    [$ok,$out,$label]=$writer->runTool('review_book',[],$ctx);
    check($ok&&str_contains($out,'The whole book: 3 chapters')&&str_contains($out,"<chapter number=\"2\">\nTitle: Two (13 words)\n\nHe came back at dawn.")&&str_contains($out,'<repetitions>')
        &&str_contains($out,'"the ghost of a smile touched her lips" ×2')&&$label==='Leu o livro inteiro para a revisão','review_book gives every chapter and the repeated phrases');
    [$ok,$out]=$writer->runTool('review_book',['from'=>2,'to'=>2],$ctx);
    check($ok&&str_contains($out,'Chapters 2 to 2 of the 3')&&!str_contains($out,'Title: One')&&!str_contains($out,'<repetitions>')&&str_contains($out,'come with the part that reaches its last chapter'),
        'a long book can be read in parts, the repeated phrases only once, with the last part');
    $est=$writer->reviewEstimate($rs,$key);
    check($est['chapters']===3&&$est['words']>20&&$est['cost']>0&&$est['writer']==='Claude Opus 5.5','the page can tell what a review costs before it starts');
    // Every conversation opens with the whole book; review_book doesn't send it again; the writer is told the size.
    $requests=[];
    $big=['input_tokens'=>300000,'output_tokens'=>200,'cache_read_input_tokens'=>0,'cache_creation_input_tokens'=>0];
    $w=$writer->withTransports(player($requests,[
        claudeEvents([['type'=>'text','text'=>'Oi.']],'end_turn'),
        claudeEvents([['type'=>'tool_use','id'=>'toolu_rv','name'=>'review_book','input'=>new stdClass()]],'tool_use'),
        claudeEvents([['type'=>'text','text'=>'<no_spoilers>Revisei o livro.</no_spoilers> Achados.']],'end_turn',$big),
        claudeEvents([['type'=>'text','text'=>'Sigo.']],'end_turn',$big),
        claudeEvents([['type'=>'tool_use','id'=>'toolu_rv2','name'=>'review_book','input'=>new stdClass()]],'tool_use',$big),
        claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn',['input_tokens'=>520000,'output_tokens'=>200,'cache_read_input_tokens'=>0,'cache_creation_input_tokens'=>0]),
        claudeEvents([['type'=>'text','text'=>'Nova.']],'end_turn')]),null);
    $w->run($w->send($rs,'Oi',[],$key)['id'],$key);
    $open=$requests[0]['body']['messages'][0]['content'][0]['text'];
    check(str_contains($open,"<book>\n<chapter number=\"1\">\nTitle: One (")&&str_contains($open,"<chapter number=\"3\">\nTitle: Three (")&&str_contains($open,'The whole book, as the person reads it now')
        &&str_contains($open,'You don\'t need read_chapter for the chapters in <book>')&&str_contains($open,'starts a new conversation when it passes 500,000 tokens, opening it again with the whole book')
        &&str_contains($open,'never stop or shorten your work because the conversation seems long'),'a conversation opens with the full text of every chapter, and the writer is told the app minds its length');
    $turn=$w->run($w->review($rs,[],$key)['id'],$key);
    $m=$requests[1]['body']['messages'];
    check($turn['status']==='completed'&&str_contains($m[0]['content'][0]['text'],'<story_state>')&&str_contains($m[0]['content'][0]['text'],'(the person asked for a review of the whole book)')
        &&str_contains($m[0]['content'][0]['text'],'<chapter number="2">')&&str_contains(json_encode(end($m),JSON_UNESCAPED_UNICODE),'start with review_book'),'Revisar o livro starts a new conversation, with the whole book, and asks for the review');
    $results=json_encode(end($requests[2]['body']['messages'])['content'],JSON_UNESCAPED_UNICODE);
    check(str_contains($results,'Their full text is in <book> at the start of this conversation, unchanged since')&&!str_contains($results,'<chapter number=')&&str_contains($results,'<repetitions>'),
        'review_book doesn\'t send the book again, only the repeated phrases');
    $chat=$w->chat($rs,$key);$mine=array_values(array_filter($chat,fn($x)=>$x['role']==='user'));
    check(end($mine)['text']===\Studio\t(Writer::REVIEW_SHOWN)&&in_array('Conversa nova para a revisão: o escritor recebe o livro inteiro e segue escrevendo com ele na memória.',array_column($chat,'text'),true),'the chat shows the review as one short line, after a notice');
    $w->run($w->send($rs,'Continue.',[],$key)['id'],$key);
    $last=json_encode(end($requests[3]['body']['messages']),JSON_UNESCAPED_UNICODE);
    check(!str_contains($last,'<story_state>')&&str_contains($last,'Conversation size: the last request of this conversation was about 300,000 tokens, of the 1,000,000 the model can take.')
        &&strpos($last,'Conversation size')<strpos($last,'Continue.'),'past 250 thousand tokens the conversation goes on, and the writer is told how big it is');
    // A chapter the person changed after the conversation opened is sent again; the others aren't.
    $two=$stories->chapters($rs,$key)[1];
    $stories->updateChapter($rs,$two['id'],['text'=>'He came back at noon, soaked.'],'person',$key);
    $w->run($w->send($rs,'Revise de novo.',[],$key)['id'],$key);
    $results=json_encode(end($requests[5]['body']['messages'])['content'],JSON_UNESCAPED_UNICODE);
    check(str_contains($results,'The text of chapters 1, 3 is in <book> at the start of this conversation')&&str_contains($results,'He came back at noon, soaked.')&&!str_contains($results,'<chapter number=\"1\">'),
        'a chapter changed since the conversation opened is read again, alone');
    $w->run($w->send($rs,'De novo.',[],$key)['id'],$key);
    $open=$requests[6]['body']['messages'][0]['content'][0]['text'];
    check(str_contains($open,'<story_state>')&&str_contains($open,'(the previous one grew long)')&&str_contains($open,'He came back at noon, soaked.')
        &&in_array('Conversa nova: a anterior ficou longa. O escritor recebe de novo o livro inteiro, a bíblia e o planejamento e segue de onde parou.',array_column($w->chat($rs,$key),'text'),true),
        'past 500 thousand tokens a new conversation starts, again with the whole book as it is now');
    check(Writer::newConversationAt('openai')===250000&&Writer::newConversationAt('gemini')===250000,'GPT and Gemini, whose windows the app doesn\'t keep, start over at 250 thousand as before');
    // Several chapters in one turn near Claude's window: told once to finish the chapter it is on, then stopped.
    $requests=[];
    $near=fn(int $t)=>['input_tokens'=>$t,'output_tokens'=>200,'cache_read_input_tokens'=>0,'cache_creation_input_tokens'=>0];
    $w=$writer->withTransports(player($requests,[
        claudeEvents([['type'=>'tool_use','id'=>'toolu_n1','name'=>'get_outline','input'=>new stdClass()]],'tool_use',$near(760000)),
        claudeEvents([['type'=>'tool_use','id'=>'toolu_n2','name'=>'get_outline','input'=>new stdClass()]],'tool_use',$near(800000)),
        claudeEvents([['type'=>'tool_use','id'=>'toolu_n3','name'=>'get_outline','input'=>new stdClass()]],'tool_use',$near(860000)),
        claudeEvents([['type'=>'text','text'=>'Nunca.']],'end_turn')]),null);
    $turn=$w->run($w->send($rs,'Escreva os próximos três.',[],$key)['id'],$key);
    $told=array_filter(array_map(fn($r)=>substr_count(json_encode($r['body']['messages'],JSON_UNESCAPED_UNICODE),'Finish the chapter you are on'),$requests));
    check(count($requests)===3&&$turn['status']==='completed'&&str_contains((string)$turn['note'],'perto do limite do modelo')&&array_values($told)===[1,1]
        &&str_contains(json_encode(end($requests[1]['body']['messages']),JSON_UNESCAPED_UNICODE),'This conversation is now at about 760,000 tokens'),
        'near the window the writer is told once to finish the chapter it is on, and past 850 thousand the turn stops before another request');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Nova conversa.']],'end_turn')]),null);
    $w->run($w->send($rs,'Siga.',[],$key)['id'],$key);
    check(str_contains($requests[0]['body']['messages'][0]['content'][0]['text'],'(the previous one grew long)'),'and the next message goes on in a new conversation');
    // A book too long to open whole: the latest chapters in full, the earlier ones only summarized.
    $lb=$stories->create('Longo',$key)['id'];
    foreach(range(1,4) as $i)$stories->addChapter($lb,"Parte $i",trim(str_repeat("ab$i ",30000)),"Resumo $i.",null,'person',$key);
    $requests=[];
    $w=$writer->withTransports(null,player($requests,[$gptAnswer([['type'=>'message','id'=>'msg_lb','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Li.','annotations'=>[]]]]],['input_tokens'=>10,'output_tokens'=>1])]));
    $w->run($w->send($lb,'Oi.',['provider'=>'openai'],$key)['id'],$key);
    $open=json_encode($requests[0]['body']['input'],JSON_UNESCAPED_UNICODE);
    check(str_contains($open,'chapters 1 to 2 are only in the summaries above (read_chapter brings any of them), and chapters 3 to 4 follow in full')
        &&str_contains($open,'<chapter number=\"3\">')&&!str_contains($open,'<chapter number=\"2\">')&&str_contains($open,'Resumo 1.'),'a book too long to open whole opens with its latest chapters');
    $stories->delete($lb);
    rejects(fn()=>$w->review($stories->create('Vazia',$key)['id'],[],$key),'a story without chapters has nothing to review',422);
    check(Writer::settings(null)['chapter_words']===5000,'new stories ask for chapters of about 5,000 words');
    $stories->delete($rs);

    // ---------------------------------------------------------------------------------------------------------------
    // Each story its own, the rules of the second round, and what the person wrote about themselves as a reader
    check(str_contains(Writer::SYSTEM,'## Each story its own')&&str_contains(Writer::SYSTEM,'"## Architecture"')&&str_contains(Writer::SYSTEM,'<other_stories>')
        &&str_contains(Writer::SYSTEM,'Every strong feeling has roots')&&str_contains(Writer::SYSTEM,'friction and cost go on')&&str_contains(Writer::SYSTEM,'remark on someone\'s growth')
        &&str_contains(Writer::SYSTEM,'never on a schedule or a quota')&&str_contains(Writer::SYSTEM,'each return finds a new angle')&&str_contains(Writer::SYSTEM,'beat log in the plan from the first chapter')
        &&str_contains(Writer::SYSTEM,'When the state has <reader>')&&!preg_match('/\b(couples?|central relationship)\b/i',Writer::SYSTEM),
        'the writer builds each story its own way, roots feelings, keeps friction after a resolution and reads what the person wrote about themselves, without pulling stories toward romance');
    check($vault->readerAbout($key)===Vault::ABOUT_START&&str_contains(Vault::ABOUT_START,'formula')&&str_contains(Vault::ABOUT_START,'no spoilers')&&str_contains(Vault::ABOUT_START,'which chapters changed'),
        'an administrator starts with a few example reader preferences');
    $otherKey=random_bytes(32);$someone=$users->create('Leitora','another-password-long-enough',$otherKey);
    $vault->useOwner($someone['id']);$empty=$vault->readerAbout($otherKey);$vault->useOwner($owner['id']);
    check($empty==='','anyone else starts with nothing');
    check($vault->setReaderAbout("  Gosto de livros longos.\r\nNada de epílogos.\x07  ",$key)==="Gosto de livros longos.\nNada de epílogos."&&$vault->readerAbout($key)==="Gosto de livros longos.\nNada de epílogos."
        &&$vault->storyPrefs($key)===[]&&!str_contains((string)$vault->db->query("SELECT prefs FROM users WHERE id='".$owner['id']."'")->fetchColumn(),'epílogos'),'it is saved sealed with the other preferences, cleaned');
    rejects(fn()=>$vault->setReaderAbout(str_repeat('a',Vault::ABOUT_MAX+1),$key),'a text too long is refused',422);
    rejects(fn()=>$vault->setReaderAbout(['x'],$key),'only text is taken',422);
    $vault->setReaderAbout(Vault::ABOUT_START,$key);
    // A new story: the writer sees the reader and how the other stories are built; a story with a plan doesn't.
    $a=$stories->create('Sal e Cinza',$key)['id'];$stories->update($a,['series'=>'Marés'],$key);
    $stories->writeDocument($a,'plan',['text'=>"## Architecture\nFirst person, past tense, one narrator.\nA heist told backwards; the motif is a broken compass.\n\n## Premise\nSEGREDO-A: the brother is the thief."],'agent',$key);
    $b=$stories->create('Velho plano',$key)['id'];$stories->writeDocument($b,'plan',['text'=>"Premise: a lighthouse keeper.\nSEGREDO-B in the first lines."],'agent',$key);
    $stories->copy($a,'Sal e Cinza (cópia)',$key);
    $stories->create('Sem plano',$key);
    $n=$stories->create('Nova',$key)['id'];
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Planejando.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn'),
        claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $w->run($w->send($n,'Vamos planejar uma história nova.',[],$key)['id'],$key);
    $state=$requests[0]['body']['messages'][0]['content'][0]['text'];
    check(str_contains($state,"<reader>\n".Vault::ABOUT_START."\n</reader>"),'the writer reads what the person wrote about themselves at the start of every conversation');
    preg_match('/<other_stories>\n(.*?)<\/other_stories>/s',$state,$mm);$others=$mm[1]??'';
    check(str_contains($others,'- "Sal e Cinza" (series "Marés"):'."\nFirst person, past tense, one narrator.\nA heist told backwards; the motif is a broken compass.\n")
        &&!str_contains($others,'SEGREDO-A')&&substr_count($others,'A heist told backwards')===1&&!str_contains($others,'"Nova"')&&!str_contains($others,'Sem plano')
        &&str_contains($others,'- "Velho plano", whose plan has no Architecture section; the start of its plan:'."\nPremise: a lighthouse keeper."),
        'planning a new story, it sees how the other stories are built: their Architecture, else the start of their plan, once per copy, never the rest of the plan');
    // The person changes what they wrote: the next message says so, once; a story with a plan doesn't list the others.
    $vault->setReaderAbout('Prefiro finais abertos.',$key);
    $stories->writeDocument($n,'plan',['text'=>"## Architecture\nSecond person."],'agent',$key);
    $w->run($w->send($n,'E agora?',[],$key)['id'],$key);
    $update=json_encode(end($requests[1]['body']['messages']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    check(str_contains($update,'The person changed what they wrote about themselves as a reader. It now reads:\n<reader>\nPrefiro finais abertos.\n</reader>'),'a change reaches the writer at the next message');
    $w->run($w->send($n,'Siga.',[],$key)['id'],$key);
    check(!str_contains(json_encode(end($requests[2]['body']['messages']),JSON_UNESCAPED_UNICODE),'<reader>'),'and only once');
    $vault->setReaderAbout('',$key);
    $w->run($w->send($n,'Mais.',[],$key)['id'],$key);
    check(str_contains(json_encode(end($requests[3]['body']['messages']),JSON_UNESCAPED_UNICODE),'The person removed what they had written about themselves as a reader.'),'cleared, the writer is told it no longer holds');
    $vault->setReaderAbout('Gosto de sutileza.',$key);
    $open=$said($call('open_story',['story_id'=>$n]));
    check(str_contains($open,"<reader>\nGosto de sutileza.\n</reader>")&&!str_contains($open,"<other_stories>\n"),'over MCP, open_story gives it too');
    $fresh=$stories->create('Outra nova',$key)['id'];
    $open=$said($call('open_story',['story_id'=>$fresh]));
    check(str_contains($open,"<other_stories>\n")&&str_contains($open,'Second person.'),'and the other stories\' architecture when the story has no plan yet');
    check(Stories::architecture("**Architecture:**\nThree acts.\nTwo voices.")===["Three acts.\nTwo voices.",true]&&Stories::architecture("# Plano\n## Arquitetura\nTerceira pessoa.\n### POV\nUm só.\n## Premissa\nX")===["Terceira pessoa.\n### POV\nUm só.",true]
        &&Stories::architecture("The architecture of the city matters.\nMore.")[1]===false&&mb_strlen(Stories::architecture(str_repeat("linha longa de plano\n",200))[0])<=1502,
        'the Architecture section is found under its heading, to the next heading of its level, and kept short');
    foreach([$a,$b,$n,$fresh] as $x)$stories->delete($x);
    foreach($stories->all($key) as $x)if(in_array($x['title'],['Sal e Cinza (cópia)','Sem plano'],true))$stories->delete($x['id']);
    $vault->setReaderAbout(Vault::ABOUT_START,$key);

    // ---------------------------------------------------------------------------------------------------------------
    // The writer's own notes on the person as a reader, beside their words, which it can't change
    check(str_contains(Writer::SYSTEM,'update_reader_notes')&&str_contains(Writer::SYSTEM,'It is theirs and you never change it')&&isset(Writer::tools()['update_reader_notes'],Writer::tools(true)['update_reader_notes'])
        &&!isset(Writer::tools()['update_reader_notes'][1]['properties']['comic']),'the writer keeps notes on the person as a reader, in books and visual novels, and leaves their own words alone');
    $p=$stories->create('Notas',$key)['id'];$q=$stories->create('Outra com notas',$key)['id'];
    $requests=[];
    $w=$writer->withTransports(player($requests,[
        claudeEvents([['type'=>'tool_use','id'=>'toolu_rn','name'=>'update_reader_notes','input'=>['append'=>'- Gosta de diálogos secos.']]],'tool_use'),
        claudeEvents([['type'=>'text','text'=>'<no_spoilers>Anotei que você gosta de diálogos secos.</no_spoilers>']],'end_turn'),
        claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn'),claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn'),
        claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $turn=$w->run($w->send($p,'Adorei os diálogos curtos.',[],$key)['id'],$key);
    $notes=$vault->readerNotes($key);
    check($turn['status']==='completed'&&$notes===['text'=>'- Gosta de diálogos secos.','by'=>'agent','story'=>$p]&&$vault->readerAbout($key)===Vault::ABOUT_START
        &&str_contains(json_encode($w->chat($p,$key),JSON_UNESCAPED_UNICODE),'Atualizou as notas sobre você como leitor'),
        'the writer adds to its notes, and the person\'s own words stay as they were');
    $w->run($w->send($p,'Continue.',[],$key)['id'],$key);
    check(!str_contains(json_encode(end($requests[2]['body']['messages']),JSON_UNESCAPED_UNICODE),'reader_notes'),'the same conversation isn\'t told of its own change');
    $w->run($w->send($q,'Vamos começar.',[],$key)['id'],$key);
    check(str_contains($requests[3]['body']['messages'][0]['content'][0]['text'],"<reader_notes>\n- Gosta de diálogos secos.\n</reader_notes>"),'another story\'s writer reads the notes at the start of its conversation');
    $vault->setReaderAbout(Vault::ABOUT_START,$key,"- Gosta de diálogos secos.\n- Odeia epílogos.");
    $w->run($w->send($p,'E agora?',[],$key)['id'],$key);
    check(str_contains(json_encode(end($requests[4]['body']['messages']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'The person edited your notes on them as a reader. They now read:\n<reader_notes>\n- Gosta de diálogos secos.\n- Odeia epílogos.\n</reader_notes>')
        &&$vault->readerNotes($key)['by']==='person','the person can edit the notes too, and the writer is told');
    $ctx=new Studio\ToolContext($q,str_repeat('e',32),Writer::settings(null),$key);
    [$ok,$out,$label]=$writer->runTool('update_reader_notes',['edits'=>[['old_text'=>'Odeia epílogos','new_text'=>'Prefere finais abertos']]],$ctx);
    check($ok&&str_contains($out,'notes on the person as a reader saved')&&$label==='Atualizou as notas sobre você como leitor'&&$vault->readerNotes($key)['text']==="- Gosta de diálogos secos.\n- Prefere finais abertos.",'it adjusts a line with an exact replacement');
    [$ok,$out]=$writer->runTool('update_reader_notes',['append'=>str_repeat('x',Vault::ABOUT_MAX)],$ctx);
    [$ok2,$out2]=$writer->runTool('update_reader_notes',[],$ctx);
    check(!$ok&&!$ok2&&str_contains($out2,'Give text, append or edits')&&$vault->readerNotes($key)['text']==="- Gosta de diálogos secos.\n- Prefere finais abertos.",'notes too long or an empty change are refused, and nothing changes');
    $w->run($w->send($p,'Mais.',[],$key)['id'],$key);
    check(str_contains(json_encode(end($requests[5]['body']['messages']),JSON_UNESCAPED_UNICODE),'You changed your notes on the person as a reader while working on another of their stories.'),'a change made in another story reaches this one');
    $op=preg_match('/^opened: (\S+)/m',$said($call('open_story',['story_id'=>$q])),$mm)?$mm[1]:'';
    $r=$call('update_reader_notes',['story_id'=>$q,'opened'=>$op,'text'=>'- Lê à noite.']);
    check(empty($r['isError'])&&$vault->readerNotes($key)['by']==='mcp'&&str_contains($said($call('open_story',['story_id'=>$q])),"<reader_notes>\n- Lê à noite.\n</reader_notes>"),'over MCP the notes can be read and changed too');
    $vault->setReaderAbout(Vault::ABOUT_START,$key,'');
    check($vault->readerNotes($key)['text']===''&&!str_contains($said($call('open_story',['story_id'=>$q])),"<reader_notes>\n"),'emptied, nothing goes to the writer');
    $stories->delete($p);$stories->delete($q);

    echo "\n$count checks passed. No real API calls.\n";
}finally{
    unset($vault,$jobs,$stories,$writer,$w);
    if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);}
}
