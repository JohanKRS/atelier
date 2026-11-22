<?php
declare(strict_types=1);
// Runs a queued request with a simulated provider. CLI only; used by browser tests and local development.
// FIXTURE_SECONDS: simulated generation time. Prompts containing FALHAR fail; prompts containing SEMCREDITO fail as OpenAI
// does when the account has no credit left (insufficient_quota); prompts containing LENTO take 60 s.
// FIXTURE_PLANNING_OUTPUT: output tokens the planning of a prompt reports, with 320 input tokens (by default it
// reports only a total, like the oldest records, which can't be priced).
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Vault,Users,Jobs,OpenAI,HttpError};
$dir=getenv('TEST_DATA');$password=getenv('TEST_PASSWORD');
if(!$dir||!$password||!isset($argv[1]))throw new RuntimeException('Test configuration missing');
$vault=new Vault($dir);$users=new Users($vault);
// The request belongs to the account signed in to the browser under test.
$q=$vault->db->prepare('SELECT user_id FROM jobs WHERE id=?');$q->execute([$argv[1]]);$owner=(string)$q->fetchColumn();
// An open read statement would pin an old snapshot and make the claim's write transaction fail at once.
$q->closeCursor();unset($q);$user=$users->find($owner);
if(!$user)throw new RuntimeException('Unknown request');
$key=$users->unwrap($user,$password);$vault->useOwner($user['id']);
$jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>(int)(getenv('FIXTURE_PARALLEL')?:4)]);
$seconds=(float)(getenv('FIXTURE_SECONDS')?:0);
$planOutput=(int)(getenv('FIXTURE_PLANNING_OUTPUT')?:0);
$planUsage=$planOutput>0?['input_tokens'=>320,'output_tokens'=>$planOutput,'total_tokens'=>320+$planOutput]:['total_tokens'=>40];
$render=function(array $body,float $progress): string {
  [$w,$h]=preg_match('/^(\d+)x(\d+)$/',$body['size']??'',$m)?[(int)$m[1],(int)$m[2]]:[1024,1024];
  $hash=crc32($body['prompt']);
  if(!empty($body['images'][0]['image_url'])){
    $src=imagecreatefromstring(base64_decode(substr($body['images'][0]['image_url'],strpos($body['images'][0]['image_url'],',')+1)));
    $im=imagecreatetruecolor($w,$h);imagefill($im,0,0,imagecolorallocate($im,30,34,44));
    imagecopyresampled($im,$src,0,0,0,0,$w,$h,imagesx($src),imagesy($src));
  } else {
    $im=imagecreatetruecolor($w,$h);
    for($y=0;$y<$h;$y+=8){$t=$y/$h;imagefilledrectangle($im,0,$y,$w,$y+8,imagecolorallocate($im,(int)(30+40*$t),(int)(36+30*$t),(int)(58+60*$t)));}
  }
  $color=imagecolorallocate($im,80+($hash&0x7f),120+(($hash>>8)&0x7f),110+(($hash>>16)&0x7f));
  $r=(int)(min($w,$h)*(0.18+0.2*$progress));
  imagefilledellipse($im,(int)($w*(0.3+0.4*(($hash>>4)&0xff)/255)),(int)($h*0.55),$r*2,$r*2,$color);
  if($progress<1){$blur=(int)(6*(1-$progress));for($i=0;$i<$blur;$i++)imagefilter($im,IMG_FILTER_GAUSSIAN_BLUR);}
  ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;
};
$api=new OpenAI('fixture',60,function($path,$body,$onPartial=null,$tick=null) use ($render,$seconds,$planUsage){
  if($path==='/responses'&&isset($body['text']['format'])){
    // Batch variation planning: one simulated direction per image.
    preg_match('/a batch of (\d+) images/',$body['instructions'],$m);
    return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['variations'=>array_map(fn($i)=>"Cena $i: variação simulada",range(1,(int)$m[1]))])]]]],'usage'=>['total_tokens'=>25]];
  }
  // Prompts containing RECUSAR are refused by the planning model.
  if($path==='/responses'&&str_contains($body['input'][0]['content'][0]['text'],'RECUSAR'))return ['status'=>'completed','output'=>[['content'=>[['type'=>'refusal','refusal'=>'Recusado na simulação.']]]],'usage'=>['total_tokens'=>12]];
  if($path==='/responses')return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Prompt planejado: '.$body['input'][0]['content'][0]['text']]]]],'usage'=>$planUsage];
  $total=str_contains($body['prompt'],'LENTO')?60:$seconds;
  $steps=max(1,(int)($body['partial_images']??0)+1);
  for($i=0;$i<$steps;$i++){
    $until=microtime(true)+$total/$steps;
    while(microtime(true)<$until){ usleep(200000); if($tick)$tick(); }
    if(str_contains($body['prompt'],'SEMCREDITO'))throw OpenAI::failure(429,json_encode(['error'=>['message'=>'You exceeded your current quota.','type'=>'insufficient_quota','code'=>'insufficient_quota','param'=>null]]),'/images/generations');
    if(str_contains($body['prompt'],'FALHAR'))throw new HttpError(502,'A OpenAI recusou os parâmetros ou o conteúdo do pedido. Revise o modelo, o prompt e as opções.');
    if($onPartial && $i<$steps-1)$onPartial($render($body,($i+1)/$steps),$i);
  }
  $data=[];for($k=0;$k<($body['n']??1);$k++)$data[]=['b64_json'=>base64_encode($render(['prompt'=>$body['prompt'].' #'.$k]+$body,1.0))];
  return ['data'=>$data,'usage'=>['total_tokens'=>1800,'input_tokens'=>44,'output_tokens'=>1756,'input_tokens_details'=>['text_tokens'=>44,'image_tokens'=>0]]];
});
echo json_encode(['job'=>$jobs->run($argv[1],$key,$api)],JSON_THROW_ON_ERROR);
