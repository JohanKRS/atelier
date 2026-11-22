<?php
declare(strict_types=1);
// Prepares a story with a comic adaptation for the browser test of the comics (tests/comics-browser.mjs): a chapter,
// the book's sheets of Ana, Carla and the lighthouse, the adaptation's own (Ana and the lighthouse, brought from the
// book) with its lettering's sample, and three pages (one with the writer's own storyboard, one waiting for Carla's
// sheet in the adaptation, one with the app's storyboard). CLI only; the pictures are drawn here, with no real API
// calls. Prints the story's id.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Vault,Users,Jobs,OpenAI,Stories};
$dir=getenv('TEST_DATA');$password=getenv('TEST_PASSWORD');
if(!$dir||!$password)throw new RuntimeException('Test configuration missing');
$vault=new Vault($dir);$users=new Users($vault);
$user=$users->findByName('dono'); if(!$user)throw new RuntimeException('No test account');
$key=$users->unwrap($user,$password);$vault->useOwner($user['id']);
$jobs=new Jobs($vault,['api_timeout'=>60]);$stories=new Stories($vault);$comics=$stories->comics();

// A plain picture for each request: its size, one colour from its prompt.
$api=new OpenAI('test',60,function(string $path,array $body){
    [$w,$h]=array_map('intval',explode('x',$body['size']??'1024x1024'));
    $im=imagecreatetruecolor($w,$h); $hash=crc32($body['prompt']??'');
    imagefill($im,0,0,imagecolorallocate($im,60+($hash&0x7f),60+(($hash>>8)&0x7f),60+(($hash>>16)&0x7f)));
    imagefilledellipse($im,intdiv($w,2),intdiv($h,2),intdiv($w,2),intdiv($h,2),imagecolorallocate($im,240,240,240));
    ob_start();imagepng($im);$png=ob_get_clean();
    return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]];
});
$picture=function(string $prompt,string $size='1024x1024') use ($jobs,$key,$api): string {
    $job=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>$prompt,'options'=>['model'=>'gpt-image-2','quality'=>'low','size'=>$size,'n'=>1,'previews'=>1]],$key)[0];
    return $jobs->run($job['id'],$key,$api)['image_ids'][0];
};

$id=$stories->create('O Farol em Quadrinhos',$key)['id'];
$chapter=$stories->addChapter($id,'A chegada','Ana chega ao farol numa noite de tempestade.','',null,'person',$key);
$stories->updateCast($id,['character'=>'Ana','add'=>[['image'=>$picture('ficha de Ana'),'caption'=>'De capa de chuva']]],'person',$key);
$stories->updateCast($id,['character'=>'O Farol','kind'=>'place','add'=>[['image'=>$picture('o farol')]]],'person',$key);
$stories->updateCast($id,['character'=>'Carla','add'=>[['image'=>$picture('ficha de Carla'),'caption'=>'A filha do faroleiro']]],'person',$key);
$stories->updateStyle($id,['text'=>'Manga, black ink, screentones.','lettering'=>'Elegant serif for the book.'],'person',$key);
$comics->setSettings($id,['kind'=>'manga','reading'=>'rtl','color'=>'bw','aspect'=>'2:3','scale'=>'1'],'agent',$key);
$stories->sheetFromBook($id,'Ana','agent',$key); $stories->sheetFromBook($id,'O Farol','agent',$key);
$stories->updateStyle($id,['lettering'=>'Hand-lettered manga capitals.','lettering_image'=>$picture('amostra das letras','1536x1024')],'agent',$key,'comic');
$comics->addPage($id,['chapter'=>$chapter['id'],'summary'=>'Ana chega ao farol.','notes'=>'Night, heavy rain.','rows'=>[1,1],'panels'=>[
    ['framing'=>'wide shot','place'=>'O Farol','characters'=>[['name'=>'Ana','where'=>'right, small','doing'=>'walks up the path']],'description'=>'Rain lashes the rocks.','text'=>[['type'=>'caption','text'=>'Naquela noite, ninguém dormiu.'],['type'=>'sfx','by'=>'the rain','text'=>'SHAAA']]],
    ['framing'=>'close-up','characters'=>[['name'=>'Ana','doing'=>'knocks, worried']],'text'=>[['type'=>'speech','by'=>'Ana','text'=>'Tem alguém aí?']]]],
    'sketch'=>'<svg viewBox="0 0 1000 1500"><rect x="40" y="40" width="920" height="700" fill="none" stroke="#000" stroke-width="6"/><rect x="40" y="762" width="920" height="698" fill="none" stroke="#000" stroke-width="6"/>'
        .'<circle cx="760" cy="420" r="40" fill="none" stroke="#000" stroke-width="5"/><line x1="760" y1="460" x2="760" y2="600" stroke="#000" stroke-width="5"/><text x="500" y="1100" font-size="60" text-anchor="middle">TEM ALGUÉM AÍ?</text></svg>'],null,'agent',$key);
$comics->addPage($id,['chapter'=>$chapter['id'],'summary'=>'Carla abre a porta.','panels'=>[['framing'=>'medium shot','characters'=>[['name'=>'Carla','doing'=>'opens the door']],'text'=>[['type'=>'speech','by'=>'Carla','text'=>'Entre, depressa.']]]]],null,'agent',$key);
$comics->addPage($id,['chapter'=>$chapter['id'],'summary'=>'O mar, de manhã.','panels'=>[['framing'=>'wide shot','description'=>'The calm sea at dawn, seen from the lighthouse.']]],null,'agent',$key);
echo $id;
