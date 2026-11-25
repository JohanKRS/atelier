<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Crypto,Vault,OpenAI,Jobs,Users,Mcp,HttpError,Stories,History};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function rejects(callable $fn,string $message): void {try{$fn();}catch(Throwable){check(true,$message);return;}throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/imagegen-tests-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
$cipher=Crypto::seal('private prompt',$key,'test');
check(Crypto::open($cipher,$key,'test')==='private prompt','authenticated encryption round trip');
rejects(fn()=>Crypto::open($cipher,random_bytes(32),'test'),'wrong key rejected');
rejects(fn()=>Crypto::open($cipher,$key,'different'),'ciphertext context binding');
$tampered=$cipher;$tampered[30]=chr(ord($tampered[30])^1);
rejects(fn()=>Crypto::open($tampered,$key,'test'),'tampered ciphertext rejected');
foreach(OpenAI::SIZES as $ratio=>$size){$s=OpenAI::options(['size'=>$size]);check($s['size']===$size,"ratio $ratio accepted");}
rejects(fn()=>OpenAI::options(['size'=>'1080x1920']),'non-multiple-of-16 size rejected');
rejects(fn()=>OpenAI::options(['size'=>'256x256']),'too-small size rejected');
rejects(fn()=>OpenAI::options(['model'=>'gpt-image-1-mini','size'=>'1536x1152']),'unsupported model ratio rejected');
rejects(fn()=>OpenAI::options(['model'=>'gpt-image-2','quality'=>'max']),'unsupported quality rejected');
rejects(fn()=>OpenAI::options(['background'=>'transparent','format'=>'jpeg']),'JPEG transparency rejected');
rejects(fn()=>OpenAI::imageInput('https://example.com/image.png'),'external image URL rejected');
rejects(fn()=>Vault::validId('../../etc/passwd'),'path traversal rejected');
// What the log says of a failure: where it was and, for the database, what SQLite said (never the values)
$sqlite=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$sqlite->exec('CREATE TABLE t(id TEXT PRIMARY KEY)');
$insert=$sqlite->prepare('INSERT INTO t(id) VALUES(?)');$insert->execute(['secret-value']);
try{$insert->execute(['secret-value']);}catch(PDOException $e){$why=Studio\databaseReason($e);}
check($why===' (SQLSTATE 23000, SQLite 19: UNIQUE constraint failed: t.id)','a database failure is logged with what SQLite said');
check(!str_contains($why,'secret-value')&&Studio\databaseReason(new RuntimeException('secret-value'))==='','and never with the values, nor any other message');
try{
  $vault=new Vault($dir);$users=new Users($vault);
  $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
  $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
  $im=imagecreatetruecolor(128,96);$col=imagecolorallocate($im,180,190,110);imagefill($im,0,0,$col);ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
  $base=$vault->saveImage($bytes,['title'=>'Test','prompt'=>'private prompt','original_prompt'=>'private prompt','options'=>OpenAI::options([]),'parent_ids'=>[]],$key);
  check($vault->bytes($base['id'],'full',$key)===$bytes,'encrypted image retrieval');
  check(!str_contains(file_get_contents($dir.'/blobs/'.$base['id'].'-full.enc'),$bytes),'image is ciphertext on disk');
  $raw=$vault->db->query('SELECT meta FROM images')->fetchColumn();check(!str_contains($raw,'private prompt'),'prompt is encrypted in SQLite');
  check($vault->gallery($key,'PRIVATE',false,false,0)['total']===1,'encrypted metadata search after unlock');
  $vault->editMeta($base['id'],'Renamed','edited prompt',$key);
  $meta=$vault->image($base['id'],$key);check($meta['original_prompt']==='private prompt'&&$meta['prompt']==='edited prompt','edited prompt preserves original');
  $config=['api_timeout'=>60];$jobs=new Jobs($vault,$config);$calls=[];
  $transport=function($path,$body)use(&$calls,$bytes){$calls[]=[$path,$body];return ['data'=>[['b64_json'=>base64_encode($bytes)]],'usage'=>['total_tokens'=>10]];};
  $api=new OpenAI('test',60,$transport);$id=bin2hex(random_bytes(16));
  $a=$jobs->create(['id'=>$id,'prompt'=>'A mountain','options'=>[]],$key)[0];
  check($a['status']==='queued','generation queued');
  $jobs->create(['id'=>$id,'prompt'=>'Different prompt','options'=>[]],$key);
  check((int)$vault->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'create request idempotency');
  $out=$jobs->run($id,$key,$api);check($out['status']==='completed'&&count($out['image_ids'])===1,'generation saved to gallery');
  $jobs->run($id,$key,$api);check(count($calls)===1,'replayed run cannot charge again');
  // API balance: the tokens of each request are kept apart from its images, sealed, and added up from the moment the
  // balance was entered.
  $spendRow=$vault->db->query("SELECT * FROM spend WHERE job_id='$id'")->fetch();
  check($spendRow!==false&&!str_contains((string)$spendRow['data'],'total_tokens')&&(int)$vault->db->query('SELECT COUNT(*) FROM spend')->fetchColumn()===1,'each request keeps its tokens once, sealed');
  check($vault->balance($key)===['billing'=>null,'spent'=>[],'planning'=>[],'accounts'=>['claude'=>['billing'=>null,'spent'=>[]],'gemini'=>['billing'=>null,'spent'=>[]]]],'the balances are off until they are entered');
  rejects(fn()=>$vault->setBilling(['balance'=>'50','topup'=>100,'day'=>24],$key),'the balance must be a number');
  rejects(fn()=>$vault->setBilling(['balance'=>50,'topup'=>100,'day'=>32],$key),'the top-up day is a day of the month');
  rejects(fn()=>$vault->setBilling(['balance'=>50,'topup'=>-1,'day'=>24],$key),'the top-up is not negative');
  $billing=$vault->setBilling(['balance'=>50.256,'topup'=>100,'day'=>24],$key);
  $q=$vault->db->prepare('SELECT billing FROM users WHERE id=?');$q->execute([$vault->owner()]);$sealedBilling=(string)$q->fetchColumn();
  check($billing['balance']===50.26&&$billing['topup']===100.0&&$billing['day']===24&&abs($billing['since']-time())<5&&$sealedBilling!==''&&!str_contains($sealedBilling,'50.26'),'the balance is saved sealed, as of now');
  // The reader's settings (assets/reader.js): kept with the account, sealed, and only what the reader knows.
  check($vault->readerPrefs($key)===null,'the reader has no saved settings until they change');
  check($vault->setReaderPrefs(['size'=>133.4,'font'=>'sans','theme'=>'sepia','mode'=>'scroll','width'=>'huge','spread'=>'yes','device'=>false,'extra'=>'x'],$key)
    ===['size'=>133,'font'=>'sans','theme'=>'sepia','mode'=>'scroll','device'=>false],'the reader keeps only the settings it knows, with valid values');
  check($vault->setReaderPrefs(['progress'=>false,'remaining'=>false],$key)===['progress'=>false,'remaining'=>false]&&$vault->setReaderPrefs(['progress'=>0,'remaining'=>'no'],$key)===[],'the line at the foot of the reader can be turned off, with true or false only');
  check($vault->setReaderPrefs(['size'=>999],$key)===['size'=>200]&&$vault->setReaderPrefs(['size'=>10],$key)===['size'=>70],'the text size stays between 70% and 200%');
  rejects(fn()=>$vault->setReaderPrefs('sepia',$key),'reader settings must be an object');
  $vault->setReaderPrefs(['theme'=>'sepia','size'=>120,'open'=>true],$key);
  $q=$vault->db->prepare('SELECT prefs FROM users WHERE id=?');$q->execute([$vault->owner()]);$sealedPrefs=(string)$q->fetchColumn();
  check($vault->readerPrefs($key)===['size'=>120,'theme'=>'sepia','open'=>true]&&$sealedPrefs!==''&&!str_contains($sealedPrefs,'sepia'),'the reader settings are saved sealed with the account');
  $vault->db->prepare('UPDATE spend SET created=? WHERE job_id=?')->execute([$billing['since']-10,$id]);
  $vault->recordSpend(str_repeat('a',32),[['model'=>'gpt-image-2','usage'=>['input_tokens'=>1100,'output_tokens'=>1000,'input_tokens_details'=>['image_tokens'=>1000]]]],$key);
  $vault->recordSpend(str_repeat('b',32),[['model'=>'gpt-image-2','usage'=>['input_tokens'=>50,'output_tokens'=>500,'input_tokens_details'=>['text_tokens'=>50,'image_tokens'=>0]]]],$key);
  $vault->recordSpend(str_repeat('c',32),[['model'=>'gpt-image-2','usage'=>null]],$key);
  $imageSpent=['model'=>'gpt-image-2','kind'=>'image','planning'=>false,'requests'=>2,'input_tokens'=>1150,'output_tokens'=>1500,'input_tokens_details'=>['text_tokens'=>150,'image_tokens'=>1000,'cached_tokens'=>0]];
  check($vault->balance($key)['spent']===[$imageSpent],'requests since the balance was entered add up by model');
  // Planning is paid apart from the image: its calls are kept with the request and add up apart, by effort.
  $planUsage=['input_tokens'=>300,'output_tokens'=>900,'total_tokens'=>1200,'input_tokens_details'=>['cached_tokens'=>100]];
  $vault->recordSpend(str_repeat('b',32),[['model'=>OpenAI::PLANNER,'usage'=>$planUsage,'kind'=>'planning','effort'=>'medium'],['model'=>OpenAI::PLANNER,'usage'=>['total_tokens'=>40],'kind'=>'planning','effort'=>'medium']],$key);
  $planSpent=['model'=>OpenAI::PLANNER,'kind'=>'planning','planning'=>true,'requests'=>1,'input_tokens'=>300,'output_tokens'=>900,'input_tokens_details'=>['text_tokens'=>300,'image_tokens'=>0,'cached_tokens'=>100]];
  $withPlan=$vault->balance($key);
  check($withPlan['spent']===[$imageSpent,$planSpent],'a request that runs again adds to what it paid, and its planning is counted apart from its image');
  check($withPlan['planning']===['medium'=>['model'=>OpenAI::PLANNER,'calls'=>1,'input_tokens'=>300,'cached_tokens'=>100,'output_tokens'=>900]],'planning calls add up by effort, and one without a count of tokens is left out');
  // A request recorded before planning was counted gets its planning from the image it made, once.
  $oldJob=str_repeat('d',32);
  $oldImage=$vault->saveImage($bytes,['title'=>'Old','prompt'=>'old','original_prompt'=>'old','options'=>OpenAI::options(['thinking'=>'high']),'parent_ids'=>[],'job_id'=>$oldJob,'usage'=>['total_tokens'=>10],'planning_usage'=>$planUsage,'variation_usage'=>['input_tokens'=>50,'output_tokens'=>60]],$key);
  $vault->db->prepare('INSERT INTO spend(job_id,user_id,created,data) VALUES(?,?,?,CAST(? AS BLOB))')->execute([$oldJob,$vault->owner(),time(),Crypto::seal(json_encode(['model'=>'gpt-image-2','usage'=>['input_tokens'=>10,'output_tokens'=>20,'input_tokens_details'=>['text_tokens'=>10,'image_tokens'=>0]]]),$key,"spend:$oldJob")]);
  $upgraded=$vault->balance($key);$again=$vault->balance($key);
  check($upgraded['spent'][0]['requests']===3&&$upgraded['spent'][1]['requests']===3&&$upgraded['spent'][1]['output_tokens']===900+900+60&&$upgraded===$again,'a request from before planning was counted gets the planning kept in its image, once');
  check($upgraded['planning']['high']['calls']===1&&$upgraded['planning']['variation']===['model'=>OpenAI::PLANNER,'calls'=>1,'input_tokens'=>50,'cached_tokens'=>0,'output_tokens'=>60],'its effort comes from the options of the image, and the directions of a batch are counted apart');
  $q=$vault->db->prepare('SELECT data FROM spend WHERE job_id=?');$q->execute([$oldJob]);
  check(count(json_decode(Crypto::open((string)$q->fetchColumn(),$key,"spend:$oldJob"),true)['entries'])===3,'and is saved in the new shape');
  $vault->mark($oldImage['id'],'trash');$vault->purge($oldImage['id']);
  // The amount the app showed and the factor learned from a correction are kept with the balance.
  rejects(fn()=>$vault->setBilling(['balance'=>50,'topup'=>0,'day'=>24,'scale'=>3],$key),'the factor on the spending stays within bounds');
  rejects(fn()=>$vault->setBilling(['balance'=>50,'topup'=>0,'day'=>24,'scale'=>'1.1'],$key),'the factor is a number');
  $corrected=$vault->setBilling(['balance'=>16.73,'topup'=>100,'day'=>24,'scale'=>1.0712345,'shown'=>18],$key);
  check($corrected['scale']===1.0712&&$corrected['shown']===18.0&&$vault->setBilling(['balance'=>5,'topup'=>0,'day'=>1],$key)['scale']===1.0,'a correction keeps what the app showed and the factor; without one the factor is 1');
  $vault->setBilling(null,$key);check($vault->balance($key)['billing']===null,'the balance can be turned off');
  // Claude's and Gemini's balances: the same settings, kept sealed with the preferences, each counting its own spending.
  rejects(fn()=>$vault->setBilling(['balance'=>5,'topup'=>0,'day'=>1],$key,'xai'),'only the services the app knows have a balance');
  rejects(fn()=>$vault->setBilling(['balance'=>'5','topup'=>0,'day'=>1],$key,'claude'),'Claude\'s balance is checked like OpenAI\'s');
  $claudeBilling=$vault->setBilling(['balance'=>20,'topup'=>0,'day'=>5],$key,'claude');
  $q=$vault->db->prepare('SELECT prefs, billing FROM users WHERE id=?');$q->execute([$vault->owner()]);$row=$q->fetch();$q->closeCursor();
  check($vault->billing($key,'claude')==$claudeBilling&&$vault->billing($key)===null&&$row['billing']===null&&!str_contains((string)$row['prefs'],'"balance"'),'Claude\'s balance is kept sealed apart from OpenAI\'s');
  check($vault->readerPrefs($key)===['size'=>120,'theme'=>'sepia','open'=>true],'and the other preferences stay as they were');
  $vault->setBilling(['balance'=>30,'topup'=>0,'day'=>5],$key);
  $vault->db->exec('UPDATE spend SET created=created-100');
  $vault->recordSpend(str_repeat('e',32),[['model'=>'claude-opus-5-5','usage'=>['input_tokens'=>10,'output_tokens'=>20],'kind'=>'writing','effort'=>'high','cost'=>0.25]],$key);
  $vault->recordSpend(str_repeat('e',32),[['model'=>'claude-opus-5-5','usage'=>['input_tokens'=>10,'output_tokens'=>20],'kind'=>'writing','cost'=>0.5],['model'=>'claude-opus-5-5','usage'=>['cache_read_input_tokens'=>10],'kind'=>'warm','cost'=>0.01]],$key);
  $vault->recordSpend(str_repeat('f',32),[['model'=>'gpt-6.2-sol','usage'=>['input_tokens'=>1000,'output_tokens'=>100],'kind'=>'writing','cost'=>0.0042]],$key);
  $both=$vault->balance($key);
  check($both['accounts']['claude']['spent']===[['model'=>'claude-opus-5-5','kind'=>'writing','planning'=>false,'requests'=>2,'cost'=>0.75],['model'=>'claude-opus-5-5','kind'=>'warm','planning'=>true,'requests'=>1,'cost'=>0.01]]
      &&$both['accounts']['claude']['billing']==$claudeBilling&&$both['accounts']['gemini']===['billing'=>null,'spent'=>[]],'what the Claude writer cost adds up in Claude\'s balance, by kind');
  check(array_column($both['spent'],'model')===['gpt-6.2-sol']&&$both['spent'][0]['kind']==='writing'&&$both['spent'][0]['requests']===1,'and not in OpenAI\'s, which counts the GPT writer in tokens');
  $vault->setBilling(null,$key,'claude');
  check($vault->billing($key,'claude')===null&&$vault->balance($key)['accounts']['claude']['spent']===[]&&$vault->billing($key)!==null,'Claude\'s balance is turned off alone');
  $vault->setBilling(null,$key);
  $vault->db->exec("DELETE FROM spend WHERE job_id<>'$id'");
  check($calls[0][0]==='/images/generations'&&$calls[0][1]['moderation']==='low','generation endpoint and minimum supported moderation');
  check($calls[0][1]['complexity']==='high','image complexity sent for 2.6');
  $id2=bin2hex(random_bytes(16));$mask=imagecreatetruecolor(128,96);imagealphablending($mask,false);imagesavealpha($mask,true);imagefill($mask,0,0,imagecolorallocatealpha($mask,0,0,0,127));ob_start();imagepng($mask);$maskData=ob_get_clean();imagedestroy($mask);
  $jobs->create(['id'=>$id2,'prompt'=>'Change the background','options'=>['model'=>'gpt-image-2'],'references'=>[['id'=>$base['id']]],'mask'=>'data:image/png;base64,'.base64_encode($maskData)],$key);
  $jobs->run($id2,$key,$api);$call=$calls[1];check($call[0]==='/images/edits'&&isset($call[1]['images'][0]['image_url'],$call[1]['mask']['image_url']),'edit endpoint receives private base64 image and mask');
  check(!isset($call[1]['complexity']),'unsupported complexity omitted');
  check(OpenAI::resolveMentions('No mentions here',2)==='No mentions here','prompts without mentions are sent as written');
  check(OpenAI::resolveMentions('a@2.com, @3, @2x or @0',2)==='a@2.com, @3, @2x or @0','e-mail addresses and numbers without an image are not mentions');
  $named=OpenAI::resolveMentions('O rosto de @1 com a roupa de @2',2,true);
  check(str_ends_with($named,"\n\nO rosto de [image 1] com a roupa de [image 2]")&&str_contains($named,'[image 1] is the 1st attached image (the image being edited); [image 2] is the 2nd attached image.'),'mentions name the attached images by position');
  $id5=bin2hex(random_bytes(16));$jobs->create(['id'=>$id5,'prompt'=>'Cores de @1 com a forma de @2','options'=>['model'=>'gpt-image-2'],'references'=>[['id'=>$base['id']],['id'=>$base['id']]]],$key);
  $mentioned=$jobs->run($id5,$key,$api);$call=end($calls);
  check(count($call[1]['images'])===2&&str_ends_with($call[1]['prompt'],'Cores de [image 1] com a forma de [image 2]')&&$vault->image($mentioned['image_ids'][0],$key)['prompt']==='Cores de @1 com a forma de @2','mentions reach the model as image numbers; the library keeps the prompt as written');
  $vault->mark($mentioned['image_ids'][0],'trash');$vault->purge($mentioned['image_ids'][0]);
  $planned=[];$variationPlanner=new OpenAI('test',60,function($path,$body)use(&$planned){$planned[]=$body;return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'{"variations":["a","b"]}']]]]];});
  $variationPlanner->planVariations('Pose de @2',['data:image/png;base64,AA==','data:image/png;base64,AA=='],2,true);
  check(str_contains($planned[0]['input'][0]['content'][0]['text'],'Pose de [image 2]'),'variation planning reads mentions as image numbers');
  $plannerCalls=[];$planner=new OpenAI('test',60,function($path,$body)use(&$plannerCalls,$bytes){$plannerCalls[]=[$path,$body];return $path==='/responses'?['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Prepared visual prompt']]]]]:['data'=>[['b64_json'=>base64_encode($bytes)]]];});
  $id3=bin2hex(random_bytes(16));$jobs->create(['id'=>$id3,'prompt'=>'Plan a landscape','options'=>['thinking'=>'max']],$key);$jobs->run($id3,$key,$planner);
  check($plannerCalls[0][1]['store']===false&&$plannerCalls[0][1]['reasoning']['effort']==='max','planning disables response storage and sends reasoning effort');
  check($plannerCalls[1][1]['prompt']==='Prepared visual prompt','planned prompt reaches image model');
  // What the planning cost is kept with the request, also when the image fails after it and when the request runs again.
  $spendOf=function(string $job)use($vault,$key):array{$q=$vault->db->prepare('SELECT data FROM spend WHERE job_id=?');$q->execute([$job]);$v=$q->fetchColumn();return $v===false?[]:array_map(fn($e)=>[$e['model'],$e['kind'],$e['effort'],$e['usage']['output_tokens']??null],json_decode(Crypto::open((string)$v,$key,"spend:$job"),true)['entries']);};
  $fails=true;$paying=new OpenAI('test',60,function($path,$body)use(&$fails,$bytes){
    if($path==='/responses')return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Prepared visual prompt']]]],'usage'=>['input_tokens'=>300,'output_tokens'=>900,'total_tokens'=>1200]];
    if($fails)throw new HttpError(502,'Simulated image failure');
    return ['data'=>[['b64_json'=>base64_encode($bytes)]],'usage'=>['input_tokens'=>44,'output_tokens'=>1756,'input_tokens_details'=>['text_tokens'=>44,'image_tokens'=>0]]];});
  $paidId=bin2hex(random_bytes(16));$jobs->create(['id'=>$paidId,'prompt'=>'Plan and fail','options'=>['thinking'=>'medium']],$key);
  check($jobs->run($paidId,$key,$paying)['status']==='failed'&&$spendOf($paidId)===[[OpenAI::PLANNER,'planning','medium',900]],'planning is paid, and counted, when the image fails after it');
  $fails=false;$paidAgain=bin2hex(random_bytes(16));$jobs->retry($paidAgain,$paidId,null,$key);$paidOut=$jobs->run($paidAgain,$key,$paying);
  check($paidOut['status']==='completed'&&$spendOf($paidAgain)===[[OpenAI::PLANNER,'planning','medium',900],['gpt-image-2.6-flare','image',null,1756]]&&count($spendOf($paidId))===1,'a planned request keeps its planning and its image; the one that failed keeps what it paid');
  $vault->mark($paidOut['image_ids'][0],'trash');$vault->purge($paidOut['image_ids'][0]);
  $fail=new OpenAI('test',60,fn()=>throw new HttpError(502,'Simulated upstream failure'));
  $id4=bin2hex(random_bytes(16));$jobs->create(['id'=>$id4,'prompt'=>'Will fail','options'=>[]],$key);$failed=$jobs->run($id4,$key,$fail);check($failed['status']==='failed'&&$failed['prompt']==='Will fail','failed requests preserve prompt without replay');
  $vault->throttle('test',1,30);rejects(fn()=>$vault->throttle('test',1,30),'rate limit enforced');
  check($vault->throttle('count',3,30)===1&&$vault->throttle('count',3,30)===2,'throttle reports the attempts so far');
  $vault->forget('count');check($vault->throttle('count',3,30)===1,'forgetting a bucket starts it over');
  $vault->remember('device:known',60);$vault->throttle('device:other',3,30);
  check($vault->remembered('device:known')&&!$vault->remembered('device:other')&&!$vault->remembered('device:missing'),'only remembered devices are recognised');
  $q=$vault->db->prepare('UPDATE images SET deleted=? WHERE id=?');$q->execute([time(),$base['id']]);
  rejects(fn()=>$vault->bytes($base['id'],'full',$key),'trashed image cannot be fetched');
  check($vault->gallery($key,'',false,true,0)['total']===1,'trash is recoverable');

  // Versions: edits and expansions stay linked to the image they came from.
  $root=$vault->saveImage($bytes,['title'=>'Root','prompt'=>'root','original_prompt'=>'root','options'=>OpenAI::options([]),'parent_ids'=>[],'parent_id'=>null,'operation'=>'generate'],$key);
  $editJob=bin2hex(random_bytes(16));
  $jobs->create(['id'=>$editJob,'prompt'=>'Make the sky blue','label'=>'Céu azul','parent_id'=>$root['id'],'references'=>[['id'=>$root['id']]],'options'=>[]],$key);
  $edited=$jobs->run($editJob,$key,$api);
  check($edited['status']==='completed'&&$edited['parent_id']===$root['id']&&$edited['operation']==='edit','edit request keeps the edited image');
  $child=$vault->image($edited['image_ids'][0],$key);
  check($child['parent_id']===$root['id']&&$child['operation']==='edit'&&$child['label']==='Céu azul'&&$child['title']==='Céu azul','new version records parent, operation and label');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'No source','parent_id'=>$root['id'],'options'=>[]],$key),'edit without the original image rejected');
  $expandJob=bin2hex(random_bytes(16));
  $jobs->create(['id'=>$expandJob,'prompt'=>'Continue the scene','operation'=>'expand','parent_id'=>$child['id'],'references'=>[['id'=>$child['id']]],'options'=>[]],$key);
  $grandchild=$vault->image($jobs->run($expandJob,$key,$api)['image_ids'][0],$key);
  check($grandchild['parent_id']===$child['id']&&Vault::operation($grandchild)==='expand','expansion is a version of the expanded image');
  $upscaleJob=bin2hex(random_bytes(16));
  $jobs->create(['id'=>$upscaleJob,'prompt'=>'Recreate larger','operation'=>'upscale','parent_id'=>$root['id'],'references'=>[['id'=>$root['id']]],'options'=>['size'=>'2048x2048']],$key);
  $upscaled=$jobs->run($upscaleJob,$key,$api);
  check($upscaled['operation']==='upscale'&&$vault->image($upscaled['image_ids'][0],$key)['parent_id']===$root['id']&&end($calls)[1]['size']==='2048x2048','upscale is a version of the source image at the requested size');
  // Removed again so the lineage checks below see only edits and expansions.
  $q=$vault->db->prepare('UPDATE images SET deleted=? WHERE id=?');$q->execute([time(),$upscaled['image_ids'][0]]);$vault->purge($upscaled['image_ids'][0]);
  $legacy=$vault->saveImage($bytes,['title'=>'Legacy','prompt'=>'older edit','original_prompt'=>'older edit','options'=>OpenAI::options([]),'parent_ids'=>[$root['id']]],$key);
  $tree=$vault->lineage($grandchild['id'],$key);
  check($tree['root']===$root['id']&&array_column($tree['nodes'],'id')===[$root['id'],$child['id'],$grandchild['id'],$legacy['id']],'lineage lists every version oldest first, including edits saved before versions existed');
  check(array_column($tree['nodes'],'parent')===[null,$root['id'],$child['id'],$root['id']],'lineage nodes carry their parent');
  $flat=$vault->gallery($key,'',false,false,0,100);$grouped=$vault->gallery($key,'',false,false,0,100,true);
  check($grouped['total']===$flat['total']-3&&$grouped['items'][0]['id']===$legacy['id']&&$grouped['items'][0]['versions']===4,'grouped gallery shows one entry per lineage, its newest version first');
  check(count(array_filter($flat['items'],fn($i)=>$i['root']===$root['id']))===4,'ungrouped gallery shows every version');
  $q=$vault->db->prepare('UPDATE images SET deleted=? WHERE id=?');$q->execute([time(),$child['id']]);
  check(count($vault->lineage($grandchild['id'],$key)['nodes'])===4,'trashed middle version still connects the tree');
  rejects(fn()=>$vault->lineage($child['id'],$key),'trashed version has no lineage view');
  rejects(fn()=>$vault->purge($root['id']),'purge requires the trash first');
  $vault->purge($child['id']);
  check(!is_file($dir.'/blobs/'.$child['id'].'-full.enc')&&$vault->lineage($grandchild['id'],$key)['root']===$grandchild['id'],'purge deletes files and later versions start their own lineage');
  $imported=$vault->importImage($bytes,"foto\x01 da praia.png",$key);
  check($imported['title']==='foto  da praia'&&Vault::operation($imported)==='import'&&$vault->lineage($imported['id'],$key)['nodes'][0]['operation']==='import','imported image starts a lineage');
  // Limpar: an image cleaned on the owner's device becomes a version of the image it came from, without the API.
  $before=count($calls);$cleaned=$vault->cleanedImage($bytes,$imported['id'],'media',$key);
  check(Vault::operation($cleaned)==='clean'&&$cleaned['label']==='Limpeza média'&&$cleaned['parent_id']===$imported['id']&&$cleaned['options']===null&&count($calls)===$before
    &&array_column($vault->lineage($imported['id'],$key)['nodes'],'operation')===['import','clean'],'a cleaned copy is a version of its image, made without the API');
  $im=imagecreatetruecolor(96,128);ob_start();imagepng($im);$turned=ob_get_clean();imagedestroy($im);$im=imagecreatetruecolor(64,64);ob_start();imagepng($im);$small=ob_get_clean();imagedestroy($im);
  check(Vault::operation($vault->cleanedImage($turned,$imported['id'],'forte',$key))==='clean','a cleaned copy may have its sides swapped (the browser applies the camera rotation)');
  rejects(fn()=>$vault->cleanedImage($small,$imported['id'],'leve',$key),'a cleaned copy has the size of its image');
  rejects(fn()=>$vault->cleanedImage($bytes,$imported['id'],'extra',$key),'cleanup levels are validated');
  rejects(fn()=>$vault->cleanedImage($bytes,str_repeat('0',32),'leve',$key),'a cleaned copy needs its image');
  // A crop made on the owner's device (the frame of Expandir inside the image) is a version too, also without the API.
  $before=count($calls);$crop=$vault->croppedImage($small,$imported['id'],$key);
  check(Vault::operation($crop)==='crop'&&$crop['label']==='Recorte para 64 × 64'&&$crop['parent_id']===$imported['id']&&$crop['options']===null&&[$crop['width'],$crop['height']]===[64,64]&&count($calls)===$before
    &&in_array('crop',array_column($vault->lineage($imported['id'],$key)['nodes'],'operation'),true)&&$vault->image($imported['id'],$key)['width']===128,'a crop is a version of its image, made without the API, and the image stays as it was');
  $im=imagecreatetruecolor(90,120);ob_start();imagepng($im);$tall=ob_get_clean();imagedestroy($im);$im=imagecreatetruecolor(200,64);ob_start();imagepng($im);$wide=ob_get_clean();imagedestroy($im);
  check(Vault::operation($vault->croppedImage($tall,$imported['id'],$key))==='crop','a crop may have its sides swapped (the browser applies the camera rotation)');
  rejects(fn()=>$vault->croppedImage($bytes,$imported['id'],$key),'a crop is smaller than its image');
  rejects(fn()=>$vault->croppedImage($wide,$imported['id'],$key),'a crop fits inside its image');
  rejects(fn()=>$vault->croppedImage('not an image',$imported['id'],$key),'a crop is an image');
  rejects(fn()=>$vault->croppedImage($small,str_repeat('0',32),$key),'a crop needs its image');

  // Several requests at once, cancellation, retry and dismissal.
  $queuedIds=array_map(fn()=>bin2hex(random_bytes(16)),range(1,3));
  foreach($queuedIds as $qid)$jobs->create(['id'=>$qid,'prompt'=>'Parallel '.$qid,'options'=>[]],$key);
  $q=$vault->db->prepare("UPDATE jobs SET status='running',updated=? WHERE id IN (?,?)");$q->execute([time(),$queuedIds[0],$queuedIds[1]]);
  $before=count($calls);
  check((new Jobs($vault,['api_timeout'=>60,'max_parallel'=>2]))->run($queuedIds[2],$key,$api)['status']==='queued'&&count($calls)===$before,'max_parallel keeps extra requests queued without calling the API');
  check((new Jobs($vault,['api_timeout'=>60,'max_parallel'=>3]))->run($queuedIds[2],$key,$api)['status']==='completed','requests run in parallel up to max_parallel');
  $q=$vault->db->prepare("UPDATE jobs SET updated=? WHERE id=?");$q->execute([time()-Jobs::STALE_AFTER-1,$queuedIds[0]]);
  $q=$vault->db->prepare("UPDATE jobs SET status='cancelling',updated=? WHERE id=?");$q->execute([time()-Jobs::STALE_AFTER-1,$queuedIds[1]]);
  $byId=array_column($jobs->list($key,'history'),null,'id');
  check($byId[$queuedIds[0]]['status']==='uncertain'&&str_contains($byId[$queuedIds[0]]['error'],'hospedagem'),'silent running request becomes uncertain with a hosting hint');
  check($byId[$queuedIds[1]]['status']==='cancelled','stale cancellation ends as cancelled');
  // A request is interrupted only when its process is gone, however long it runs (the Máxima level often passes 5
  // minutes). The process holds a lock on a file of the request's own from before it claims it until after it ends it.
  $lockOf=fn(string $jobId)=>$dir.'/locks/'.$jobId.'.lock';
  $rowOf=function(string $jobId)use($vault){$q=$vault->db->prepare('SELECT status,worker_lock,started,updated FROM jobs WHERE id=?');$q->execute([$jobId]);$r=$q->fetch();$q->closeCursor();return $r;};
  $longId=bin2hex(random_bytes(16));$jobs->create(['id'=>$longId,'prompt'=>'Slow at the highest quality','options'=>['previews'=>0]],$key);$inside=[];$heard=null;
  $long=new OpenAI('test',60,function($path,$body,$onPartial,$tick)use($jobs,$vault,$longId,$key,$bytes,$rowOf,$lockOf,&$inside){
    $inside['row']=$rowOf($longId);$inside['file']=is_file($lockOf($longId));
    // Silent for longer than STALE_AFTER, but its process (this one) still holds the lock.
    $vault->db->prepare('UPDATE jobs SET updated=? WHERE id=?')->execute([time()-Jobs::STALE_AFTER-30,$longId]);
    $inside['silent']=array_column($jobs->list($key,'history'),null,'id')[$longId]['status'];
    // Silent for longer than a whole call to the provider: given up on, lock or not.
    $vault->db->prepare('UPDATE jobs SET updated=? WHERE id=?')->execute([time()-60-Jobs::STALE_AFTER-5,$longId]);
    $inside['ceiling']=array_column($jobs->list($key,'history'),null,'id')[$longId];
    // It speaks again (the heartbeat is written at most every 3 s).
    usleep(3100000);$tick();$inside['revived']=$rowOf($longId)['status'];
    return ['data'=>[['b64_json'=>base64_encode($bytes)]],'usage'=>['total_tokens'=>10]];
  });
  $ran=$jobs->run($longId,$key,$long,null,function(array $job)use(&$heard,$vault,$longId){$heard=$job;});
  check($heard!==null&&$heard['status']==='running'&&$heard['watched']===true,'the route hears of the claimed request before the provider is called');
  check((int)$inside['row']['worker_lock']===1&&(int)$inside['row']['started']>0&&$inside['file'],'the process of a running request holds a lock on its own file');
  check($inside['silent']==='running','a silent request whose process holds its lock is not interrupted');
  check($inside['ceiling']['status']==='uncertain'&&str_contains($inside['ceiling']['error'],'quando esperava a imagem'),'a process silent for longer than a whole provider call is given up on');
  check($inside['revived']==='running','a request marked as interrupted runs again when its process speaks');
  check($ran['status']==='completed'&&count($ran['image_ids'])===1&&!is_file($lockOf($longId)),'it ends as it ends, and its lock file goes');
  $deadId=bin2hex(random_bytes(16));$jobs->create(['id'=>$deadId,'prompt'=>'Stopped by the host','options'=>[]],$key);
  $beat=time()-3;$vault->db->prepare("UPDATE jobs SET status='running',phase='generating',worker_lock=1,started=?,updated=? WHERE id=?")->execute([$beat-312,$beat,$deadId]);
  touch($lockOf($deadId));// the process died and left its file, unlocked
  check(array_column($jobs->list($key,'history'),null,'id')[$deadId]['status']==='running','a request is checked only after some seconds of silence');
  $beat=time()-Jobs::CHECK_AFTER-1;$vault->db->prepare('UPDATE jobs SET started=?,updated=? WHERE id=?')->execute([$beat-312,$beat,$deadId]);
  $dead=array_column($jobs->list($key,'history'),null,'id')[$deadId];
  check($dead['status']==='uncertain'&&str_contains($dead['error'],'encerrado depois de 5 min 12 s, quando esperava a imagem')&&str_contains($dead['error'],'hospedagem')&&!is_file($lockOf($deadId)),
    'a request whose process is gone is interrupted within seconds, and says after how long and where it stopped');
  $goneId=bin2hex(random_bytes(16));$jobs->create(['id'=>$goneId,'prompt'=>'Cancelled, then stopped','options'=>[]],$key);
  $vault->db->prepare("UPDATE jobs SET status='cancelling',worker_lock=1,started=?,updated=? WHERE id=?")->execute([time()-100,time()-Jobs::CHECK_AFTER-1,$goneId]);
  check(array_column($jobs->list($key,'history'),null,'id')[$goneId]['status']==='cancelled','a cancellation whose process is gone ends at once');
  $heldId=bin2hex(random_bytes(16));$jobs->create(['id'=>$heldId,'prompt'=>'Started by another process','options'=>[]],$key);
  $held=fopen($lockOf($heldId),'c');flock($held,LOCK_EX);$before=count($calls);
  check($jobs->run($heldId,$key,$api)['status']==='queued'&&count($calls)===$before,'a request whose lock another process holds is left to that process');
  flock($held,LOCK_UN);fclose($held);
  check($jobs->run($heldId,$key,$api)['status']==='completed'&&count($calls)===$before+1,'and it runs once that lock is free');
  $cancelId=bin2hex(random_bytes(16));$jobs->create(['id'=>$cancelId,'prompt'=>'Cancel me','options'=>[]],$key);
  check($jobs->cancel($cancelId,$key)['status']==='cancelled','queued request cancels at once');
  check($jobs->run($cancelId,$key,$api)['status']==='cancelled'&&count($calls)===$before+1,'cancelled request never reaches the API');
  rejects(fn()=>$jobs->cancel($cancelId,$key),'finished request cannot be cancelled');
  $runningId=bin2hex(random_bytes(16));$jobs->create(['id'=>$runningId,'prompt'=>'Slow one','options'=>[]],$key);$seen=null;
  $slow=new OpenAI('test',60,function($path,$body,$onPartial,$tick)use($jobs,$vault,$runningId,$key,&$seen){
    $q=$vault->db->prepare('SELECT status,phase FROM jobs WHERE id=?');$q->execute([$runningId]);$seen=$q->fetch();
    $jobs->cancel($runningId,$key);$until=microtime(true)+8;
    while(microtime(true)<$until){$tick();usleep(50000);}
    throw new RuntimeException('cancellation was not noticed');
  });
  $cancelled=$jobs->run($runningId,$key,$slow);
  check($seen['status']==='running'&&$seen['phase']==='generating','running request reports its phase');
  check($cancelled['status']==='cancelled'&&str_contains($cancelled['error'],'cobrado'),'running request stops at the next heartbeat after cancel');
  $retryId=bin2hex(random_bytes(16));$retried=$jobs->retry($retryId,$editJob,3,$key);
  check(count($retried)===3&&array_column($retried,'id')===Jobs::batchIds($retryId,3)&&$retried[2]['status']==='queued'&&$retried[2]['options']['n']===1&&$retried[2]['parent_id']===$root['id']&&$retried[2]['reference_count']===1,'retry queues the same inputs once per requested image');
  check(count($jobs->retry($retryId,$editJob,3,$key))===3&&count($jobs->list($key,'history'))===count(array_unique(array_column($jobs->list($key,'history'),'id'))),'repeated retry request finds the same jobs');
  $orphanId=bin2hex(random_bytes(16));check($jobs->retry($orphanId,$expandJob,null,$key)[0]['parent_id']===null,'retry of a purged image starts a new lineage');
  $feed=array_column($jobs->list($key,'feed'),'id');check(in_array($id4,$feed,true),'failed request shows in the feed');
  $jobs->dismiss($id4,$key);
  check(!in_array($id4,array_column($jobs->list($key,'feed'),'id'),true)&&array_column($jobs->list($key,'history'),null,'id')[$id4]['dismissed'],'dismissed request leaves the feed but stays in history');
  rejects(fn()=>$jobs->dismiss($id,$key),'successful request cannot be dismissed');
  $active=array_column($jobs->list($key,'active',[$id]),'status','id');
  check(isset($active[$retryId])&&($active[$id]??'')==='completed'&&!isset($active[$id4]),'active scope lists pending requests plus the ones asked for');

  // Partial previews while the image is generated.
  $streamId=bin2hex(random_bytes(16));$jobs->create(['id'=>$streamId,'prompt'=>'Stream it','options'=>[]],$key);$streamed=[];
  $streaming=new OpenAI('test',60,function($path,$body,$onPartial)use($vault,$streamId,$key,$dir,$bytes,&$streamed){
    $streamed['body']=$body;$onPartial($bytes,0);
    $streamed['preview']=$vault->preview($streamId,$key);$streamed['disk']=file_get_contents($dir.'/blobs/job-'.$streamId.'-preview.enc');
    $q=$vault->db->prepare('SELECT preview FROM jobs WHERE id=?');$q->execute([$streamId]);$streamed['count']=(int)$q->fetchColumn();
    return ['data'=>[['b64_json'=>base64_encode($bytes)]]];
  });
  $jobs->run($streamId,$key,$streaming);
  check($streamed['body']['stream']===true&&$streamed['body']['partial_images']===2,'single images stream partial previews');
  check($streamed['preview']===$bytes&&$streamed['count']===1&&!str_contains($streamed['disk'],$bytes),'partial preview is encrypted and counted');
  rejects(fn()=>$vault->preview($streamId,$key),'preview is deleted when the request ends');
  // Several images in one request become separate jobs: one failure does not take the others with it.
  $multiId=bin2hex(random_bytes(16));$multi=$jobs->create(['id'=>$multiId,'prompt'=>'Three separate','options'=>['n'=>3,'previews'=>0]],$key);
  check(count($multi)===3&&count(array_unique(array_column($multi,'id')))===3&&array_column(array_column($multi,'options'),'n')===[1,1,1],'a request for 3 images becomes 3 jobs of 1 image');
  check(array_column($jobs->create(['id'=>$multiId,'prompt'=>'Three separate','options'=>['n'=>3]],$key),'id')===array_column($multi,'id'),'repeated multi-image request finds the same jobs');
  $before=count($calls);
  $results=[$jobs->run($multi[0]['id'],$key,$api)['status'],$jobs->run($multi[1]['id'],$key,$fail)['status'],$jobs->run($multi[2]['id'],$key,$api)['status']];
  check($results===['completed','failed','completed']&&count($calls)===$before+2&&end($calls)[1]['n']===1,'one image failing leaves the others of the same request');
  check(end($calls)[1]['prompt']==="Three separate\n\n".OpenAI::singleImage(3),'images of a set made from the same prompt are each one whole picture too');
  // Varied batches: one planning call gives each image its own direction; images of the same batch share it.
  $planCalls=[];
  $varier=new OpenAI('test',60,function($path,$body)use(&$calls,&$planCalls,$bytes){
    if($path==='/responses'){$planCalls[]=$body;preg_match('/a batch of (\d+) images/',$body['instructions'],$m);
      return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['variations'=>array_map(fn($i)=>"Scene $i\u{0007}  at night",range(1,(int)$m[1]))])]]]],'usage'=>['total_tokens'=>30]];}
    $calls[]=[$path,$body];return ['data'=>[['b64_json'=>base64_encode($bytes)]],'usage'=>['total_tokens'=>10]];
  });
  $variedId=bin2hex(random_bytes(16));$varied=$jobs->create(['id'=>$variedId,'prompt'=>'A red fox','options'=>['n'=>3,'previews'=>0,'vary'=>true]],$key);
  $prompts=[];$done=[];foreach($varied as $job){$done[]=$jobs->run($job['id'],$key,$varier);$prompts[]=end($calls)[1]['prompt'];}
  check(count($planCalls)===1&&$planCalls[0]['text']['format']['type']==='json_schema'&&$planCalls[0]['input'][0]['content'][0]['text']==="Request:\nA red fox",'a varied batch is planned once');
  check($prompts===array_map(fn($i)=>"A red fox\n\nDirection for this image: Scene $i at night\n\n".OpenAI::singleImage(3),[1,2,3]),'each image of a varied batch gets its own planned direction');
  check(str_contains(end($planCalls)['instructions'],'never put several poses, views or panels into one image')&&str_contains(OpenAI::singleImage(3),'one image of a set of 3')&&str_contains(OpenAI::singleImage(3),'never a grid, collage'),'every image of a set is one whole picture, never a grid of several');
  $variedImage=$vault->image($done[1]['image_ids'][0],$key);
  check($variedImage['variation']===['index'=>2,'count'=>3,'label'=>'Scene 2 at night','planned'=>true,'written'=>false]&&$done[1]['variation']['label']==='Scene 2 at night','variation is recorded with the image');
  check($vault->image($done[0]['image_ids'][0],$key)['variation_usage']===['total_tokens'=>30]&&$variedImage['variation_usage']===null,'planning usage is recorded once per batch');
  check($varied[1]['variation']===['index'=>2,'count'=>3,'label'=>null,'planned'=>false,'written'=>false],'queued images show their place in the batch');
  $plain=$jobs->run($multi[0]['id'],$key,$api);check($vault->image($plain['image_ids'][0],$key)['variation']===null&&$plain['variation']===null,'batches without vary keep the same prompt');
  $againId=bin2hex(random_bytes(16));$again=$jobs->retry($againId,$varied[2]['id'],1,$key);$jobs->run($again[0]['id'],$key,$varier);
  check(str_ends_with(end($calls)[1]['prompt'],"Scene 3 at night\n\n".OpenAI::singleImage(3))&&count($planCalls)===1,'repeating one image of a varied batch keeps its direction without planning again');
  $moreId=bin2hex(random_bytes(16));$more=$jobs->retry($moreId,$varied[2]['id'],2,$key);
  check(array_column(array_column($more,'variation'),'index')===[1,2],'repeating as a new batch numbers the variations again');
  // Edits vary too: the planner sees the image being edited and is told to keep its subjects.
  $editsId=bin2hex(random_bytes(16));$edits=$jobs->create(['id'=>$editsId,'prompt'=>'Put her in different scenes','options'=>['n'=>2,'vary'=>true,'previews'=>0],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['id'=>$variedImage['id']]]],$key);
  $jobs->run($edits[0]['id'],$key,$varier);
  check(end($calls)[0]==='/images/edits'&&str_ends_with(end($calls)[1]['prompt'],"Scene 1 at night\n\n".OpenAI::singleImage(2)."\n\n".OpenAI::FIDELITY)&&end($planCalls)['input'][0]['content'][1]['type']==='input_image'&&str_contains(end($planCalls)['instructions'],'edits of the attached image'),'edits of an image can be varied');
  check(substr_count(end($calls)[1]['prompt'],OpenAI::FIDELITY)===1&&str_contains(OpenAI::FIDELITY,'dandruff')&&str_contains(OpenAI::FIDELITY,'One person has one skin: the body, neck and limbs keep the same tone, finish and quality as the face')&&str_contains(OpenAI::FIDELITY,'Skin finish: lean matte')&&str_contains(OpenAI::FIDELITY,'Pores only where the view is close enough for real pores to show'),'edits carry the skin and fidelity guard once, after the request');
  check(str_contains(OpenAI::FIDELITY,"keep every person's build, proportions and musculature")&&str_starts_with(OpenAI::FIDELITY,'The rules below apply only to what the request above does not ask to change.')&&str_contains(OpenAI::FIDELITY,'nothing below may weaken, shrink or undo it')&&str_contains(OpenAI::CLEANED,'blur or any other change the request asks for is made as asked')&&str_contains(OpenAI::FIDELITY,'webs of fine lines')&&!str_contains(end($calls)[1]['prompt'],'blurred on purpose')&&!str_contains(end($calls)[1]['prompt'],OpenAI::CLEANED_REMINDER),'the guard keeps bodies as they are except where the request changes them, and names the web of lines; an image sent as it is carries no cleaning note');
  // The browser cleaned the image before sending it (Limpar antes de enviar): the prompt opens by saying that the blur
  // was put there on purpose and must be undone, at the level it was cleaned at, and ends with a reminder.
  $cleanJob=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($bytes),'source_id'=>$variedImage['id'],'cleaned'=>true,'clean'=>'forte']]],$key);
  check($cleanJob[0]['cleaned']==='forte','the request says at which level the image was cleaned');
  $cleanDone=$jobs->run($cleanJob[0]['id'],$key,$varier);
  check(end($calls)[1]['prompt']===OpenAI::cleanedNotice('forte')."\n\nMake the sky purple\n\n".OpenAI::FIDELITY."\n\n".OpenAI::CLEANED_REMINDER
    &&str_starts_with(OpenAI::cleanedNotice('forte'),'About the input image, before anything else: it was blurred on purpose. Before sending it, we ran a strong smoothing filter')
    &&str_contains(OpenAI::CLEANED,'undo it')&&str_contains(OpenAI::CLEANED,'in full, clean focus')&&str_contains(OpenAI::CLEANED,'Restore focus, not texture'),'an edit of an image cleaned in the browser opens by saying the blur is on purpose and must be undone, and ends with a reminder');
  check($vault->image($cleanDone['image_ids'][0],$key)['cleaned']==='forte','the new image says the image it came from was cleaned, and how much');
  check($jobs->sentPrompt($cleanJob[0]['id'],$key)===end($calls)[1]['prompt']&&$jobs->sentPrompt('nope',$key)===null&&$jobs->sentPrompt(str_repeat('0',32),$key)===null,'the request keeps the prompt exactly as it went to the image API, notice included');
  // Fotorrealista, Melhorar, Ampliar and Expandir put what the person wrote inside a ready-made instruction: the request
  // and its image keep that text apart, to copy on its own (also when nothing was written, and when the request is repeated).
  $typedJob=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>"The user's own request for this image. It has priority over everything else in this prompt:\nolhos mais definidos\n\nRestore",'typed'=>"  olhos mais definidos \n",'label'=>'Melhorada: olhos mais definidos','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['id'=>$variedImage['id']]]],$key);
  $typedDone=$jobs->run($typedJob[0]['id'],$key,$varier);
  check($typedJob[0]['typed']==='olhos mais definidos'&&$typedDone['typed']==='olhos mais definidos'&&$vault->image($typedDone['image_ids'][0],$key)['typed']==='olhos mais definidos','the request and its image keep what the person wrote apart from the whole prompt');
  check($jobs->retry(bin2hex(random_bytes(16)),$typedJob[0]['id'],null,$key)[0]['typed']==='olhos mais definidos','a repeated request keeps what the person wrote');
  $untypedJob=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Turn this image into a photograph','typed'=>'','label'=>'Fotorrealista','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['id'=>$variedImage['id']]]],$key);
  $untypedDone=$jobs->run($untypedJob[0]['id'],$key,$varier);
  check($untypedJob[0]['typed']===''&&$vault->image($untypedDone['image_ids'][0],$key)['typed']==='','an empty text says that nothing was written');
  check($cleanJob[0]['typed']===null&&!array_key_exists('typed',$vault->image($cleanDone['image_ids'][0],$key)),'a plain edit keeps no separate text: its prompt is what was written');
  $badTyped=false;try{$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'x','typed'=>['x'],'options'=>[]],$key);}catch(HttpError $e){$badTyped=$e->status===422;}
  check($badTyped,'a written text that is not a string is refused');
  // Words that make the model invent texture stay out of the notice: it asks for focus, not for detail.
  check(!preg_match('/\b(crisp|sharpen|fine detail|visible pores|more detail)\b/i',OpenAI::CLEANED.OpenAI::CLEANED_REMINDER.OpenAI::CLEANED_PLANNING)&&str_contains(OpenAI::CLEANED,'A depth of field the picture already has'),'the notice asks for focus without asking for texture, and keeps a depth of field the picture has');
  $levelless=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($bytes),'source_id'=>$variedImage['id'],'cleaned'=>true,'clean'=>['x']]]],$key);
  check($levelless[0]['cleaned']==='media','a cleaned image without a valid level counts as medium');
  $plainJob=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($bytes),'source_id'=>$variedImage['id'],'cleaned'=>'yes']]],$key);
  $plainDone=$jobs->run($plainJob[0]['id'],$key,$varier);
  check(end($calls)[1]['prompt']==="Make the sky purple\n\n".OpenAI::FIDELITY&&$plainJob[0]['cleaned']===null&&$vault->image($plainDone['image_ids'][0],$key)['cleaned']===null,'only a reference marked as cleaned adds the note');
  // A Limpar version is blurred on purpose too: editing it, with "Limpar antes de enviar" off, says so at its own level.
  $limpa=$vault->cleanedImage($bytes,$variedImage['id'],'leve',$key);
  $fromLimpa=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$limpa['id'],'operation'=>'edit','references'=>[['id'=>$limpa['id']]]],$key);
  $jobs->run($fromLimpa[0]['id'],$key,$varier);
  check($fromLimpa[0]['cleaned']==='leve'&&str_starts_with(end($calls)[1]['prompt'],OpenAI::cleanedNotice('leve')."\n\n")&&str_contains(OpenAI::cleanedNotice('leve'),'a light smoothing filter'),'an edit of a Limpar version says its blur is on purpose');
  $twice=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$limpa['id'],'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($bytes),'source_id'=>$limpa['id'],'cleaned'=>true,'clean'=>'media']]],$key);
  check($twice[0]['cleaned']==='media','a Limpar version cleaned again before sending counts at the stronger level');
  $asReference=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['id'=>$variedImage['id']],['id'=>$limpa['id']]]],$key);
  $generated=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'A new scene like @1','options'=>['previews'=>0,'thinking'=>'off'],'references'=>[['id'=>$limpa['id']]]],$key);
  $expanded=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Expand','options'=>['previews'=>0,'thinking'=>'off'],'parent_id'=>$limpa['id'],'operation'=>'expand','references'=>[['id'=>$limpa['id']]]],$key);
  check($asReference[0]['cleaned']===null&&$generated[0]['cleaned']===null&&$expanded[0]['cleaned']===null,'only the image being changed counts, and an expansion, which keeps what is outside its new areas, carries no notice');
  // With planning on, the planner is told not to describe the blur, and the image prompt still opens with the notice.
  $plannerCalls=[];$plannedClean=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Make the sky purple','options'=>['previews'=>0,'thinking'=>'low'],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($bytes),'source_id'=>$variedImage['id'],'cleaned'=>true,'clean'=>'media']]],$key);
  $jobs->run($plannedClean[0]['id'],$key,$planner);
  check($plannerCalls[0][1]['input'][0]['content'][0]['text']==="Make the sky purple\n\n".OpenAI::CLEANED_PLANNING&&$plannerCalls[1][1]['prompt']===OpenAI::cleanedNotice('media')."\n\nPrepared visual prompt\n\n".OpenAI::FIDELITY."\n\n".OpenAI::CLEANED_REMINDER,'the planner hears about the blur, and the planned prompt opens with the notice');
  $upscales=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Upscale','options'=>['n'=>2,'vary'=>true],'parent_id'=>$variedImage['id'],'operation'=>'upscale','references'=>[['id'=>$variedImage['id']]]],$key);
  check($upscales[1]['variation']===null,'upscales and expansions are never varied');
  // One line per image: each line is that image's direction as written, with no planning call.
  $planBefore=count($planCalls);
  $written=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'This character','options'=>['n'=>1,'previews'=>0],'parent_id'=>$variedImage['id'],'operation'=>'edit','references'=>[['id'=>$variedImage['id']]],'lines'=>[' sitting  on the beach ','','running','from behind']],$key);
  check(count($written)===3&&array_column(array_column($written,'variation'),'label')===['sitting on the beach','running','from behind']&&$written[0]['variation']['written']===true&&$written[0]['variation']['planned']===false,'each written line becomes one image');
  $jobs->run($written[1]['id'],$key,$varier);
  check(str_starts_with(end($calls)[1]['prompt'],"This character\n\nDirection for this image: running\n\n")&&count($planCalls)===$planBefore,'a written line reaches the model as written, without planning');
  $writtenAgain=$jobs->retry(bin2hex(random_bytes(16)),$written[0]['id'],3,$key);
  check(array_column(array_column($writtenAgain,'variation'),'label')===['sitting on the beach','running','from behind'],'repeating a written batch keeps its lines');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'X','lines'=>['only one','  ']],$key),'one line is not a batch');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'X','options'=>['n'=>1],'parent_id'=>$variedImage['id'],'operation'=>'upscale','references'=>[['id'=>$variedImage['id']]],'lines'=>['a','b']],$key),'upscales take no lines');
  // Planning that fails falls back to fixed directions instead of failing the images.
  $fallbackId=bin2hex(random_bytes(16));$fallback=$jobs->create(['id'=>$fallbackId,'prompt'=>'A blue owl','options'=>['n'=>2,'previews'=>0,'vary'=>true]],$key);
  $fallbackDone=[];foreach($fallback as $job){$fallbackDone[]=$jobs->run($job['id'],$key,$api);$prompts[]=end($calls)[1]['prompt'];}
  check(array_column($fallbackDone,'status')===['completed','completed']&&end($calls)[1]['prompt']==="A blue owl\n\nDirection for this image: Choose a different composition and camera angle than the most obvious one. Keep everything the request specifies exactly as specified; vary only what it leaves open.\n\n".OpenAI::singleImage(2)&&$fallbackDone[0]['variation']['label']==='prompt como escrito','failed planning falls back to fixed directions');
  check(!str_contains(end($calls)[1]['prompt'],'stay faithful to the input image'),'new images from a prompt get no fidelity guard');
  // A planner that stopped answering is replaced; a cancelled planner lets another image of the batch plan.
  $staleId=bin2hex(random_bytes(16));$stale=$jobs->create(['id'=>$staleId,'prompt'=>'A green frog','options'=>['n'=>2,'previews'=>0,'vary'=>true]],$key);
  $vault->db->prepare("INSERT INTO plans(id,status,payload,updated) VALUES(?,'planning',NULL,?)")->execute([$staleId,time()-120]);
  $jobs->run($stale[1]['id'],$key,$varier);check(str_ends_with(end($calls)[1]['prompt'],"Scene 2 at night\n\n".OpenAI::singleImage(2)),'a stalled planning is taken over');
  $cancelPlanId=bin2hex(random_bytes(16));$cancelPlan=$jobs->create(['id'=>$cancelPlanId,'prompt'=>'A grey cat','options'=>['n'=>2,'previews'=>0,'vary'=>true]],$key);
  $cancelling=new OpenAI('test',60,function($path,$body,$onPartial,$tick)use($jobs,$cancelPlan,$key){
    $jobs->cancel($cancelPlan[0]['id'],$key);$until=microtime(true)+8;
    while(microtime(true)<$until){$tick();usleep(50000);}
    throw new RuntimeException('cancellation was not noticed');
  });
  $q=$vault->db->prepare('SELECT COUNT(*) FROM plans WHERE id=?');
  check($jobs->run($cancelPlan[0]['id'],$key,$cancelling)['status']==='cancelled'&&$q->execute([$cancelPlanId])&&(int)$q->fetchColumn()===0,'cancelling the planning image frees the plan for the others');
  $jobs->run($cancelPlan[1]['id'],$key,$varier);check(str_ends_with(end($calls)[1]['prompt'],"Scene 2 at night\n\n".OpenAI::singleImage(2)),'another image of the batch plans after a cancel');
  rejects(fn()=>OpenAI::options(['vary'=>'yes']),'vary must be a boolean');
  $offId=bin2hex(random_bytes(16));$jobs->create(['id'=>$offId,'prompt'=>'No previews','options'=>['previews'=>0]],$key);$jobs->run($offId,$key,$api);
  check(!isset(end($calls)[1]['stream']),'previews can be turned off');
  rejects(fn()=>OpenAI::options(['previews'=>4]),'more than 3 previews rejected');

  // Provider errors: moderation blocks are told apart from rejected options, without echoing provider text.
  $blocked=OpenAI::failure(400,json_encode(['error'=>['message'=>'Rejected: private prompt','type'=>'image_generation_user_error','code'=>'moderation_blocked','param'=>null]]));
  check($blocked->getMessage()===OpenAI::moderationMessage()&&!str_contains($blocked->getMessage(),'private prompt'),'moderation block reported as such, without provider text');
  $option=OpenAI::failure(400,json_encode(['error'=>['message'=>'Invalid value: max','type'=>'invalid_request_error','code'=>'invalid_value','param'=>'quality']]));
  check(str_contains($option->getMessage(),'“quality”')&&str_contains($option->getMessage(),'Não foi uma recusa de conteúdo'),'rejected option names the parameter');
  check(!str_contains(OpenAI::failure(400,json_encode(['error'=>['param'=>'<script>']]))->getMessage(),'<script>'),'unexpected provider values are dropped');
  check(str_contains(OpenAI::failure(400,'not json')->getMessage(),'sem informar o motivo'),'unreadable error body stays generic');
  check(str_contains(OpenAI::failure(429,'')->getMessage(),'Limite de uso'),'rate limit message kept');
  // No credit left: insufficient_quota (HTTP 429) and billing codes say so plainly and mark the request for the page.
  $credit=OpenAI::failure(429,json_encode(['error'=>['message'=>'You exceeded your current quota','type'=>'insufficient_quota','code'=>'insufficient_quota','param'=>null]]));
  check($credit->getMessage()===OpenAI::noCreditMessage()&&$credit->kind==='credit'&&!str_contains($credit->getMessage(),'exceeded'),'no credit left is reported as such, without provider text');
  check(OpenAI::failure(400,json_encode(['error'=>['type'=>'invalid_request_error','code'=>'billing_hard_limit_reached']]))->kind==='credit','a reached spending limit counts as no credit');
  $rate=OpenAI::failure(429,json_encode(['error'=>['type'=>'requests','code'=>'rate_limit_exceeded']]));
  check($rate->getMessage()===OpenAI::rateLimitMessage()&&$rate->kind===null,'a rate limit is told apart from no credit');
  $creditId=bin2hex(random_bytes(16));$jobs->create(['id'=>$creditId,'prompt'=>'No credit','options'=>['previews'=>0]],$key);
  $creditJob=$jobs->run($creditId,$key,new OpenAI('test',60,function($path){ if($path==='/responses')return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Plan']]]],'usage'=>['total_tokens'=>9]]; throw OpenAI::failure(429,json_encode(['error'=>['type'=>'insufficient_quota','code'=>'insufficient_quota']])); }));
  check($creditJob['status']==='failed'&&$creditJob['error']===OpenAI::noCreditMessage()&&$creditJob['error_kind']==='credit','a request refused for no credit ends failed and marked as such');
  // Planning only improves the prompt: a refusal, an unfinished answer or a failed call never costs the image.
  foreach (['refused'=>['status'=>'completed','output'=>[['content'=>[['type'=>'refusal','refusal'=>'No']]]],'usage'=>['total_tokens'=>9]],
    'incomplete'=>['status'=>'incomplete','output'=>[]],'failed'=>null] as $reason=>$answer) {
    $sent=[];$planning=new OpenAI('test',60,function($path,$body)use($answer,$bytes,&$sent){
      if($path==='/responses'){if($answer===null)throw new HttpError(502,'Simulated planning failure');return $answer;}
      $sent[]=$body['prompt'];return ['data'=>[['b64_json'=>base64_encode($bytes)]]];});
    $skipId=bin2hex(random_bytes(16));$jobs->create(['id'=>$skipId,'prompt'=>"Plan this $reason",'options'=>['thinking'=>'low']],$key);
    $skipJob=$jobs->run($skipId,$key,$planning);$skipImage=$vault->image($skipJob['image_ids'][0]??str_repeat('0',32),$key);
    check($skipJob['status']==='completed'&&$sent===["Plan this $reason"],"planning $reason: the image is made from the prompt as written");
    check($skipImage['planning_skipped']===$reason&&$skipImage['planned_prompt']===null,"planning $reason: the image records why it was not planned");
  }

  // Accounts: each one has its own key and library; nothing of one is visible or usable from another.
  $workKey=random_bytes(32);$work=$users->create('Trabalho','work-password-long-enough',$workKey);
  check(!$work['admin']&&$users->unwrap($work,'work-password-long-enough')===$workKey,'new account opens with its own password');
  rejects(fn()=>$users->unwrap($work,'test-password-long-enough'),'another account password does not open it');
  rejects(fn()=>$users->create('trabalho','another-password-long',random_bytes(32)),'names are unique regardless of case');
  rejects(fn()=>$users->create('a/b','another-password-long',random_bytes(32)),'names with path characters rejected');
  rejects(fn()=>$users->create('Curta','short',random_bytes(32)),'short passwords rejected for new accounts');
  $mine=$vault->gallery($key,'',false,false,0,300)['total'];$myJob=$jobs->list($key,'history')[0]['id'];
  $vault->useOwner($work['id']);
  check($vault->gallery($workKey,'',false,false,0)['total']===0&&$jobs->list($workKey,'history')===[]&&$jobs->list($workKey,'feed')===[],'a new account starts with an empty library and no requests');
  check($vault->readerPrefs($workKey)===null,'each account has its own reader settings');
  rejects(fn()=>$vault->image($root['id'],$workKey),'images of another account are not found');
  rejects(fn()=>$vault->bytes($root['id'],'full',$workKey),'files of another account cannot be read');
  rejects(fn()=>$vault->mark($root['id'],'trash'),'images of another account cannot be trashed');
  rejects(fn()=>$jobs->cancel($myJob,$workKey),'requests of another account cannot be cancelled');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Edit theirs','parent_id'=>$root['id'],'references'=>[['id'=>$root['id']]],'options'=>[]],$workKey),'images of another account cannot be edited or used as references');
  rejects(fn()=>$jobs->create(['id'=>$myJob,'prompt'=>'Reuse an id','options'=>[]],$workKey),'request ids of another account cannot be taken over');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'No key yet','options'=>[]],$workKey),'each account needs its own API key');
  $vault->setApiKey('sk-work-placeholder-for-unit-tests',$workKey);
  check($vault->apiKey($workKey)==='sk-work-placeholder-for-unit-tests','API keys are stored per account');
  $workJob=bin2hex(random_bytes(16));$jobs->create(['id'=>$workJob,'prompt'=>'Work image','options'=>['previews'=>0]],$workKey);
  $workImage=$jobs->run($workJob,$workKey,$api)['image_ids'][0];
  check($vault->gallery($workKey,'',false,false,0)['total']===1,'images go to the library of the account that asked');
  $vault->useOwner($owner['id']);
  check($vault->apiKey($key)==='sk-test-placeholder-for-unit-tests'&&$vault->gallery($key,'',false,false,0,300)['total']===$mine,'the first library is unchanged');
  rejects(fn()=>$jobs->run($workJob,$key,$api),'requests of another account cannot be run');
  $users->rename($work['id'],'Trabalho 2026');rejects(fn()=>$users->rename($work['id'],'pessoal'),'renaming to a taken name rejected');
  check($users->findByName('trabalho 2026')['id']===$work['id'],'accounts can be renamed');
  $epoch=$work['epoch'];$users->setPassword($work['id'],$workKey,'changed-work-password');
  $changed=$users->find($work['id']);check($changed['epoch']!==$epoch&&$users->unwrap($changed,'changed-work-password')===$workKey,'a password change rewraps the same key and ends other sessions');

  // MCP tokens and download links.
  $token=$users->issueToken($work['id'],$workKey);
  [$tokenUser,$tokenKey]=$users->resolveToken($token);
  check($tokenUser['id']===$work['id']&&$tokenKey===$workKey&&!str_contains((string)$users->find($work['id'])['mcp_key'],$workKey),'an MCP token unlocks only its account');
  check($users->resolveToken(substr($token,0,-2).(str_ends_with($token,'AA')?'AB':'AA'))===null&&$users->resolveToken('igm_x')===null,'a wrong token secret is refused');
  $second=$users->issueToken($work['id'],$workKey);
  check($users->resolveToken($token)===null&&$users->resolveToken($second)!==null,'a new token replaces the old one');
  $users->revokeToken($work['id']);check($users->resolveToken($second)===null,'a revoked token stops working');
  $vault->useOwner($work['id']);$link=$vault->createLink($workImage,$workKey);
  $vault->useOwner($owner['id']);[$linked,$linkedBytes]=$vault->openLink($link);
  check($linked['id']===$workImage&&$linkedBytes===$bytes&&$vault->owner()===$work['id'],'a download link opens its own image without a session');
  rejects(fn()=>$vault->openLink(substr($link,0,33).str_repeat('A',43)),'a download link with a wrong secret is refused');
  $vault->db->prepare('UPDATE links SET expires=? WHERE id=?')->execute([time()-1,substr($link,0,32)]);
  rejects(fn()=>$vault->openLink($link),'an expired download link is refused');
  $vault->useOwner($owner['id']);rejects(fn()=>$vault->createLink($workImage,$key),'links can only be made for images of the account');

  // The MCP server: protocol, generation with the account's library and key, edits as versions, upscale sizes.
  $vault->useOwner($work['id']);
  $mcpCalls=[];$mcpApi=new OpenAI('test',60,function($path,$body)use(&$mcpCalls,$bytes){$mcpCalls[]=[$path,$body];return ['data'=>[['b64_json'=>base64_encode($bytes)]],'usage'=>['total_tokens'=>10]];});
  $mcp=new Mcp($vault,$jobs,$workKey,'https://example.test/studio/',['api_timeout'=>60],$mcpApi);
  $rpc=fn(string $method,array $params=[],$id=1)=>$mcp->handle(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params]);
  $tool=function(string $name,array $arguments)use($rpc){$r=$rpc('tools/call',['name'=>$name,'arguments'=>$arguments]);$r['result']['data']=json_decode($r['result']['content'][0]['text'],true);return $r['result'];};
  $init=$rpc('initialize',['protocolVersion'=>'2025-06-18','capabilities'=>new stdClass(),'clientInfo'=>['name'=>'test','version'=>'1']]);
  check($init['result']['protocolVersion']==='2025-06-18'&&isset($init['result']['capabilities']['tools'])&&$init['result']['serverInfo']['name']==='atelier'&&$init['result']['serverInfo']['version']===Studio\VERSION,'MCP initialize answers with the requested protocol and the app\'s version');
  check($rpc('initialize',['protocolVersion'=>'1999-01-01'])['result']['protocolVersion']==='2025-06-18','unknown protocol versions get a supported one');
  check($mcp->handle(['jsonrpc'=>'2.0','method'=>'notifications/initialized'])===null&&$rpc('nope')['error']['code']===-32601&&$mcp->handle(['id'=>1])['error']['code']===-32600,'notifications get no answer; unknown methods and invalid messages get JSON-RPC errors');
  $names=array_column($rpc('tools/list')['result']['tools'],'name');
  check(array_slice($names,0,7)===['generate_image','edit_image','upscale_image','list_images','get_image','import_image','check_requests']&&in_array('write_chapter',$names,true),'MCP lists the image tools, then the stories');
  check(json_encode(Mcp::tools())!==false&&array_reduce(Mcp::tools(),fn($ok,$t)=>$ok&&$t['inputSchema']['type']==='object',true),'tool schemas are JSON objects');
  check($rpc('tools/call',['name'=>'delete_everything','arguments'=>[]])['error']['code']===-32602,'unknown tools are refused');
  $generated=$tool('generate_image',['prompt'=>'A lighthouse at dusk','size'=>'16:9','quality'=>'medium','title'=>'Farol']);
  $gimg=$generated['data']['requests'][0]['images'][0];
  check(empty($generated['isError'])&&$generated['data']['requests'][0]['status']==='completed'&&end($mcpCalls)[1]['size']==='1536x864'&&end($mcpCalls)[1]['quality']==='medium'&&!isset(end($mcpCalls)[1]['stream']),'generate_image runs the request with the given ratio and quality, without paid previews');
  check($generated['content'][1]['type']==='image'&&$generated['content'][1]['mimeType']==='image/jpeg'&&getimagesizefromstring(base64_decode($generated['content'][1]['data']))!==false,'generated images come back as previews');
  check($gimg['title']==='Farol'&&str_starts_with($gimg['download_url'],'https://example.test/studio/mcp.php?file=')&&$vault->openLink(substr($gimg['download_url'],strpos($gimg['download_url'],'=')+1))[1]===$bytes,'generated images come with a working download link');
  $vault->useOwner($work['id']);
  check($vault->gallery($workKey,'Farol',false,false,0)['total']===1,'MCP images are saved to the token account library');
  $edited=$tool('edit_image',['image_id'=>$gimg['id'],'prompt'=>'Add a boat','reference_image_ids'=>[$workImage]]);
  $eimg=$edited['data']['requests'][0]['images'][0];
  check($eimg['parent_id']===$gimg['id']&&$eimg['operation']==='edit'&&end($mcpCalls)[0]==='/images/edits'&&count(end($mcpCalls)[1]['images'])===2&&end($mcpCalls)[1]['size']==='944x704','edit_image saves a version of the source with its references, keeping its proportions');
  $vault->useOwner($owner['id']);$foreign=$root['id'];$vault->useOwner($work['id']);
  check(!empty($tool('edit_image',['image_id'=>$foreign,'prompt'=>'Steal it'])['isError']),'MCP cannot reach images of another account');
  check(!empty($tool('upscale_image',['image_id'=>$gimg['id'],'scale'=>2])['isError']),'upscale_image refuses a scale the proportions do not allow');
  $up=$tool('upscale_image',['image_id'=>$gimg['id'],'scale'=>'max','instructions'=>'sharper']);
  check($up['data']['requests'][0]['images'][0]['operation']==='upscale'&&$up['data']['requests'][0]['images'][0]['parent_id']===$gimg['id']&&end($mcpCalls)[1]['size']==='3312x2480'&&str_ends_with(end($mcpCalls)[1]['prompt'],"Requested improvements: sharper\n\n".OpenAI::FIDELITY),'upscale_image recreates the image at a larger size with the same proportions');
  check(Mcp::upscaleSizes(1024,1024)===['1.5'=>[1536,1536],'2'=>[2048,2048],'max'=>[2880,2880]]&&Mcp::upscaleSizes(3840,2160)===[]&&Mcp::editSize(128,96,true)==='944x704'&&Mcp::editSize(1536,864,false)==='1536x1024'&&Mcp::editSize(1000,3100,true)==='auto','upscale and edit sizes match the studio');
  check(!empty($tool('generate_image',['prompt'=>'Bad size','size'=>'1080x1920'])['isError'])&&!empty($tool('generate_image',['prompt'=>''])['isError'])&&!empty($tool('generate_image',['prompt'=>'Too many','n'=>9])['isError']),'invalid arguments come back as tool errors');
  $failing=new Mcp($vault,$jobs,$workKey,'https://example.test/',['api_timeout'=>60],$fail);
  $failed=$failing->handle(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call','params'=>['name'=>'generate_image','arguments'=>['prompt'=>'Will fail']]])['result'];
  check($failed['isError']===true&&str_contains($failed['content'][0]['text'],'Simulated upstream failure'),'provider failures are reported as tool errors');
  $listed=$tool('list_images',['query'=>'lighthouse','limit'=>5]);
  check($listed['data']['total']===1&&$listed['data']['images'][0]['id']===$gimg['id'],'list_images searches the library');
  $got=$tool('get_image',['image_id'=>$eimg['id']]);
  check($got['data']['prompt']==='Add a boat'&&count($got['data']['versions'])===3&&$got['content'][1]['type']==='image','get_image returns the prompt, the versions and a preview');
  check(count($tool('get_image',['image_id'=>$eimg['id'],'include_preview'=>false])['content'])===1,'previews can be left out');
  $imported=$tool('import_image',['data'=>base64_encode($bytes),'name'=>'logo.png']);
  check($imported['data']['title']==='logo'&&$imported['data']['operation']==='import','import_image accepts plain base64');
  check(!empty($tool('import_image',['data'=>base64_encode('not an image')])['isError']),'import_image refuses data that is not an image');
  $checked=$tool('check_requests',[]);
  check(count($checked['data']['requests'])>=4&&$checked['data']['requests'][0]['status']==='failed','check_requests lists recent requests');
  $lined=$tool('edit_image',['image_id'=>$gimg['id'],'prompt'=>'Same scene','lines'=>['at noon','in a storm']]);
  check(empty($lined['isError'])&&count($lined['data']['requests'])===2&&str_contains(end($mcpCalls)[1]['prompt'],"Same scene\n\nDirection for this image: in a storm"),'edit_image makes one image per line');
  check(!empty($tool('generate_image',['prompt'=>'X','lines'=>['a','b','c','d','e']])['isError']),'MCP takes at most 4 lines');
  $waited=null;$taken=new Mcp($vault,$jobs,$workKey,'https://example.test/',['api_timeout'=>60],$mcpApi);
  $busyId=bin2hex(random_bytes(16));$jobs->create(['id'=>$busyId,'prompt'=>'Queued behind others','options'=>['previews'=>0]],$workKey);
  $vault->db->prepare("UPDATE jobs SET status='running',updated=? WHERE id=?")->execute([time(),$busyId]);
  check($jobs->runAndWait($busyId,$workKey,$mcpApi,null,time()+1)['status']==='running','a request run elsewhere is waited for, not run twice');

  // Videos: sent in pieces, kept encrypted in chunks, read back by byte range. Frames saved from them are images.
  $vault->useOwner($work['id']);
  $clip=file_get_contents(__DIR__.'/fixtures/frames.mp4');$long=$clip.str_repeat("\x00\x01\x02",1100000);
  $chunk=Vault::VIDEO_CHUNK;$length=strlen($long);
  rejects(fn()=>$vault->startUpload($length,0),'video uploads can be turned off');
  rejects(fn()=>$vault->startUpload($length,$length-1),'a video over the size limit is refused before it is sent');
  $upload=$vault->startUpload($length,10*$chunk);
  rejects(fn()=>$vault->appendUpload($upload,0,str_repeat('not a video ',100),$workKey),'a file that is not MP4, MOV or WebM is refused at the first piece');
  rejects(fn()=>$vault->appendUpload($upload,$chunk/2,substr($long,0,$chunk),$workKey),'pieces start at a chunk boundary');
  check($vault->appendUpload($upload,0,substr($long,0,2*$chunk),$workKey)===2*$chunk&&$vault->appendUpload($upload,0,substr($long,0,2*$chunk),$workKey)===2*$chunk,'a piece sent twice is stored once');
  check($vault->appendUpload($upload,3*$chunk,substr($long,3*$chunk),$workKey)===2*$chunk,'a piece after a gap is ignored and the answer says where to continue');
  rejects(fn()=>$vault->finishUpload($upload,['name'=>'clip.mp4','duration'=>2,'width'=>320,'height'=>180],'',$workKey),'an incomplete upload cannot be finished');
  check($vault->appendUpload($upload,2*$chunk,substr($long,2*$chunk),$workKey)===$length,'the upload continues from where the server says');
  $im=imagecreatetruecolor(64,36);ob_start();imagejpeg($im);$posterBytes=ob_get_clean();imagedestroy($im);
  $video=$vault->finishUpload($upload,['name'=>'clip.mp4','duration'=>2,'width'=>320,'height'=>180,'fps'=>12,'frames'=>24],'data:image/jpeg;base64,'.base64_encode($posterBytes),$workKey);
  check($video['id']===$upload&&$video['kind']==='video'&&$video['mime']==='video/mp4'&&$video['size']===$length&&$video['title']==='clip'&&$video['fps']==12&&$video['frames']===24&&$video['poster_mime']==='image/jpeg','a finished upload becomes a library video with its details');
  check($vault->finishUpload($upload,[],'',$workKey)['id']===$upload&&$vault->bytes($upload,'full',$workKey)===$posterBytes,'finishing again is harmless; the poster is the item image');
  $readRange=function(int $first,int $last)use($vault,$video,$workKey){$out='';$vault->streamVideo($video,$first,$last,$workKey,function($piece)use(&$out){$out.=$piece;return true;});return $out;};
  check($readRange(0,$length-1)===$long&&$readRange($chunk-3,$chunk+3)===substr($long,$chunk-3,7)&&$readRange($length-10,$length-1)===substr($long,-10),'byte ranges are read across chunk boundaries');
  check(!str_contains(file_get_contents($dir."/blobs/$upload-video.enc"),substr($clip,0,64))&&!glob($dir."/blobs/$upload-video.part"),'the video is ciphertext on disk');
  $sealed=file_get_contents($dir."/blobs/$upload-video.enc");$swapped=substr($sealed,$chunk+40,$chunk+40).substr($sealed,0,$chunk+40).substr($sealed,2*($chunk+40));
  file_put_contents($dir."/blobs/$upload-video.enc",$swapped);rejects(fn()=>$readRange(0,10),'chunks cannot be reordered');file_put_contents($dir."/blobs/$upload-video.enc",$sealed);
  check(Studio\byteRange('bytes=0-',$length,8*$chunk)===[0,$length-1,true]&&Studio\byteRange('bytes=-500',$length,8*$chunk)===[$length-500,$length-1,true]&&Studio\byteRange(null,$length,8*$chunk)===[0,$length-1,false]&&Studio\byteRange('bytes=5-',20*$chunk,8*$chunk)===[5,8*$chunk+4,true],'Range headers: open ranges are capped, suffix ranges count from the end');
  rejects(fn()=>Studio\byteRange("bytes=$length-",$length,8*$chunk),'a range past the end is refused');
  check(Studio\videoPieceBytes('8M')===7*$chunk&&Studio\videoPieceBytes('2G')===8*$chunk&&Studio\videoPieceBytes('0')===8*$chunk&&Studio\videoPieceBytes('1M')===null,'upload pieces fit under post_max_size');
  $frame=$vault->importImage($bytes,'clip · frame 4',$workKey,['video_id'=>$upload,'frame'=>3,'time'=>0.25]);
  $listedFrames=$vault->frames($upload,$workKey);
  check($frame['operation']==='frame'&&count($listedFrames)===1&&$listedFrames[0]['id']===$frame['id']&&$listedFrames[0]['source']===['video_id'=>$upload,'frame'=>3,'time'=>0.25],'frames saved from a video are listed with it');
  check($vault->importImage($bytes,'x',$workKey,['video_id'=>$foreign,'frame'=>1,'time'=>0.1])['source']['video_id']===null,'a frame cannot point at a video of another account');
  rejects(fn()=>$vault->importImage($bytes,'x',$workKey,['video_id'=>$upload,'frame'=>'3','time'=>0.25]),'frame positions are validated');
  $vault->mark($upload,'trash');$trashedLink=$vault->detail($frame['id'],$workKey)['source']['video_id'];$vault->mark($upload,'restore');
  check($trashedLink===null&&$vault->detail($frame['id'],$workKey)['source']['video_id']===$upload,'a frame links back to its video only while the video is in the library');
  rejects(fn()=>$vault->still($upload,$workKey),'a video is not edited directly');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Edit the video','parent_id'=>$upload,'options'=>[]],$workKey),'a video cannot be the source of an edit');
  rejects(fn()=>$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Use the video','references'=>[['id'=>$upload]],'options'=>[]],$workKey),'a video cannot be a reference');
  check(array_column($vault->gallery($workKey,'',false,false,0,300,false,'video')['items'],'id')===[$upload]&&!in_array($upload,array_column($vault->gallery($workKey,'',false,false,0,300,false,'image')['items'],'id'),true),'the gallery filters videos and images');
  check(!empty($tool('get_image',['image_id'=>$upload])['isError'])&&!empty($tool('upscale_image',['image_id'=>$upload,'scale'=>'max'])['isError'])&&!in_array($upload,array_column($tool('list_images',['limit'=>50])['data']['images'],'id'),true),'MCP tools leave videos out');
  $pending=$vault->startUpload(5*$chunk,10*$chunk);$vault->appendUpload($pending,0,substr($long,0,$chunk),$workKey);
  $aborted=$vault->startUpload(5*$chunk,10*$chunk);$vault->abortUpload($aborted);
  check(!glob($dir."/blobs/$aborted-*")&&(int)$vault->db->query("SELECT COUNT(*) FROM uploads WHERE id='$aborted'")->fetchColumn()===0,'a cancelled upload leaves nothing behind');
  $vault->useOwner($owner['id']);rejects(fn()=>$vault->appendUpload($pending,$chunk,substr($long,$chunk,$chunk),$key),'uploads of another account cannot be continued');
  rejects(fn()=>$vault->video($upload,$key),'videos of another account cannot be played');

  // Removing an account removes its library, files and links.
  $vault->useOwner($work['id']);$workStories=new Stories($vault);
  $workStory=$workStories->create('História da conta removida',$workKey);$workStories->addChapter($workStory['id'],'Um','Texto do capítulo.','Resumo.',null,'person',$workKey);
  $vault->useOwner($owner['id']);
  $workFiles=glob($dir.'/blobs/'.$workImage.'-*.enc');
  $workVideo=glob($dir.'/blobs/'.$upload.'-*');
  $users->delete($work['id']);
  check(count($workVideo)===3&&!glob($dir.'/blobs/'.$upload.'-*')&&!glob($dir.'/blobs/'.$pending.'-*')&&(int)$vault->db->query("SELECT COUNT(*) FROM uploads WHERE user_id='{$work['id']}'")->fetchColumn()===0,'deleting an account removes its videos and unfinished uploads');
  check($users->find($work['id'])===null&&(int)$vault->db->query("SELECT COUNT(*) FROM images WHERE user_id='{$work['id']}'")->fetchColumn()===0&&count($workFiles)===2&&!glob($dir.'/blobs/'.$workImage.'-*.enc'),'deleting an account removes its images and files');
  check(array_sum(array_map(fn($t)=>(int)$vault->db->query("SELECT COUNT(*) FROM $t WHERE user_id='{$work['id']}'")->fetchColumn(),['stories','chapters','story_messages','story_turns','comic_pages','vn_scenes','story_inbox']))===0,'deleting an account removes its stories, chapters and conversations');
  check((int)$vault->db->query("SELECT COUNT(*) FROM jobs WHERE user_id='{$work['id']}'")->fetchColumn()===0&&(int)$vault->db->query("SELECT COUNT(*) FROM links WHERE user_id='{$work['id']}'")->fetchColumn()===0,'deleting an account removes its requests and links');
  $vault->useOwner($owner['id']);
  check($vault->gallery($key,'',false,false,0,300)['total']===$mine,'other libraries survive an account removal');

  // Library management: the whole view at once, orders, origins, bulk actions, albums and the custom order.
  $libKey=random_bytes(32);$lib=$users->create('Biblioteca','library-password-long',$libKey);$vault->useOwner($lib['id']);
  $make=function(int $w,int $h,string $title,string $operation,int $created)use($vault,$libKey){
    $im=imagecreatetruecolor($w,$h);ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
    $item=$operation==='import'?$vault->importImage($png,$title,$libKey):$vault->saveImage($png,['title'=>$title,'prompt'=>"prompt $title",'original_prompt'=>'','options'=>OpenAI::options([]),'parent_ids'=>[],'parent_id'=>null,'operation'=>$operation,'label'=>null],$libKey);
    $vault->db->prepare('UPDATE images SET created=? WHERE id=?')->execute([$created,$item['id']]);return $item['id'];};
  $t=1700000000;$wide=$make(64,32,'Zebra','generate',$t+10);$tall=$make(32,64,'água.png','import',$t+5);$big=$make(96,96,'Barco 10','generate',$t+1);$small=$make(16,16,'barco 9','import',$t);
  $ids=fn(array $view)=>array_column($vault->library($libKey,$view)['items'],0);
  check($ids([])===[$wide,$tall,$big,$small]&&$ids(['sort'=>'oldest'])===[$small,$big,$tall,$wide],'the library lists the whole view at once, newest or oldest first');
  check($vault->library($libKey,[])['items'][0]===[$wide,$t+10,64,32,0,1,0]&&$vault->library($libKey,[])['items'][1][4]===4,'library rows carry layout data and flags, no prompts');
  check($ids(['sort'=>'largest'])===[$big,$wide,$tall,$small]&&$ids(['sort'=>'smallest'])===[$small,$wide,$tall,$big],'orders by resolution, newest first among equals');
  check($ids(['sort'=>'wide'])===[$wide,$big,$small,$tall]&&$ids(['sort'=>'tall'])===[$tall,$big,$small,$wide],'orders by shape');
  check($ids(['search'=>'barco'])===[$big]&&$ids(['search'=>'água'])===[]&&$ids(['search'=>'PROMPT zebra'])===[$wide],'the search looks at prompts, not at the names files and the generator gave');
  check($ids(['origin'=>'generated'])===[$wide,$big]&&$ids(['origin'=>'imported'])===[$tall,$small]&&$ids(['origin'=>'video'])===[],'imported and generated items are listed apart');
  $captions=array_column($vault->captions([$small,$wide,str_repeat('0',32)],$libKey),'prompt','id');ksort($captions);$expected=[$wide=>'prompt Zebra',$small=>''];ksort($expected);
  check($captions===$expected&&!array_key_exists('title',$vault->captions([$wide],$libKey)[0]),'captions are read only for the items asked, without names');
  rejects(fn()=>$vault->bulk('favorite',['../x'],null),'bulk actions validate every id');
  check($vault->bulk('favorite',[$wide,$small,$wide])===2&&$ids(['favorites'=>true])===[$wide,$small]&&$vault->bulk('unfavorite',[$small])===1,'favorites many at once');
  check($vault->bulk('trash',[$tall,$big])===2&&$ids([])===[$wide,$small]&&$ids(['trash'=>true])===[$tall,$big]&&$vault->bulk('restore',[$tall])===1,'trash and restore many at once');
  $bigFiles=glob($dir."/blobs/$big-*");
  check($vault->bulk('purge',[$big,$wide])===1&&count($bigFiles)===2&&!glob($dir."/blobs/$big-*")&&$vault->image($wide,$libKey)['id']===$wide,'purging many removes only trashed items and their files');
  $album=$vault->createAlbum('  Viagem   2026 ',$libKey);
  check($album['name']==='Viagem 2026'&&!str_contains((string)$vault->db->query("SELECT meta FROM albums WHERE id='{$album['id']}'")->fetchColumn(),'Viagem'),'album names are tidied and encrypted');
  check($vault->bulk('album-add',[$wide,$tall,$wide],$album['id'])===2&&$vault->bulk('album-add',[$wide],$album['id'])===0&&$ids(['album'=>$album['id']])===[$wide,$tall],'items are added to an album once');
  $listed=$vault->albums($libKey);check(count($listed)===1&&$listed[0]['count']===2&&$listed[0]['cover']===$wide,'albums list their size and newest item');
  $vault->bulk('trash',[$wide]);check($vault->albums($libKey)[0]['count']===1&&$vault->albums($libKey)[0]['cover']===$tall&&$ids(['album'=>$album['id'],'trash'=>true])===[$wide],'trashed items leave the album count but stay members');
  $vault->bulk('restore',[$wide]);$vault->renameAlbum($album['id'],'Férias',$libKey);
  check($vault->bulk('album-remove',[$tall],$album['id'])===1&&$ids(['album'=>$album['id']])===[$wide]&&$vault->albums($libKey)[0]['name']==='Férias'&&$vault->image($tall,$libKey)['id']===$tall,'removing from an album keeps the item; albums can be renamed');
  rejects(fn()=>$vault->createAlbum(str_repeat('a',81),$libKey),'album names are limited');
  check($ids(['sort'=>'custom'])===[$wide,$tall,$small],'the custom order starts as newest first');
  $vault->reorder([$small],$wide);check($ids(['sort'=>'custom'])===[$small,$wide,$tall],'items move before another item');
  $vault->reorder([$small],null);check($ids(['sort'=>'custom'])===[$wide,$tall,$small],'items move to the end');
  $vault->reorder([$tall,$wide],$small);check($ids(['sort'=>'custom'])===[$tall,$wide,$small],'several items move together in the given order');
  $vault->reorder([$wide,$small],$wide);check($ids(['sort'=>'custom'])===[$tall,$wide,$small],'a moved item used as anchor keeps the block in place');
  $newest=$make(20,20,'Nova','generate',$t+20);check($ids(['sort'=>'custom'])===[$newest,$tall,$wide,$small]&&$ids([])===[$newest,$wide,$tall,$small],'new items come first in the custom order; the date order ignores it');
  $vault->editMeta($wide,'Zebra 2','x',$libKey);check(!array_key_exists('position',json_decode(Crypto::open((string)$vault->db->query("SELECT meta FROM images WHERE id='$wide'")->fetchColumn(),$libKey,"$wide:meta"),true))&&$ids(['sort'=>'custom'])[2]===$wide,'editing keeps the custom order out of the encrypted metadata');
  // Covers: one version chosen to stand for an image and its edits in the grouped gallery.
  $editOf=function(string $parent,string $title,int $created)use($vault,$libKey){
    $im=imagecreatetruecolor(20,20);ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
    $item=$vault->saveImage($png,['title'=>$title,'prompt'=>"edit $title",'original_prompt'=>'','options'=>OpenAI::options([]),'parent_ids'=>[$parent],'parent_id'=>$parent,'operation'=>'edit','label'=>null],$libKey);
    $vault->db->prepare('UPDATE images SET created=? WHERE id=?')->execute([$created,$item['id']]);return $item['id'];};
  $e1=$editOf($newest,'Nova 2',$t+21);$e2=$editOf($e1,'Nova 3',$t+22);
  check($ids(['group'=>true])===[$e2,$wide,$tall,$small],'grouped, an image shows its newest version');
  $vault->setCover($newest,$libKey);$row=$vault->library($libKey,['group'=>true])['items'][0];
  check($row[0]===$newest&&$row[1]===$t+22&&$row[5]===3&&array_column(array_filter($vault->lineage($e2,$libKey)['nodes'],fn($n)=>$n['cover']),'id')===[$newest],'a chosen cover stands for its versions, with the newest date');
  check($ids(['sort'=>'custom','group'=>true])[0]===$newest&&$ids(['sort'=>'oldest','group'=>true])[3]===$newest,'the cover keeps the image in its place');
  check($ids([])===[$e2,$e1,$newest,$wide,$tall,$small],'without grouping every version is listed');
  $vault->bulk('trash',[$newest]);check($ids(['group'=>true])[0]===$e2,'a trashed cover gives way to the newest version');$vault->bulk('restore',[$newest]);
  $vault->setCover($e1,$libKey);check($ids(['group'=>true])[0]===$e1&&(int)$vault->db->query("SELECT COUNT(*) FROM images WHERE cover<>0")->fetchColumn()===1,'choosing another cover replaces the first');
  $vault->setCover($e1,$libKey,false);check($ids(['group'=>true])[0]===$e2&&(int)$vault->db->query("SELECT COUNT(*) FROM images WHERE cover<>0")->fetchColumn()===0,'the cover can go back to the newest version');
  $vault->editMeta($e1,'Nova 2b','x',$libKey);check(!array_key_exists('cover',json_decode(Crypto::open((string)$vault->db->query("SELECT meta FROM images WHERE id='$e1'")->fetchColumn(),$libKey,"$e1:meta"),true)),'the cover stays out of the encrypted metadata');
  // Favorites and albums hold versions picked one by one: each of them shows, even when versions are grouped.
  $vault->bulk('favorite',[$newest,$e1]);check($ids(['group'=>true,'favorites'=>true])===[$e1,$newest,$wide],'favorites list every favorite version of an image');
  $picked=$vault->createAlbum('Versões',$libKey);$vault->bulk('album-add',[$e2,$newest],$picked['id']);
  check($ids(['group'=>true,'album'=>$picked['id']])===[$e2,$newest],'albums list every version added to them');
  $vault->bulk('unfavorite',[$newest,$e1]);$vault->deleteAlbum($picked['id']);
  // Hidden items leave the library for the Ocultas filter; the reference picker (gallery) and the trash still list them.
  $flags=fn(array $view,string $id)=>array_column($vault->library($libKey,$view)['items'],4,0)[$id]??null;
  check($vault->hide([$e2],true,true,$libKey)===3&&$ids(['group'=>true])===[$wide,$tall,$small]&&$ids(['group'=>true,'hidden'=>true])===[$e2]&&$ids(['hidden'=>true])===[$e2,$e1,$newest],'hiding a grouped image hides all its versions');
  check(($flags(['hidden'=>true],$e1)&32)===32&&$vault->gallery($libKey,'',false,false,0,300)['total']===6&&$vault->lineage($wide,$libKey)['nodes'][0]['hidden']===false,'hidden items keep a flag and still show in the reference picker');
  $e3=$editOf($e2,'Nova 4',$t+23);check($vault->image($e3,$libKey)['hidden']===true&&$ids(['group'=>true])===[$wide,$tall,$small]&&$ids(['group'=>true,'hidden'=>true])===[$e3],'a new version of a hidden image is hidden too');
  check(count(array_filter($vault->lineage($e3,$libKey)['nodes'],fn($n)=>$n['hidden']))===4,'the versions of a hidden image list it as hidden');
  check($vault->hide([$e1],false,false,$libKey)===1&&$ids(['group'=>true])===[$e1,$wide,$tall,$small]&&$ids(['group'=>true,'hidden'=>true])===[$e3],'one version can be shown again on its own');
  check($vault->hide([$e1],false,true,$libKey)===3&&$ids(['hidden'=>true])===[]&&$vault->hide([$e1],false,true,$libKey)===0,'showing a grouped image shows all its versions');
  $kept=$vault->createAlbum('Escondidas',$libKey);$vault->bulk('album-add',[$tall,$small],$kept['id']);$vault->hide([$tall],true,false,$libKey);
  $keptRow=array_values(array_filter($vault->albums($libKey),fn($a)=>$a['id']===$kept['id']))[0];
  check($keptRow['count']===1&&$keptRow['cover']===$small&&$ids(['album'=>$kept['id']])===[$small]&&$ids(['album'=>$kept['id'],'hidden'=>true])===[$tall],'in an album, hidden items show only in the Ocultas filter and are never its cover');
  $vault->bulk('trash',[$tall]);check($ids(['trash'=>true])===[$tall]&&$ids(['trash'=>true,'hidden'=>false])===[$tall],'the trash lists hidden items too');$vault->bulk('restore',[$tall]);
  $vault->hide([$tall],false,false,$libKey);$vault->deleteAlbum($kept['id']);
  $vault->editMeta($e1,'Nova 2c','x',$libKey);$plain=json_decode(Crypto::open((string)$vault->db->query("SELECT meta FROM images WHERE id='$e1'")->fetchColumn(),$libKey,"$e1:meta"),true);
  check(!array_key_exists('hidden',$plain)&&!array_key_exists('stack',$plain),'hiding and stacks stay out of the encrypted metadata');
  // Stacks: piles grouped by hand, shown as one tile like the versions of an image.
  rejects(fn()=>$vault->stack([$e1,$e2],$libKey),'a stack needs two piles at least');
  $pile=$vault->stack([$e3,$wide],$libKey);
  check($pile['count']===5&&strlen($pile['stack'])===32&&$ids(['group'=>true])===[$e3,$tall,$small]&&$ids([])===[$e3,$e2,$e1,$newest,$wide,$tall,$small],'grouping images makes one tile of them and their versions; ungrouped views list them all');
  $row=$vault->library($libKey,['group'=>true])['items'][0];$tree=$vault->lineage($wide,$libKey);
  check(($row[4]&16)===16&&$row[5]===5&&$tree['stack']===$pile['stack']&&count($tree['nodes'])===5&&count(array_filter($tree['nodes'],fn($n)=>$n['parent']===null))===2,'a stack lists every image in it, each lineage a tree of its own');
  $w2=$editOf($wide,'Zebra 3',$t+24);check($ids(['group'=>true])===[$w2,$tall,$small]&&$vault->library($libKey,['group'=>true])['items'][0][5]===6,'a new version of an image in a stack joins the stack');
  $vault->setCover($wide,$libKey);$row=$vault->library($libKey,['group'=>true])['items'][0];
  check($row[0]===$wide&&$row[1]===$t+24&&array_column(array_filter($vault->lineage($e1,$libKey)['nodes'],fn($n)=>$n['cover']),'id')===[$wide],'a stack has one cover, chosen among all its items');
  check($vault->hide([$wide],true,true,$libKey)===6&&$ids(['group'=>true])===[$tall,$small]&&$ids(['group'=>true,'hidden'=>true])===[$wide],'hiding a stack hides everything in it');$vault->hide([$wide],false,true,$libKey);
  $again=$vault->stack([$wide,$tall],$libKey);check($again['stack']===$pile['stack']&&$again['count']===7&&$ids(['group'=>true])===[$wide,$small],'grouping a stack with more images adds them to it');
  check($vault->unstack([$tall],false,$libKey)===1&&$ids(['group'=>true])===[$wide,$tall,$small]&&$vault->library($libKey,['group'=>true])['items'][0][5]===6,'an image can leave its stack');
  check($vault->unstack([$w2],false,$libKey)===6&&(int)$vault->db->query("SELECT COUNT(*) FROM images WHERE stack IS NOT NULL")->fetchColumn()===0&&$ids(['group'=>true])===[$wide,$e3,$tall,$small],'a stack left with one image is undone');
  $vault->stack([$e1,$w2,$small],$libKey);check($vault->unstack([$small],true,$libKey)===7&&$ids(['group'=>true])===[$wide,$e3,$tall,$small],'a whole stack can be undone');
  $vault->setCover($wide,$libKey,false);$vault->bulk('trash',[$e3,$w2]);$vault->bulk('purge',[$e3,$w2]);
  $vault->bulk('trash',[$e1,$e2]);$vault->bulk('purge',[$e1,$e2]);
  $vault->bulk('trash',[$wide]);$vault->bulk('purge',[$wide]);
  check((int)$vault->db->query("SELECT COUNT(*) FROM album_items WHERE image_id='$wide'")->fetchColumn()===0,'purging an item takes it out of its albums');
  $vault->useOwner($owner['id']);
  check($vault->bulk('trash',[$tall])===0&&$vault->captions([$tall],$key)===[],'bulk actions and captions ignore items of other accounts');
  rejects(fn()=>$vault->bulk('album-add',[$root['id']],$album['id']),'albums of another account cannot be used');
  rejects(fn()=>$vault->library($key,['album'=>$album['id']]),'albums of another account cannot be listed');
  check($vault->hide([$tall],true,true,$key)===0&&$vault->unstack([$tall],true,$key)===0,'hiding and stacks ignore items of other accounts');
  rejects(fn()=>$vault->stack([$tall,$small],$key),'items of other accounts cannot be stacked');
  $vault->reorder([$tall],null);$vault->useOwner($lib['id']);check($ids(['sort'=>'custom'])===[$newest,$tall,$small]&&$ids([])===[$newest,$tall,$small],'reordering ignores items of other accounts');
  // An album can keep its items out of the library: they show only in it (and in the favorites, Ocultas, the trash and
  // the reference picker). The owner's own albums show them until the owner says otherwise.
  $apartAlbum=$vault->createAlbum('Só no álbum',$libKey);$vault->bulk('album-add',[$tall],$apartAlbum['id']);
  check($apartAlbum['library']===true&&$apartAlbum['kind']===null&&$ids([])===[$newest,$tall,$small],'an album of the owner shows its items in the library too');
  $vault->setAlbumLibrary($apartAlbum['id'],false);$row=array_values(array_filter($vault->albums($libKey),fn($a)=>$a['id']===$apartAlbum['id']))[0];
  check($row['library']===false&&$row['count']===1&&$ids([])===[$newest,$small]&&$ids(['group'=>true])===[$newest,$small]&&$ids(['origin'=>'imported'])===[$small]&&$ids(['album'=>$apartAlbum['id']])===[$tall],'kept out of the library, its items show only in the album');
  check($vault->gallery($libKey,'',false,false,0,300)['total']===3&&$ids(['search'=>'água'])===[],'the reference picker still lists them; the library\'s search doesn\'t');
  $vault->bulk('favorite',[$tall]);check($ids(['favorites'=>true])===[$tall],'the favorites list them');$vault->bulk('unfavorite',[$tall]);
  $vault->hide([$tall],true,false,$libKey);check($ids(['hidden'=>true])===[$tall],'and so does Ocultas, when hidden');$vault->hide([$tall],false,false,$libKey);
  $tall2=$editOf($tall,'água 2',$t+30);
  check($ids(['album'=>$apartAlbum['id']])===[$tall2,$tall]&&$ids([])===[$newest,$small]&&$ids(['group'=>true])===[$newest,$small],'a new version of an image kept out of the library joins its album, so it stays out too');
  $shown=$vault->createAlbum('Na biblioteca',$libKey);$vault->bulk('album-add',[$small],$shown['id']);$small2=$editOf($small,'barco 9b',$t+31);
  check($ids(['album'=>$shown['id']])===[$small]&&$ids([])===[$small2,$newest,$small],'a new version of an image in an album that shows its items stays only in the library, as before');
  $vault->bulk('album-add',[$small],$apartAlbum['id']);
  check($ids([])===[$small2,$newest]&&$ids(['group'=>true])===[$small2,$newest],'an item kept out by one album stays out even when another album shows it; its version from the library still shows');
  $vault->bulk('album-remove',[$small],$apartAlbum['id']);
  $vault->setAlbumLibrary($apartAlbum['id'],true);check($ids([])===[$small2,$tall2,$newest,$tall,$small],'shown again, its items are back in the library');
  $vault->setAlbumLibrary($apartAlbum['id'],false);$vault->deleteAlbum($apartAlbum['id']);$vault->deleteAlbum($shown['id']);
  check($ids([])===[$small2,$tall2,$newest,$tall,$small],'deleting an album that kept its items out brings them back to the library');
  $vault->bulk('trash',[$small2,$tall2]);$vault->bulk('purge',[$small2,$tall2]);
  $made=$vault->createAlbum('História',$libKey,['kind'=>'story','story'=>str_repeat('5',32)]);
  check($made['library']===false&&$made['kind']==='story'&&!str_contains((string)$vault->db->query("SELECT meta FROM albums WHERE id='{$made['id']}'")->fetchColumn(),'story')
    &&array_values(array_filter($vault->albums($libKey),fn($a)=>$a['id']===$made['id']))[0]['story']===null,'an album a story made keeps its items out of the library from the start, says what made it (sealed), and no story while the story is gone');
  $vault->renameAlbum($made['id'],'História 2',$libKey);check($vault->albumOrigin($made['id'],$libKey)['kind']==='story','renaming keeps what made it');
  $vault->deleteAlbum($made['id']);
  $vault->useOwner($owner['id']);rejects(fn()=>$vault->setAlbumLibrary($album['id'],false),'the albums of another account cannot be changed');$vault->useOwner($lib['id']);
  $vault->deleteAlbum($album['id']);check($vault->albums($libKey)===[]&&$vault->image($tall,$libKey)['id']===$tall,'deleting an album keeps its items');
  $second=$vault->createAlbum('Outro',$libKey);$vault->bulk('album-add',[$tall],$second['id']);
  $users->delete($lib['id']);
  check((int)$vault->db->query("SELECT COUNT(*) FROM albums WHERE user_id='{$lib['id']}'")->fetchColumn()===0&&(int)$vault->db->query("SELECT COUNT(*) FROM album_items WHERE album_id='{$second['id']}'")->fetchColumn()===0,'deleting an account removes its albums');
  $vault->useOwner($owner['id']);

  // The images a request sends live in a file of their own, so the history, the polling and the details read a few KB.
  $vault->useOwner($owner['id']);$jobs=new Jobs($vault,['api_timeout'=>60]);
  $pic=imagecreatetruecolor(64,48);imagefill($pic,0,0,imagecolorallocate($pic,40,90,200));ob_start();imagepng($pic);$picBytes=ob_get_clean();imagedestroy($pic);
  $picItem=$vault->saveImage($picBytes,['title'=>'Fonte','prompt'=>'fonte','original_prompt'=>'fonte','options'=>OpenAI::options([]),'parent_ids'=>[]],$key);
  $rowOf=function(string $id)use($vault){$q=$vault->db->prepare('SELECT payload FROM jobs WHERE id=?');$q->execute([$id]);return (string)$q->fetchColumn();};
  $payloadOf=fn(string $id)=>json_decode(Crypto::open($rowOf($id),$key,"job:$id"),true);
  $heavy=bin2hex(random_bytes(16));$upload='data:image/png;base64,'.base64_encode($picBytes);$files=fn()=>count(glob($dir.'/blobs/job-*-inputs.enc'));$filesBefore=$files();
  $made=$jobs->create(['id'=>$heavy,'prompt'=>'Mude a cor','options'=>['model'=>'gpt-image-2','n'=>3],'parent_id'=>$picItem['id'],'references'=>[['id'=>$picItem['id']],['data'=>$upload]]],$key);
  $inputsFile=$dir."/blobs/job-$heavy-inputs.enc";
  check(count($made)===3&&is_file($inputsFile)&&$files()===$filesBefore+1&&!str_contains(file_get_contents($inputsFile),base64_encode($picBytes)),'the images a request sends go once, sealed, into a file of the batch');
  check(!array_key_exists('references',$payloadOf($made[1]['id']))&&strlen($rowOf($made[1]['id']))<2000&&$payloadOf($made[1]['id'])['inputs']===$heavy&&$made[1]['reference_count']===2,'the row keeps only the rest, and says how many references there are');
  $before=count($calls);$ran=$jobs->run($made[2]['id'],$key,$api);$sent=$calls[$before][1];
  check($ran['status']==='completed'&&count($sent['images'])===2&&$sent['images'][1]['image_url']===$upload,'running a request reads its images from the file');
  $again=$jobs->retry(bin2hex(random_bytes(16)),$made[2]['id'],1,$key)[0];
  check($again['reference_count']===2&&$files()===$filesBefore+1,'a repeated request shares the file of the one it repeats');
  $before=count($calls);$jobs->run($again['id'],$key,$api);check(count($calls[$before][1]['images'])===2,'and sends the same images');
  // A request from before kept its images in its row: the first read moves them out, and nothing is lost meanwhile.
  $legacyOf=function(array $extra=[])use($vault,$key,$upload,$owner){
    $id=bin2hex(random_bytes(16));$p=['prompt'=>'Pedido antigo','options'=>OpenAI::options([]),'references'=>[$upload,$upload,$upload],'mask'=>null,'parent_ids'=>[],'image_ids'=>[],'error'=>null]+$extra;
    $vault->db->prepare("INSERT INTO jobs(id,payload,status,created,updated,user_id) VALUES(?,CAST(? AS BLOB),'failed',?,?,?)")->execute([$id,Crypto::seal(json_encode($p),$key,"job:$id"),time()-50,time()-50,$owner['id']]);
    return $id;};
  $old=$legacyOf();$heavyRow=strlen($rowOf($old));
  $listed=array_values(array_filter($jobs->list($key,'active',[$old]),fn($j)=>$j['id']===$old))[0]??null;
  check($listed!==null&&$listed['reference_count']===3&&strlen($rowOf($old))<$heavyRow/2&&is_file($dir."/blobs/job-$old-inputs.enc"),'reading a request from before moves its images out of the row');
  $before=count($calls);$redo=$jobs->retry(bin2hex(random_bytes(16)),$old,null,$key)[0];$jobs->run($redo['id'],$key,$api);
  check(count($calls[$before][1]['images'])===3,'and repeating it still sends them');
  $busy=new Jobs($vault,['api_timeout'=>60]);(function(){$this->moving=999.0;})->call($busy);
  $late=$legacyOf();$busy->list($key,'active',[$late]);check(!is_file($dir."/blobs/job-$late-inputs.enc")&&array_key_exists('references',$payloadOf($late)),'with no time left, a request from before is read whole and left as it was');
  $busy->dismiss($late,$key);
  check(!array_key_exists('references',$payloadOf($late))&&!empty($payloadOf($late)['dismissed'])&&is_file($dir."/blobs/job-$late-inputs.enc"),'saving it moves its images out first, so they are never lost');
  // The file of a request is gone (a partial backup): the request fails saying so, without calling the API.
  $lost=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'Sem arquivo','options'=>['model'=>'gpt-image-2'],'references'=>[['data'=>$upload]]],$key)[0];
  unlink($dir."/blobs/job-{$lost['id']}-inputs.enc");$before=count($calls);$gone=$jobs->run($lost['id'],$key,$api);
  check($gone['status']==='failed'&&str_contains((string)$gone['error'],'não estão mais')&&count($calls)===$before,'a request whose images are missing fails with a clear message and no call');

  // The history of prompts: image requests and messages to the writer, newest first, a page at a time.
  $storiesH=new Stories($vault);$tale=$storiesH->create('Coração de pedra',$key);$talAlbum=$storiesH->album($tale['id'],$key);$other=$storiesH->create('Outra',$key);
  $say=function(string $story,string $text,int $created,string $role='user')use($vault,$key,$owner){
    $id=bin2hex(random_bytes(16));$content=json_encode(['provider'=>'claude','model'=>null,'native'=>['role'=>'user','content'=>[['type'=>'text','text'=>$text]]],'view'=>$role==='user'?['text'=>$text]:['hidden'=>true]]);
    $vault->db->prepare('INSERT INTO story_messages(id,story_id,user_id,conversation,turn_id,role,content,created) VALUES(?,?,?,1,NULL,?,CAST(? AS BLOB),?)')->execute([$id,$story,$owner['id'],$role,Crypto::seal($content,$key,"story-message:$id"),$created]);};
  $vault->db->exec('DELETE FROM jobs');$now=time();
  $img=function(string $prompt,int $created,?string $album=null)use($jobs,$vault,$key){$id=bin2hex(random_bytes(16));$jobs->create(['id'=>$id,'prompt'=>$prompt,'options'=>[]]+($album?['album'=>$album]:[]),$key);$vault->db->prepare('UPDATE jobs SET created=? WHERE id=?')->execute([$created,$id]);return $id;};
  $i1=$img('Uma montanha ao amanhecer',$now-500);$i2=$img('Capa: coração de pedra',$now-300,$talAlbum);$i3=$img('Um gato',$now-100);
  $say($tale['id'],'Escreva o capítulo um, com o coração partido',$now-400);$say($tale['id'],'contexto escondido',$now-350,'context');$say($other['id'],'Mude o fim',$now-200);
  $history=new History($vault,$jobs,$storiesH);
  $all=$history->page($key,[]);
  check(array_map(fn($i)=>[$i['type'],$i['type']==='image'?$i['prompt']:$i['text']],$all['items'])===[['image','Um gato'],['writer','Mude o fim'],['image','Capa: coração de pedra'],['writer','Escreva o capítulo um, com o coração partido'],['image','Uma montanha ao amanhecer']]&&$all['next']===null,'the history merges image requests and the messages to the writer, newest first, without what the app adds');
  check($all['items'][2]['story']===['id'=>$tale['id'],'title'=>'Coração de pedra']&&$all['items'][1]['story']['title']==='Outra'&&$all['items'][0]['story']===null,'each one says which story it came from');
  check(array_column($history->page($key,['kind'=>'images'])['items'],'type')===['image','image','image']&&array_column($history->page($key,['kind'=>'writer'])['items'],'text')===['Mude o fim','Escreva o capítulo um, com o coração partido'],'it shows only images or only the writer');
  check(array_map(fn($i)=>$i['prompt']??$i['text'],$history->page($key,['story'=>$tale['id']])['items'])===['Capa: coração de pedra','Escreva o capítulo um, com o coração partido'],'one story shows its pictures and its messages');
  check(array_map(fn($i)=>$i['prompt']??$i['text'],$history->page($key,['q'=>'CORACAO'])['items'])===['Capa: coração de pedra','Escreva o capítulo um, com o coração partido'],'a search ignores case and accents');
  for($k=0;$k<12;$k++)$img("Mais $k",$now-1000-$k);
  $seen=[];$cursor='';$pages=0;
  do{$p=$history->page($key,['limit'=>5,'cursor'=>$cursor]);foreach($p['items'] as $i)$seen[]=$i['id'];$cursor=(string)$p['next'];$pages++;}while($p['next']!==null&&$pages<10);
  check(count($seen)===17&&count(array_unique($seen))===17&&$pages===4,'pages go on from where the last one stopped, with nothing left out or repeated');
  rejects(fn()=>$history->page($key,['story'=>str_repeat('a',32)]),'a story of another account is not found');
  $vault->db->exec('DELETE FROM jobs');$vault->db->exec('DELETE FROM story_messages');

  // A vault from the single-owner version becomes the first account, keeping password, key, API key and images.
  mkdir($dir.'/legacy',0700);mkdir($dir.'/legacy/blobs',0700);
  $legacyKey=random_bytes(32);$salt=random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);$legacyDb=new PDO('sqlite:'.$dir.'/legacy/vault.sqlite');
  $legacyDb->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value BLOB NOT NULL);
    CREATE TABLE images (id TEXT PRIMARY KEY, meta BLOB NOT NULL, created INTEGER NOT NULL, favorite INTEGER NOT NULL DEFAULT 0, deleted INTEGER);
    CREATE TABLE jobs (id TEXT PRIMARY KEY, payload BLOB NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL, phase TEXT, preview INTEGER NOT NULL DEFAULT 0);');
  $set=$legacyDb->prepare('INSERT INTO settings VALUES(?,CAST(? AS BLOB))');
  foreach(['salt'=>base64_encode($salt),'wrapped_key'=>Crypto::seal($legacyKey,Crypto::derive('legacy-password-long',$salt),'vault-key:v1'),'epoch'=>'legacy-epoch','api_key'=>Crypto::seal('sk-legacy-placeholder',$legacyKey,'api_key')] as $n=>$v)$set->execute([$n,$v]);
  $legacyImage=bin2hex(random_bytes(16));$legacyJob=bin2hex(random_bytes(16));
  $legacyDb->prepare('INSERT INTO images(id,meta,created) VALUES(?,CAST(? AS BLOB),?)')->execute([$legacyImage,Crypto::seal(json_encode(['title'=>'Antiga','prompt'=>'old','options'=>null,'parent_ids'=>[],'width'=>128,'height'=>96,'mime'=>'image/png']),$legacyKey,"$legacyImage:meta"),time()]);
  file_put_contents($dir."/legacy/blobs/$legacyImage-full.enc",Crypto::seal($bytes,$legacyKey,"$legacyImage:full"));
  $legacyDb->prepare("INSERT INTO jobs(id,payload,status,created,updated) VALUES(?,CAST(? AS BLOB),'completed',?,?)")->execute([$legacyJob,Crypto::seal(json_encode(['prompt'=>'old','options'=>OpenAI::options([]),'image_ids'=>[$legacyImage],'error'=>null,'references'=>[],'parent_ids'=>[]]),$legacyKey,"job:$legacyJob"),time(),time()]);
  unset($legacyDb,$set);
  $upgraded=new Vault($dir.'/legacy');$legacyUsers=new Users($upgraded);$admin=$legacyUsers->only();
  check($admin!==null&&$admin['name']==='admin'&&(bool)$admin['admin']&&$admin['epoch']==='legacy-epoch'&&$legacyUsers->unwrap($admin,'legacy-password-long')===$legacyKey,'the existing owner becomes the admin account with the same password and key');
  $upgraded->useOwner($admin['id']);
  check($upgraded->apiKey($legacyKey)==='sk-legacy-placeholder'&&$upgraded->bytes($legacyImage,'full',$legacyKey)===$bytes&&(new Jobs($upgraded,[]))->list($legacyKey)[0]['image_ids']===[$legacyImage],'existing images, requests and API key belong to the admin account');
  check($upgraded->get('wrapped_key')===null&&$upgraded->get('salt')===null&&$upgraded->get('api_key')===null,'the old password-wrapped key is removed after the move');
  $upgraded->db->prepare('INSERT INTO images(id,meta,created) VALUES(?,CAST(? AS BLOB),?)')->execute([bin2hex(random_bytes(16)),'x',time()]);
  $again=new Vault($dir.'/legacy');check((new Users($again))->count()===1&&(int)$again->db->query('SELECT COUNT(*) FROM images WHERE user_id IS NULL')->fetchColumn()===0,'rows written by the previous version during the upload are adopted too');
  unset($upgraded,$again,$legacyUsers);

  // Vaults created before progress tracking gain the new columns.
  mkdir($dir.'/old',0700);$legacyDb=new PDO('sqlite:'.$dir.'/old/vault.sqlite');
  $legacyDb->exec('CREATE TABLE jobs (id TEXT PRIMARY KEY, payload BLOB NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL)');unset($legacyDb);
  new Vault($dir.'/old');$upgraded=new Vault($dir.'/old');
  $columns=array_column($upgraded->db->query('PRAGMA table_info(jobs)')->fetchAll(),'name');unset($upgraded);
  check(in_array('phase',$columns,true)&&in_array('preview',$columns,true),'existing vault is migrated once');

  // On servers that ignore .htaccess the page warns the administrator when app/ answers on the web: it fetches the marker
  // file that .htaccess hides, and the page and the file must agree on its words.
  $marker=trim((string)file_get_contents(dirname(__DIR__).'/app/web-check.txt'));
  check($marker!==''&&str_contains((string)file_get_contents(dirname(__DIR__).'/assets/app.js'),"'app/web-check.txt'")&&str_contains((string)file_get_contents(dirname(__DIR__).'/assets/app.js'),"=== '$marker'"),'the page checks for the marker file that .htaccess hides in app/');
  echo "\n$count checks passed. No real API calls.\n";
}finally{
  unset($vault,$jobs);
  if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);}
}
