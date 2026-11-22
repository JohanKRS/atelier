<?php
declare(strict_types=1);
// Runs a queued turn of the story writer with Claude and GPT simulated. CLI only; used by the browser test of the
// stories. The first Claude turn plans the story, writes chapter 1 and asks for an illustration in it; later Claude
// turns answer in text, or, for a message containing FOTO, ask for a photographic version of the first illustration. GPT answers in text. A message containing DEVAGAR keeps the turn running for 3 seconds before its first answer and 3 more after its first step, so the page shows the writer at work, before and after a step.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Vault,Users,Jobs,Stories,Writer};
$dir=getenv('TEST_DATA');$password=getenv('TEST_PASSWORD');
if(!$dir||!$password||!isset($argv[1]))throw new RuntimeException('Test configuration missing');
$vault=new Vault($dir);$users=new Users($vault);
$q=$vault->db->prepare('SELECT user_id,story_id FROM story_turns WHERE id=?');$q->execute([$argv[1]]);$row=$q->fetch();$q->closeCursor();unset($q);
if(!$row)throw new RuntimeException('Unknown turn');
$user=$users->find((string)$row['user_id']);$key=$users->unwrap($user,$password);$vault->useOwner($user['id']);
$jobs=new Jobs($vault,['api_timeout'=>60]);$stories=new Stories($vault);
$written=$stories->chapters((string)$row['story_id'],$key);$chapters=count($written);
// The first illustration, for a message asking for a photographic version of it (FOTO).
$photo=null;
foreach($written as $c) if(!$photo&&preg_match('/\(img:([a-f0-9]{32})\)/',$c['text'],$m)) $photo=$m[1];

/** The events of a Claude answer, as the API streams them. */
$claude=function(array $blocks,string $stop): array {
    $events=[['message_start',['type'=>'message_start','message'=>['model'=>'claude-opus-5-5','content'=>[],'usage'=>['input_tokens'=>1200,'output_tokens'=>0,'cache_read_input_tokens'=>8000,'cache_creation_input_tokens'=>600]]]]];
    foreach($blocks as $i=>$b){
        if($b['type']==='tool_use'){
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'tool_use','id'=>$b['id'],'name'=>$b['name'],'input'=>[]]]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'input_json_delta','partial_json'=>json_encode($b['input'],JSON_UNESCAPED_UNICODE)]]];
        }elseif($b['type']==='thinking'){
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'thinking','thinking'=>'','signature'=>'']]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'thinking_delta','thinking'=>$b['thinking']]]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'signature_delta','signature'=>'sig']]];
        }else{
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'text','text'=>'']]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'text_delta','text'=>$b['text']]]];
        }
        $events[]=['content_block_stop',['type'=>'content_block_stop','index'=>$i]];
    }
    $events[]=['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>$stop],'usage'=>['output_tokens'=>900]]];
    return $events;
};
$chapter="A névoa cobria a ilha desde a madrugada.\nMarina desceu do barco sem olhar para trás.\n\n— Quem está aí? — perguntou, *baixinho*.\n\nNinguém respondeu. Só o farol, **aceso** em pleno dia.\n\n* * *\n\nNa casa do faroleiro, a mesa estava posta para dois.";
$answers=$chapters===0 ? [
    $claude([['type'=>'thinking','thinking'=>'Vou planejar o arco inteiro antes do primeiro capítulo.'],
        ['type'=>'tool_use','id'=>'toolu_plan','name'=>'update_plan','input'=>['text'=>"PLANO SECRETO\n1. A chegada\n2. O mapa\n3. O pai (morre no fim)"]],
        ['type'=>'tool_use','id'=>'toolu_notes','name'=>'update_notes','input'=>['text'=>"Marina: 30 anos, volta à ilha.\nO farol: aceso de dia."]]],'tool_use'),
    $claude([['type'=>'tool_use','id'=>'toolu_write','name'=>'write_chapter','input'=>['title'=>'A chegada','text'=>$chapter,'summary'=>'Marina chega à ilha e encontra a casa do faroleiro pronta para dois.']],
        ['type'=>'tool_use','id'=>'toolu_image','name'=>'illustrate','input'=>['chapter'=>1,'after_text'=>'posta para dois','caption'=>'A mesa posta para dois','prompt'=>'A lighthouse keeper\'s kitchen at dusk, a table set for two, watercolor']]],'tool_use'),
    $claude([['type'=>'text','text'=>"<no_spoilers>Planejei a história inteira (o planejamento fica escondido) e escrevi o capítulo 1, *A chegada*.\n\nQuer que eu siga para o capítulo 2?</no_spoilers>\n\nNo capítulo, Marina encontra a mesa posta para dois: alguém sabia que ela vinha."]],'end_turn'),
] : [$claude([['type'=>'text','text'=>'Combinado, sigo nesse tom.']],'end_turn')];
$photoAnswers=$photo ? [
    $claude([['type'=>'tool_use','id'=>'toolu_photo','name'=>'edit_illustration','input'=>['image_id'=>$photo,'kind'=>'photoreal','caption'=>'A mesa, em foto']]],'tool_use'),
    $claude([['type'=>'text','text'=>'Pedi a versão em foto da ilustração.']],'end_turn'),
] : null;
$n=0;$slow=false;
$claudeTransport=function(string $url,array $headers,array $body,Closure $onEvent,Closure $tick) use (&$n,&$slow,&$answers,$photoAnswers): array {
    $last=json_encode(end($body['messages']),JSON_UNESCAPED_UNICODE);
    if($n===0&&$photoAnswers&&str_contains($last,'FOTO'))$answers=$photoAnswers;
    if($n===0&&str_contains($last,'DEVAGAR'))$slow=true;
    if($slow&&$n<2){ for($i=0;$i<12;$i++){ if($tick())break; usleep(250000);} }
    foreach($answers[min($n++,count($answers)-1)] as [$e,$d])$onEvent($e,$d);
    return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
};
$gptTransport=function(string $url,array $headers,array $body,Closure $onEvent): array {
    $item=['type'=>'message','id'=>'msg_g','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'Aqui é o GPT, seguindo a mesma conversa.','annotations'=>[]]]];
    $onEvent('response.output_text.delta',['type'=>'response.output_text.delta','delta'=>$item['content'][0]['text']]);
    $onEvent('response.output_item.done',['type'=>'response.output_item.done','output_index'=>0,'item'=>$item]);
    $onEvent('response.completed',['type'=>'response.completed','response'=>['status'=>'completed','model'=>$body['model'],'usage'=>['input_tokens'=>3000,'input_tokens_details'=>['cached_tokens'=>0],'output_tokens'=>40],'output'=>[$item]]]);
    return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
};
$writer=(new Writer($vault,$stories,$jobs,['api_timeout'=>60]))->withTransports($claudeTransport,$gptTransport);
echo json_encode(['turn'=>$writer->run($argv[1],$key)],JSON_UNESCAPED_UNICODE);
