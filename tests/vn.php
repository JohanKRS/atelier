<?php
declare(strict_types=1);
// The visual novel projects: the script and its conditions, the scenes and the choice tree, the checks (dead ends,
// endings no path reaches), the art (sprites on a transparent background from the sheets, expressions as edits of the
// base sprite, backgrounds and their variants, CGs), the writer's tools, the export to Ren'Py, copies and files, and
// the documents the person brings in. Fake APIs only: no real calls.
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Vault,OpenAI,Jobs,Users,HttpError,Stories,Writer,Vn,ToolContext,Mcp};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function rejects(callable $fn,string $message,?int $status=null,?string $says=null): void {
    try{$fn();}catch(Throwable $e){
        if($status!==null && (!$e instanceof HttpError || $e->status!==$status)) throw new RuntimeException($message.' (got '.get_class($e).': '.$e->getMessage().')');
        if($says!==null && !str_contains($e->getMessage(),$says)) throw new RuntimeException($message.' (said: '.$e->getMessage().')');
        check(true,$message);return;
    }
    throw new RuntimeException($message);
}
function claudeEvents(array $blocks,string $stop): array {
    $usage=['input_tokens'=>1000,'output_tokens'=>200,'cache_read_input_tokens'=>0,'cache_creation_input_tokens'=>0];
    $events=[['message_start',['type'=>'message_start','message'=>['id'=>'msg_1','model'=>'claude-opus-5-5','role'=>'assistant','content'=>[],'usage'=>$usage]]]];
    foreach($blocks as $i=>$b){
        if($b['type']==='tool_use'){
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'tool_use','id'=>$b['id'],'name'=>$b['name'],'input'=>[]]]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'input_json_delta','partial_json'=>json_encode($b['input'],JSON_UNESCAPED_UNICODE)]]];
        }else{
            $events[]=['content_block_start',['type'=>'content_block_start','index'=>$i,'content_block'=>['type'=>'text','text'=>'']]];
            $events[]=['content_block_delta',['type'=>'content_block_delta','index'=>$i,'delta'=>['type'=>'text_delta','text'=>$b['text']]]];
        }
        $events[]=['content_block_stop',['type'=>'content_block_stop','index'=>$i]];
    }
    $events[]=['message_delta',['type'=>'message_delta','delta'=>['stop_reason'=>$stop,'stop_details'=>null],'usage'=>['output_tokens'=>200]]];
    $events[]=['message_stop',['type'=>'message_stop']];
    return $events;
}
function player(array &$requests,array $answers): Closure {
    $n=0;
    return function(string $url,array $headers,array $body,Closure $onEvent,Closure $tick,int $timeout) use (&$requests,&$n,$answers): array {
        $requests[]=['body'=>json_decode(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),true)];
        $answer=$answers[$n++]??throw new RuntimeException('No more fake answers');
        foreach($answer as [$event,$data])$onEvent($event,$data);
        return ['status'=>200,'body'=>'','sent'=>true,'errno'=>0,'retry_after'=>null];
    };
}

$dir=sys_get_temp_dir().'/imagegen-vn-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    // -----------------------------------------------------------------------------------------------------------------
    // Names, conditions and the script
    check(Vn::slug('Café Central')==='cafe_central'&&Vn::slug('  Ação! 2ª parte ')==='acao_2_parte'&&Vn::slug('3 irmãs')==='x3_irmas'&&Vn::slug('???')==='x','names become plain labels');
    check(Vn::label('O Primeiro Encontro')==='o_primeiro_encontro'&&Vn::label('rota_ana_01')==='rota_ana_01','a scene\'s label comes from its title, or is kept as given');
    rejects(fn()=>Vn::label('start'),'Ren\'Py\'s own labels are refused',422,'Ren\'Py');
    rejects(fn()=>Vn::variableName('afinidade ana'),'a variable name has no spaces',422);
    rejects(fn()=>Vn::variableName('and'),'nor is a reserved word',422,'reservada');
    $tree=Vn::condition('afinidade_ana >= 3 e não brigou ou nome = "Bia"');
    check($tree[0]==='or'&&$tree[1][0]==='and'&&$tree[1][2]===['not',['var','brigou']]&&$tree[2]===['cmp','==',['var','nome'],['str','Bia']],'conditions read Portuguese and English, = as ==');
    check(Vn::python($tree)==='afinidade_ana >= 3 and not brigou or nome == "Bia"'&&Vn::python(Vn::condition('not (a or b)'))==='not (a or b)'&&Vn::python(Vn::condition('x = verdadeiro'))==='x == True','and are written back as Python, with parentheses only where needed');
    check(Vn::evaluate($tree,['afinidade_ana'=>3,'brigou'=>false])===true&&Vn::evaluate($tree,['afinidade_ana'=>2,'nome'=>'Bia'])===true&&Vn::evaluate($tree,['afinidade_ana'=>5,'brigou'=>true])===false,'conditions are evaluated with the variables, unset ones as false');
    check(Vn::evaluate(Vn::condition('pontos > -2'),['pontos'=>-1])===true&&Vn::evaluate(Vn::condition('2.5 < x'),['x'=>3])===true,'negative and decimal numbers');
    rejects(fn()=>Vn::condition('a >= '),'a condition that ends early is refused',422);
    rejects(fn()=>Vn::condition('(a and b'),'an open parenthesis is refused',422,'parêntese');
    rejects(fn()=>Vn::condition('a b'),'two values in a row are refused',422,'Sobrou');
    $set=Vn::assignment('afinidade_ana += 2');
    check($set['op']==='+='&&Vn::apply($set,['afinidade_ana'=>1])['afinidade_ana']===3&&Vn::apply(Vn::assignment('viu = true'),[])['viu']===true&&Vn::assignmentText(Vn::assignment('nome="Bia"'))==='nome = "Bia"','changes of variables');
    rejects(fn()=>Vn::assignment('nome += "x"'),'+= adds a number',422);

    $script="@fundo Café Central, noite, fade\n# a Ana está nervosa\n@show Ana, feliz, esquerda\nAna: Oi! Você veio.\nAna (brava): Mas atrasado.\n> Ana: (pensando) não, isso é narração\nEram 10:30 da manhã.\n???: Quem está aí?\n@set afinidade_ana += 1\n@if afinidade_ana >= 2: Ana: Senti sua falta.\n@musica tema_cafe, fade 2\n@som porta\n@cg beijo, dissolve\n@pausa 1,5\n@efeito tremer\n@hide Ana, dissolve\n@music stop";
    $p=Vn::parse($script,['Ana']);
    $t=array_column($p['steps'],'t');
    check(!$p['errors']&&$t===['bg','note','show','say','say','say','say','say','set','say','music','sound','cg','pause','effect','hide','music'],'every kind of line is read, Portuguese commands too');
    [$bg,,$show,$hi,$angry,$narr,$time,$who]=$p['steps'];
    check($bg['place']==='Café Central'&&$bg['variant']==='noite'&&$bg['with']==='fade'&&$show['who']==='Ana'&&$show['attrs']==='feliz'&&$show['at']==='left','backgrounds with variants and transitions, sprites with expressions and positions');
    check($hi['who']==='Ana'&&$hi['text']==='Oi! Você veio.'&&$angry['attrs']==='brava'&&$narr['who']===null&&str_starts_with($narr['text'],'Ana:')&&$time['who']===null&&$who['who']==='???','dialogue, expressions on a line, narration (also with > and with a time), an unknown speaker');
    $if=$p['steps'][9];
    check($if['t']==='say'&&$if['if']==='afinidade_ana >= 2'&&$if['cond'][0]==='cmp','a step can depend on a condition');
    check($p['steps'][10]['track']==='tema_cafe'&&$p['steps'][10]['fade']===2.0&&$p['steps'][13]['seconds']===1.5&&$p['steps'][14]['name']==='shake'&&$p['steps'][16]['track']===null,'music with a fade, pauses, effects, music stopping');
    $q=Vn::parse("@show Ana Clara triste direita\n@show Bruno",['Ana Clara','Bruno']);
    check($q['steps'][0]['who']==='Ana Clara'&&$q['steps'][0]['attrs']==='triste'&&$q['steps'][0]['at']==='right'&&$q['steps'][1]['attrs']==='','a command without commas tells the name from the expression by the sheets');
    $bad=Vn::parse("Ana: oi\n@voar alto\n@if x: \n@bg\n@set = 3",[]);
    check(array_column($bad['errors'],'line')===[2,3,4,5]&&str_contains($bad['errors'][0]['message'],'@voar'),'lines that can\'t be read are told by their number');
    check(Vn::scriptWords($p['steps'])>10,'the words the player reads are counted');

    // -----------------------------------------------------------------------------------------------------------------
    // A project and its settings
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $vault->setClaudeKey('sk-ant-test-placeholder-key',$key);
    $stories=new Stories($vault);$jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>4]);
    $writer=new Writer($vault,$stories,$jobs,['api_timeout'=>60]);
    $vn=$stories->vn();
    $plain=$stories->create('Uma história',$key)['id'];
    $sid=$stories->create('Café às Três',$key,'vn')['id'];
    check($stories->listed($sid,$key)['kind']==='vn'&&$stories->listed($plain,$key)['kind']==='story'&&$stories->kind($sid,$key)==='vn','a visual novel project is a story of its own kind');
    rejects(fn()=>$vn->scenes($plain,$key),'a story that isn\'t a visual novel has no scenes',422);
    rejects(fn()=>$stories->create('x',$key,'game'),'only stories and visual novels',422);
    $stories->update($sid,['writer'=>Writer::settings(null,['image_model'=>'gpt-image-2'])],$key);
    $s=$vn->settings($sid,$key);
    check($s['settings']===['name'=>'','aspect'=>'16:9','framing'=>'knees','start'=>'']&&$s['variables']===[]&&$s['gameplay']['text']==='','a project starts 16:9, sprites to the knees, with nothing declared');
    $s=$vn->setSettings($sid,['name'=>'Café às Três','framing'=>'waist','variables'=>['set'=>[['name'=>'afinidade_ana','type'=>'number','initial'=>'0','note'=>'Quanto a Ana gosta de você'],['name'=>'viu_carta','type'=>'flag'],['name'=>'sobra','type'=>'flag']]],
        'routes'=>['set'=>[['name'=>'Comum'],['name'=>'Rota da Ana','color'=>'#ff8800']]],'audio'=>['set'=>[['name'=>'Tema do Café','kind'=>'music','note'=>'piano calmo'],['name'=>'porta','kind'=>'sound']]]],'person',$key);
    check($s['settings']['name']==='Café às Três'&&$s['settings']['framing']==='waist'&&$s['variables'][0]['initial']===0&&$s['variables'][1]['initial']===false&&$s['routes'][1]['color']==='#ff8800'&&$s['audio'][0]['name']==='tema_do_cafe'&&$s['rev']===1&&$s['by']==='person','settings, variables, routes and audio cues');
    rejects(fn()=>$vn->setSettings($sid,['variables'=>['set'=>[['name'=>'viu_carta','new_name'=>'afinidade_ana']]]],'person',$key),'two variables can\'t have one name',422,'Já existe');
    rejects(fn()=>$vn->setSettings($sid,['aspect'=>'1:1'],'person',$key),'only a screen\'s shapes',422);
    $vn->setSettings($sid,['variables'=>['remove'=>['sobra']]],'person',$key);
    $g=$vn->writeGameplay($sid,['text'=>'Afinidade vai de 0 a 5.'],'person',$key);
    check($g['rev']===1&&$vn->settings($sid,$key)['rev']===2,'the gameplay notes keep a revision of their own');
    rejects(fn()=>$vn->writeGameplay($sid,['text'=>'x'],'person',$key,0),'gameplay notes changed meanwhile are not overwritten',409);

    // -----------------------------------------------------------------------------------------------------------------
    // Scenes and the tree
    $stories->updateCast($sid,['character'=>'Ana','note'=>'barista, 24 anos'],'person',$key);
    $stories->updateCast($sid,['character'=>'Café Central','kind'=>'place'],'person',$key);
    $ch=$stories->addChapter($sid,'Capítulo 1','Base do capítulo.','',null,'person',$key);
    $a=$vn->addScene($sid,['title'=>'A Chegada','chapter'=>$ch['id'],'route'=>'Comum','script'=>"@bg Café Central, noite\n@show Ana, feliz\nAna: Oi!\n@set afinidade_ana += 1",
        'exit'=>['type'=>'choice','prompt'=>'E agora?','options'=>[['text'=>'Pedir desculpas','to'=>'desculpas','set'=>['afinidade_ana += 1']],['text'=>'Ir embora','to'=>'final_ruim','note'=>'o final rápido']]]],null,'person',$key);
    check($a['label']==='a_chegada'&&$a['number']===1&&$a['rev']===1&&$a['chapter']===$ch['id']&&$a['route']===$vn->settings($sid,$key)['routes'][0]['id']&&$vn->settings($sid,$key)['settings']['start']==='a_chegada','a scene takes its label from the title, and the first one is where the game starts');
    $dup=$vn->addScene($sid,['title'=>'A Chegada','script'=>'> x'],null,'person',$key);
    check($dup['label']==='a_chegada_2','a label taken from a title gets a number');
    $vn->deleteScene($sid,$dup['id'],$key);
    rejects(fn()=>$vn->addScene($sid,['title'=>'X','label'=>'a_chegada','script'=>'> x'],null,'person',$key),'a label given must be free',422);
    rejects(fn()=>$vn->addScene($sid,['title'=>'X','script'=>"Ana: oi\n@voar"],null,'person',$key),'a script with a line that can\'t be read is refused, with the line',422,'Linha 2');
    rejects(fn()=>$vn->addScene($sid,['title'=>'X','route'=>'Nenhuma'],null,'person',$key),'a route must exist',422);
    rejects(fn()=>$vn->addScene($sid,['title'=>'X','exit'=>['type'=>'choice','options'=>[['text'=>'a','to'=>'b','if'=>'x >']]]],null,'person',$key),'a choice\'s condition is checked',422,'Opção 1');
    $b=$vn->addScene($sid,['title'=>'Desculpas','label'=>'desculpas','script'=>"Ana (brava): Você demorou.",'exit'=>['type'=>'branch','cases'=>[['if'=>'afinidade_ana >= 2','to'=>'final_bom']],'else'=>'final_ruim']],null,'person',$key);
    $vn->addScene($sid,['title'=>'Final bom','label'=>'final_bom','script'=>"@cg beijo\nAna: Fica.",'exit'=>['type'=>'end','title'=>'Juntos','kind'=>'good']],null,'person',$key);
    $vn->addScene($sid,['title'=>'Final ruim','label'=>'final_ruim','script'=>'> Você saiu.','exit'=>['type'=>'end','title'=>'Sozinho','kind'=>'bad']],null,'person',$key);
    $secret=$vn->addScene($sid,['title'=>'Segredo','label'=>'segredo','script'=>'> ...','exit'=>['type'=>'end','title'=>'O segredo','kind'=>'secret']],null,'person',$key);
    $an=$vn->analyze($sid,$key);
    $codes=fn(array $an)=>array_map(fn($p)=>$p['code'].':'.$p['scene'],$an['problems']);
    check(in_array('unreachable:segredo',$codes($an),true)&&in_array('ending-unreachable:segredo',$codes($an),true),'a scene and an ending no path reaches are flagged');
    check(array_column($an['endings'],'reachable','scene')===['final_bom'=>true,'final_ruim'=>true,'segredo'=>false]&&$an['played']['truncated']===false&&$an['played']['endings']===2,'every path is played: two endings can be reached');
    check($an['stats']===['scenes'=>5,'words'=>$an['stats']['words'],'choices'=>1,'endings'=>3,'lines'=>5]&&$an['stats']['words']>5,'the totals');
    check(array_map(fn($m)=>$m['character'].'|'.$m['attrs'],$an['missing']['sprites'])===['Ana|feliz','Ana|brava']&&$an['missing']['backgrounds'][0]['place']==='Café Central'&&$an['missing']['backgrounds'][0]['sheet']===true&&$an['missing']['cgs'][0]['name']==='beijo','the art the scripts use and doesn\'t exist yet');
    // The good ending needs 2 points: one from the first scene, one from the apology. Without the apology's point it can't be reached.
    $vn->updateScene($sid,'a_chegada',['exit'=>['type'=>'choice','prompt'=>'E agora?','options'=>[['text'=>'Pedir desculpas','to'=>'desculpas'],['text'=>'Ir embora','to'=>'final_ruim']]]],'person',$key);
    $an=$vn->analyze($sid,$key);
    check(in_array('ending-blocked:final_bom',$codes($an),true)&&array_column($an['endings'],'reachable','scene')['final_bom']===false,'an ending a path leads to, that no combination of choices reaches, is flagged');
    $vn->updateScene($sid,'a_chegada',['exit'=>['type'=>'choice','options'=>[['text'=>'Só se viu a carta','to'=>'desculpas','if'=>'viu_carta']]]],'person',$key);
    $an=$vn->analyze($sid,$key);
    check(in_array('all-conditional:a_chegada',$codes($an),true)&&in_array('stuck:a_chegada',$codes($an),true)&&in_array('one-option:a_chegada',$codes($an),true),'a choice whose every option is hidden leaves the player stuck');
    $vn->updateScene($sid,'desculpas',['exit'=>['type'=>'branch','cases'=>[['if'=>'afinidade_ana >= 2','to'=>'final_bom'],['if'=>'medo','to'=>'nao_existe']]]],'person',$key);
    $an=$vn->analyze($sid,$key);
    check(in_array('no-else:desculpas',$codes($an),true)&&in_array('missing-target:desculpas',$codes($an),true)&&in_array('undeclared:desculpas',$codes($an),true),'a branch without else, to a scene not written, on a variable not declared');
    $none=$vn->addScene($sid,['title'=>'Sem saída','label'=>'sem_saida'],null,'person',$key);
    check(in_array('dead-end:sem_saida',$codes($vn->analyze($sid,$key)),true)&&in_array('empty:sem_saida',$codes($vn->analyze($sid,$key)),true),'a scene without an exit, and without a script');
    $vn->deleteScene($sid,'sem_saida',$key);
    // Back to a good tree
    $vn->updateScene($sid,'a_chegada',['exit'=>['type'=>'choice','prompt'=>'E agora?','options'=>[['text'=>'Pedir desculpas','to'=>'desculpas','set'=>['afinidade_ana += 1']],['text'=>'Ir embora','to'=>'final_ruim']]]],'person',$key);
    $vn->updateScene($sid,'desculpas',['exit'=>['type'=>'branch','cases'=>[['if'=>'afinidade_ana >= 2','to'=>'final_bom']],'else'=>'final_ruim']],'person',$key);

    $was=$vn->scene($sid,'desculpas',$key);
    rejects(fn()=>$vn->updateScene($sid,'desculpas',['summary'=>'x'],'person',$key,$was['rev']-1),'a scene changed meanwhile is not overwritten',409);
    $vn->updateScene($sid,'desculpas',['label'=>'pedido_de_desculpas'],'person',$key);
    check($vn->scene($sid,'a_chegada',$key)['exit']['options'][0]['to']==='pedido_de_desculpas','a new label is changed in the exits that led to the old one');
    $vn->updateScene($sid,'a_chegada',['label'=>'abertura'],'person',$key);
    check($vn->settings($sid,$key)['settings']['start']==='abertura','and in the start');
    $e=$vn->updateScene($sid,'pedido_de_desculpas',['script_edits'=>[['old_text'=>'demorou','new_text'=>'demorou muito']],'append_script'=>'Ana: Mas tudo bem.'],'person',$key);
    check($e['script']==="Ana (brava): Você demorou muito.\nAna: Mas tudo bem.",'exact replacements and lines added at the end of a script');
    $before=$vn->scene($sid,'abertura',$key)['rev'];
    $vn->setPositions($sid,[$vn->scene($sid,'abertura',$key)['id']=>['x'=>120,'y'=>40]],$key);
    check($vn->scene($sid,'abertura',$key)['pos']===['x'=>120,'y'=>40]&&$vn->scene($sid,'abertura',$key)['rev']===$before,'a scene\'s place on the tree is not a change of the scene');
    $vn->setSettings($sid,['variables'=>['set'=>[['name'=>'afinidade_ana','new_name'=>'af_ana']]]],'person',$key);
    check(str_contains($vn->scene($sid,'abertura',$key)['script'],'@set af_ana += 1')&&$vn->scene($sid,'abertura',$key)['exit']['options'][0]['set']===['af_ana += 1']&&$vn->scene($sid,'pedido_de_desculpas',$key)['exit']['cases'][0]['if']==='af_ana >= 2','a variable renamed is renamed in every script and exit');
    $vn->moveScene($sid,'segredo',0,$key);
    check(array_column($vn->scenes($sid,$key),'label')[0]==='segredo','scenes move in the list');
    $vn->moveScene($sid,'segredo',9,$key);

    // -----------------------------------------------------------------------------------------------------------------
    // The writer's tools, and the art with a fake image API
    $png=function(int $w,int $h): string { $im=imagecreatetruecolor($w,$h); imagesavealpha($im,true); imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127)); ob_start(); imagepng($im); return ob_get_clean(); };
    $sent=[];
    $api=new OpenAI('test',60,function($path,$body) use ($png,&$sent){ $sent[]=['path'=>$path,'body'=>$body]; [$w,$h]=array_map('intval',explode('x',$body['size']==='auto'?'1024x1536':$body['size'])); return ['data'=>[['b64_json'=>base64_encode($png((int)($w/16),(int)($h/16)))]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]]; });
    $jobOf=fn(string $id)=>array_values(array_filter($jobs->list($key,'active',[$id]),fn($j)=>$j['id']===$id))[0];
    $lib=[];
    foreach(['ana','cafe','foto'] as $n){ $one=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>"imagem $n",'options'=>['model'=>'gpt-image-2','quality'=>'low','size'=>'1024x1024','n'=>1,'previews'=>1]],$key)[0]; $lib[$n]=$jobs->run($one['id'],$key,$api)['image_ids'][0]; }
    $stories->updateCast($sid,['character'=>'Ana','add'=>[['image'=>$lib['ana'],'caption'=>'Uniforme do café']]],'person',$key);
    $stories->updateCast($sid,['character'=>'Café Central','add'=>[['image'=>$lib['cafe']]]],'person',$key);
    $stories->updateStyle($sid,['text'=>'Soft anime visual novel art.'],'person',$key);
    $wi=$writer->withImageApi($api);
    $ctx=new ToolContext($sid,str_repeat('e',32),Writer::settings(null,['image_model'=>'gpt-image-2']),$key);
    $names=array_keys(Writer::tools(true));
    check(!array_intersect($names,['comic_settings','draw_comic_page'])&&!array_diff(['read_scenes','write_scene','edit_scene','check_vn','make_sprite','make_background','make_cg','update_art','vn_settings','update_gameplay'],$names)&&in_array('illustrate',$names,true),'a visual novel\'s writer has the scene and art tools, and no comic');
    check(!array_intersect(array_keys(Writer::tools()),['write_scene','make_sprite'])&&count(Writer::tools(null))===count(Writer::tools())+count(Writer::vnTools()),'a story\'s writer has none of them; MCP lists them all');
    check(str_contains(Writer::system(true),'## This project is a visual novel')&&str_contains(Writer::system(true),'@bg Place')&&Writer::system(false)===Writer::SYSTEM,'the writer of a visual novel is told how the game, the script and the art work');
    $pc=new ToolContext($plain,str_repeat('f',32),Writer::settings(null),$key);
    [$ok,$out]=$writer->runTool('write_scene',['title'=>'x','script'=>'> x'],$pc);
    check(!$ok&&str_contains($out,'visual novel projects'),'the scene tools refuse a story that isn\'t a visual novel');
    [$ok,$out]=$writer->runTool('read_comic',[],$ctx);
    check(!$ok&&str_contains($out,'no comic'),'and a visual novel has no comic');

    [$ok,$out,$label]=$wi->runTool('write_scene',['title'=>'Na cozinha','label'=>'cozinha','route'=>'Rota da Ana','chapter'=>1,'summary'=>'Ana prepara o café.','notes'=>'Primeira cena da rota.',
        'script'=>"@bg Cozinha do Café, dia\n@show Ana, surpresa, centro\nAna (surpresa): Você aqui?\n@music tema_novo",'exit'=>['type'=>'jump','to'=>'final_bom']],$ctx);
    check($ok&&$label==='Escreveu a cena cozinha · “Na cozinha” (2 palavras)'&&str_contains($out,'Scene cozinha "Na cozinha" saved as scene 6 (2 words)')&&str_contains($out,'Exit: → final_bom'),'the writer writes a scene');
    check(str_contains($out,"It uses art or audio that doesn't exist yet: sprite Ana (surpresa); background Cozinha do Café (dia); music tema_novo (not in the audio list)."),'and hears what the scene uses that doesn\'t exist yet');
    check($vn->scene($sid,'cozinha',$key)['by']==='agent'&&$vn->scene($sid,'cozinha',$key)['chapter']===$ch['id'],'the scene is the writer\'s, with its chapter');
    [$ok,$out]=$wi->runTool('write_scene',['title'=>'Ruim','script'=>"Ana: oi\n@if : x"],$ctx);
    check(!$ok&&str_contains($out,'Linha 2'),'a script with a line that can\'t be read is refused with its line, and nothing is saved');
    [$ok,$out,$label]=$wi->runTool('edit_scene',['scene'=>'cozinha','append_script'=>'Ana: Quer um café?','exit'=>['type'=>'choice','options'=>[['text'=>'Aceitar','to'=>'final_bom','set'=>['af_ana += 1']],['text'=>'Recusar','to'=>'final_ruim']]]],$ctx);
    check($ok&&$label==='Continuou a cena cozinha'&&str_contains($out,'choice: "Aceitar" {af_ana += 1} → final_bom | "Recusar" → final_ruim'),'the writer continues a scene and gives it a choice');
    [$ok,$out]=$wi->runTool('read_scenes',['scenes'=>['cozinha','nada']],$ctx);
    check($ok&&str_contains($out,"Script:\n@bg Cozinha do Café, dia")&&str_contains($out,'route Rota da Ana')&&str_contains($out,'Scene nada: Não há cena'),'the writer reads scenes in full');
    [$ok,$out,$label]=$wi->runTool('vn_settings',['audio'=>['set'=>[['name'=>'tema_novo','kind'=>'music']]]],$ctx);
    check($ok&&$label==='Atualizou a trilha'&&str_contains($out,'tema_novo (music)'),'the writer declares the music');
    [$ok,$out,$label]=$wi->runTool('check_vn',[],$ctx);
    check($ok&&str_contains($out,'segredo: Nenhum caminho')&&str_contains($out,'NOT reachable')&&str_contains($out,'Every path was played')&&str_contains($out,'sprites Ana (feliz), Ana (brava), Ana (surpresa)'),'check_vn shows the problems, the endings and what is missing');
    [$ok,$out]=$wi->runTool('get_outline',[],$ctx);
    check($ok&&str_contains($out,'Visual novel settings: game name "Café às Três"')&&str_contains($out,'- cozinha "Na cozinha" [chapter 1] [route Rota da Ana]')&&str_contains($out,'<gameplay>'),'the outline has the whole visual novel');

    // Sprites: the base one from the sheet, on a transparent background; another expression as an edit of it
    $sent=[];
    [$ok,$out,$label]=$wi->runTool('make_sprite',['character'=>'ana','expression'=>'Neutra','prompt'=>'Calm smile, hands together in front.'],$ctx);
    $base=end($ctx->jobs); $job=$jobOf($base);
    check($ok&&$label==='Pediu o sprite Ana (neutra)'&&str_contains($out,'from Ana\'s sheet')&&$job['options']['background']==='transparent'&&$job['options']['format']==='png'&&$job['options']['size']==='1152x1536','the base sprite is made from the sheet, transparent, framed to the waist (3:4)');
    check(str_contains($job['prompt'],'Calm smile')&&str_contains($job['prompt'],'framed from the head down to the waist')&&str_contains($job['prompt'],'Transparent background')&&str_contains($job['prompt'],'Attached image 1 is Ana\'s reference sheet')&&str_contains($job['prompt'],'Soft anime visual novel art'),'its prompt has the framing, the background, the sheet and the style');
    $art=$vn->art($sid,$key);
    check(count($art['sprites'])===1&&$art['sprites'][0]['job']===$base&&$art['sprites'][0]['image']===null&&$art['sprites'][0]['expression']==='neutra'&&$art['by']==='agent','the sprite waits for its picture in the art');
    [$ok,$out]=$wi->runTool('make_sprite',['character'=>'Ana','expression'=>'neutra','prompt'=>'x'],$ctx);
    check(!$ok&&str_contains($out,'already being made'),'one being made is not made twice');
    [$ok,$out]=$wi->runTool('make_sprite',['character'=>'Bruno','expression'=>'feliz','prompt'=>'x'],$ctx);
    check(!$ok&&str_contains($out,'ficha'),'a sprite needs a character sheet');
    [$ok,$out,$label]=$wi->runTool('make_sprite',['character'=>'Ana','expression'=>'feliz','prompt'=>'Big open smile, one hand waving.'],$ctx);
    $happy=end($ctx->jobs); $hj=$jobOf($happy);
    check($ok&&$jobOf($base)['status']==='completed'&&$hj['operation']==='edit'&&$hj['parent_id']===$jobOf($base)['image_ids'][0]&&$hj['options']['background']==='transparent'&&str_contains($out,'as an edit of the sprite of Ana (neutra)'),'another expression waits for the base and is an edit of it');
    check(str_contains($hj['prompt'],'Edit @1, a visual novel sprite of Ana: the facial expression becomes feliz')&&str_contains($hj['prompt'],'line up when one replaces the other')&&str_contains($hj['prompt'],'Attached image 2 is Ana\'s reference sheet'),'keeping everything but the expression, with the sheet');
    $vn->finishJob($sid,$happy,$jobs->run($happy,$key,$api)['image_ids'][0],$key);
    $stories->resolveIllustrations($sid,$key,$jobs);
    $art=$vn->art($sid,$key);
    check(count(array_filter($art['sprites'],fn($x)=>is_string($x['image'])&&$x['job']===null))===2,'the pictures that are ready become the sprites');
    check(Vn::spriteFor($art['sprites'],$art['sprites'][0]['character'],'feliz')['expression']==='feliz'&&Vn::spriteFor($art['sprites'],$art['sprites'][0]['character'],'')['expression']==='neutra'&&Vn::spriteFor($art['sprites'],$art['sprites'][0]['character'],'brava')===null,'the sprite for an expression, the first for none');
    [$ok,$out,$label]=$wi->runTool('make_sprite',['character'=>'Ana','expression'=>'neutra','outfit'=>'pijama','prompt'=>'Pajamas with stars.'],$ctx);
    check($ok&&str_contains($jobOf(end($ctx->jobs))['prompt'],'the outfit becomes the pijama outfit')&&!str_contains($jobOf(end($ctx->jobs))['prompt'],', the same outfit,'),'a new outfit is an edit of the base too');

    // Backgrounds: the base one from the place sheet, the night as an edit of it
    [$ok,$out,$label]=$wi->runTool('make_background',['place'=>'café central','prompt'=>'A cozy small café, wooden counter, warm lamps.'],$ctx);
    $bgJob=$jobOf(end($ctx->jobs));
    check($ok&&$label==='Pediu o cenário Café Central'&&$bgJob['options']['size']==='1536x864'&&str_contains($bgJob['prompt'],'No people or characters')&&str_contains($bgJob['prompt'],'reference sheet of this place')&&($bgJob['options']['background']??'auto')!=='transparent','a background is made in the screen\'s shape from the place sheet');
    [$ok,$out]=$wi->runTool('make_background',['place'=>'Café Central','variant'=>'Noite','prompt'=>'Closed for the night, rain on the windows.'],$ctx);
    $night=$jobOf(end($ctx->jobs));
    check($ok&&$night['operation']==='edit'&&str_contains($night['prompt'],'show the same place noite')&&str_contains($out,'as an edit of the place\'s base background'),'a variant is an edit of the place\'s base background');
    [$ok,$out]=$wi->runTool('make_background',['place'=>'Terraço','prompt'=>'A rooftop at sunset.'],$ctx);
    check($ok&&str_contains($out,'the place has no sheet picture'),'a place without a sheet is made from the description');

    // A CG with the sheets of its characters
    [$ok,$out,$label]=$wi->runTool('make_cg',['name'=>'Beijo','title'=>'O beijo','prompt'=>'Ana kisses @1 under the rain.','characters'=>['Ana'],'references'=>[$lib['foto']]],$ctx);
    $cg=$jobOf(end($ctx->jobs));
    check($ok&&$label==='Pediu a CG “O beijo”'&&str_contains($cg['prompt'],'Attached image 2 is Ana\'s reference sheet')&&$cg['reference_count']===2&&$cg['options']['size']==='1536x864'&&$vn->art($sid,$key)['cgs'][0]['name']==='beijo','a CG has the sheets of its characters after the writer\'s references');
    [$ok,$out,$label]=$wi->runTool('update_art',['kind'=>'cg','name'=>'chuva','title'=>'Chuva','image_id'=>$lib['foto']],$ctx);
    check($ok&&count($vn->art($sid,$key)['cgs'])===2,'an existing image becomes a CG, free');
    [$ok]=$wi->runTool('update_art',['kind'=>'cg','name'=>'chuva','remove'=>true],$ctx);
    check($ok&&count($vn->art($sid,$key)['cgs'])===1,'and leaves the art');
    foreach($ctx->jobs as $j) if(in_array($jobOf($j)['status'],['queued','running'],true)) $jobs->run($j,$key,$api);
    $stories->resolveIllustrations($sid,$key,$jobs);
    $art=$vn->art($sid,$key);
    check(!array_filter([...$art['sprites'],...$art['backgrounds'],...$art['cgs']],fn($x)=>$x['job']!==null),'every picture is in the art');
    $spriteImage=$art['sprites'][1]['image'];
    [$ok,$out,$label]=$wi->runTool('edit_illustration',['image_id'=>$spriteImage,'instruction'=>'Make the smile softer.'],$ctx);
    check($ok&&str_contains($out,'the sprite of Ana (feliz)'),'edit_illustration changes a sprite too');
    $edit=end($ctx->jobs); $new=$jobs->run($edit,$key,$api)['image_ids'][0];
    $stories->resolveIllustrations($sid,$key,$jobs);
    check($vn->art($sid,$key)['sprites'][1]['image']===$new,'the new version takes the sprite\'s place');
    $an=$vn->analyze($sid,$key);
    check(!array_filter($an['missing']['sprites'],fn($m)=>$m['attrs']==='feliz')&&!array_filter($an['missing']['backgrounds'],fn($m)=>$m['place']==='Café Central'&&$m['variant']==='noite')&&!$an['missing']['cgs'],'the checks no longer miss the art that exists');

    // The page's art: a default prompt, the base pending, a failure retried
    $person=Writer::settings(null,['image_model'=>'gpt-image-2']);
    [$made,$job,$how]=$wi->makeVnArt($sid,$person,'sprite',['character'=>'Ana','expression'=>'brava','prompt'=>''],'person',$key);
    check($made['by']==='person'&&str_contains($job['prompt'],'Ana, brava expression.')&&$job['operation']==='edit','from the page, a sprite with no description is made from the sheet\'s note');
    $jobs->run($job['id'],$key,new OpenAI('test',60,function(){ throw new HttpError(400,'Recusado.'); }));
    $failed=array_values(array_filter($stories->resolveIllustrations($sid,$key,$jobs),fn($j)=>$j['id']===$job['id']));
    check($failed&&$failed[0]['for']==='vn','a failed picture of the art is reported as such');
    $again=$wi->retryVnArt($sid,$job['id'],$key);
    check($vn->jobs($sid,$key)[$again['id']]['kind']==='sprite'&&!isset($vn->jobs($sid,$key)[$job['id']]),'and made again');
    $vn->retryJob($sid,$again['id'],null,$key);
    check(!array_filter($vn->art($sid,$key)['sprites'],fn($x)=>$x['expression']==='brava'),'one set aside without a picture leaves the art');

    // -----------------------------------------------------------------------------------------------------------------
    // The page's view, the preview's runtime and Ren'Py
    $view=$vn->view($sid,$key,true);
    $coz=array_values(array_filter($view['scenes'],fn($x)=>$x['label']==='cozinha'))[0];
    check(isset($coz['steps'][0]['t'])&&$coz['run']['options'][0]['sets'][0]['var']==='af_ana'&&$view['analysis']['stats']['scenes']===6&&!isset($vn->view($sid,$key)['scenes'][0]['steps']),'the page gets the project, and the preview each scene as it plays');
    $r=$vn->renpy($sid,$key);
    $rpy=$r['files']['game/script.rpy'];
    check(str_contains($rpy,"label start:\n    jump abertura")&&str_contains($rpy,"label abertura:")&&str_contains($rpy,'    menu:'."\n".'        "E agora?"'."\n".'        "Pedir desculpas":'."\n".'            $ af_ana += 1'."\n".'            jump pedido_de_desculpas'),'the script has a label per scene and the choices as menus');
    check(str_contains($rpy,"    if af_ana >= 2:\n        jump final_bom\n    else:\n        jump final_ruim")&&str_contains($rpy,'    centered "{size=+12}Juntos{/size}"'."\n    return"),'branches as if/else, endings as a title and return');
    check(str_contains($rpy,'scene bg cafe_central noite')&&str_contains($rpy,'show ana feliz')&&str_contains($rpy,'ana brava "Você demorou muito."')&&str_contains($rpy,'play music "audio/tema_novo.ogg"')&&str_contains($rpy,'scene cg beijo'),'backgrounds, sprites, expressions in dialogue, music and CGs');
    check(str_contains($r['files']['game/atelier_personagens.rpy'],'define ana = Character("Ana", image="ana"')&&str_contains($r['files']['game/atelier_variaveis.rpy'],'default af_ana = 0')&&str_contains($r['files']['game/atelier_variaveis.rpy'],'default viu_carta = False'),'characters and variables');
    check(isset($r['images']['game/images/sprites/ana/ana_feliz.png'],$r['images']['game/images/bg/cafe_central_noite.png'],$r['images']['game/images/cg/beijo.png'])&&str_contains($r['files']['game/atelier_imagens.rpy'],'image ana feliz = Transform("images/sprites/ana/ana_feliz.png"'),'every picture has its place in the game folder');
    check(str_contains($r['files']['atelier/roteiro.md'],'**Ana** *(brava)*: Você demorou muito.')&&json_decode($r['files']['atelier/projeto.json'],true)['scenes'][0]['label']!==''&&str_contains($r['files']['LEIA-ME.txt'],'Ren\'Py 8'),'with the whole script to read, the project as JSON and how to use it');
    check(Vn::renpyText('Diga "oi" [agora] {b}')==='"Diga \"oi\" [[agora] {{b}"','text is escaped for Ren\'Py');

    // -----------------------------------------------------------------------------------------------------------------
    // The writer's state, and what it hears of the person's changes
    $state=$writer->openState($sid,$key);
    check(str_contains($state,'The visual novel (the person sees all of it')&&str_contains($state,'- cozinha "Na cozinha"')&&str_contains($state,'Sprites (')&&str_contains($state,'Afinidade vai de 0 a 5.'),'the writer\'s state has the visual novel');
    $requests=[];
    $w=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Oi.']],'end_turn'),claudeEvents([['type'=>'tool_use','id'=>'t1','name'=>'edit_scene','input'=>['scene'=>'segredo','summary'=>'O segredo do café.']]],'tool_use'),claudeEvents([['type'=>'text','text'=>'Feito.']],'end_turn')]),null);
    $w->run($w->send($sid,'Oi',[],$key)['id'],$key);
    $body=$requests[0]['body'];
    check(str_contains($body['system'][0]['text'],'## This project is a visual novel')&&in_array('write_scene',array_column($body['tools'],'name'),true)&&!in_array('draw_comic_page',array_column($body['tools'],'name'),true),'the writer of a visual novel gets its instructions and tools');
    check(str_contains(json_encode($body['messages'],JSON_UNESCAPED_UNICODE),'The visual novel (the person sees all of it'),'and the game in its first message');
    $first=$body['messages'][0]['content'][0]['text'];
    check(str_contains($first,"The scenes in full, as they are now:\n<scenes>\n<scene label=\"abertura\">")&&str_contains($first,'Ana (brava): Você demorou')&&str_contains($first,"<book>\n<chapter number=\"1\">")
        &&str_contains($first,'Base do capítulo.')&&strpos($first,'<scenes>')>strpos($first,'The visual novel (the person sees all of it'),'and every scene\'s script in full, after the outline, with the chapters\' base texts');
    $vn->updateScene($sid,'cozinha',['summary'=>'Mudado à mão.'],'person',$key);
    $vn->addScene($sid,['title'=>'Nova','label'=>'nova','script'=>'> Nova.'],null,'person',$key);
    $vn->writeGameplay($sid,['text'=>'Afinidade vai de 0 a 10.'],'person',$key);
    $w->run($w->send($sid,'Mudei umas coisas',[],$key)['id'],$key);
    $update=json_encode(end($requests[1]['body']['messages']),JSON_UNESCAPED_UNICODE);
    check(str_contains($update,'Scene cozinha \"Na cozinha\" was edited by the person')&&str_contains($update,'Scene nova \"Nova\" was added by the person')&&str_contains($update,'edited the gameplay notes')&&str_contains($update,'Afinidade vai de 0 a 10.'),'the writer hears of the scenes and notes the person changed');
    check(!str_contains($update,'Scene abertura'),'and not of what didn\'t change');
    check($vn->scene($sid,'segredo',$key)['by']==='agent','(the writer changed a scene)');
    $requests=[];
    $w2=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $w2->run($w2->send($sid,'E agora?',[],$key)['id'],$key);
    check(!str_contains(json_encode(end($requests[0]['body']['messages']),JSON_UNESCAPED_UNICODE),'Scene segredo'),'what the writer changed itself is not news to it');
    // A project too long to open whole: the route worked on last first, with the common scenes, then the others.
    $big=$stories->create('Rotas longas',$key,'vn')['id'];
    $vn->setSettings($big,['routes'=>['set'=>[['name'=>'Comum'],['name'=>'Rota da Ana'],['name'=>'Rota do Bruno']]]],'person',$key);
    $long=trim(str_repeat('> '.str_repeat('palavra ',6)."\n",1100));
    $vn->addScene($big,['title'=>'Início','route'=>'Comum','script'=>'> Tudo começa no café.'],null,'person',$key);
    foreach(['Ana','Bruno'] as $who)foreach(range(1,6) as $i)$vn->addScene($big,['title'=>"$who $i",'route'=>"Rota d".($who==='Ana'?'a':'o')." $who",'script'=>$long],null,'person',$key);
    sleep(1);$vn->updateScene($big,'bruno_3',['summary'=>'Bruno conta a verdade.'],'agent',$key);
    $requests=[];
    $w3=$writer->withTransports(player($requests,[claudeEvents([['type'=>'text','text'=>'Ok.']],'end_turn')]),null);
    $w3->run($w3->send($big,'Siga a rota do Bruno.',[],$key)['id'],$key);
    $first=$requests[0]['body']['messages'][0]['content'][0]['text'];
    check(str_contains($first,'(11 of the 13: first the route you worked on last, Rota do Bruno, with the common scenes;')&&str_contains($first,'<scene label="inicio">')
        &&str_contains($first,'<scene label="bruno_1">')&&str_contains($first,'<scene label="bruno_6">')&&str_contains($first,'<scene label="ana_4">')&&!str_contains($first,'<scene label="ana_5">')
        &&strpos($first,'<scene label="inicio">')<strpos($first,'<scene label="ana_1">')&&strpos($first,'<scene label="ana_4">')<strpos($first,'<scene label="bruno_1">'),
        'a project too long to open whole opens with the route worked on last and the common one, then what fits of the others, in the game\'s order');
    $stories->delete($big);

    // -----------------------------------------------------------------------------------------------------------------
    // Copies, files and documents brought in
    $copy=$writer->duplicate($sid,'Cópia',$key);
    check($copy['kind']==='vn'&&array_column($vn->scenes($copy['id'],$key),'label')===array_column($vn->scenes($sid,$key),'label')&&$vn->settings($copy['id'],$key)['variables']===$vn->settings($sid,$key)['variables']
        &&count($vn->art($copy['id'],$key)['sprites'])===count($vn->art($sid,$key)['sprites']),'a copy has the same scenes, settings and art');
    $vn->updateScene($copy['id'],'abertura',['title'=>'Mudada na cópia'],'person',$key);
    check($vn->scene($sid,'abertura',$key)['title']==='A Chegada','and changes on its own');
    $pack=$stories->export($sid,$key,$jobs);
    check($pack['story']['kind']==='vn'&&count($pack['vn']['scenes'])===7&&$pack['vn']['scenes'][1]['chapter']===null&&isset($pack['images'][$spriteImage])===false&&isset($pack['images'][$new]),'a project goes to a file with its scenes, the chapters by number, and its pictures');
    $made=$stories->import(json_decode(json_encode($pack),true),Writer::settings(null),$key);
    $in=$made['id'];
    check($stories->kind($in,$key)==='vn'&&array_column($vn->scenes($in,$key),'label')===array_column($vn->scenes($sid,$key),'label')&&$vn->scene($in,'cozinha',$key)['chapter']===$stories->chapters($in,$key)[0]['id']
        &&$vn->scene($in,'cozinha',$key)['route']===$vn->settings($in,$key)['routes'][1]['id']&&count($vn->art($in,$key)['backgrounds'])===count($vn->art($sid,$key)['backgrounds'])&&isset($made['map'][$vn->scene($sid,'cozinha',$key)['id']]),'and back, as a new project, with its chapters, routes and art');
    check(Vn::spriteFor($vn->art($in,$key)['sprites'],$stories->cast($in,$key)['characters'][0]['id'],'feliz')!==null,'the sprites still belong to their character');
    $counts=$vn->importDocuments($sid,[['to'=>'notes','title'=>'Documento de design','text'=>'Uma VN sobre um café.'],['to'=>'chapter','title'=>'Capítulo 2','text'=>'Base do capítulo 2.'],
        ['to'=>'character','title'=>'Bruno','text'=>"O dono do café, 40 anos.\n\nTem um segredo."],['to'=>'gameplay','title'=>'Afinidade','text'=>'Cada escolha certa vale 1.'],['to'=>'place','title'=>'Terraço','text'=>'O terraço do prédio.']],'person',$key);
    $story=$stories->get($sid,$key);
    check($counts===['notes'=>1,'plan'=>0,'gameplay'=>1,'chapters'=>1,'characters'=>1,'places'=>1]&&str_contains($story['notes']['text'],"## Documento de design\n\nUma VN sobre um café.")&&str_contains($story['notes']['text'],"## Bruno\n\nO dono do café, 40 anos.\n\nTem um segredo.")
        &&$story['chapters'][1]['title']==='Capítulo 2'&&Stories::findCharacter($story['cast']['characters'],'Bruno')!==null&&$story['cast']['characters'][Stories::findCharacter($story['cast']['characters'],'Bruno')]['note']==='O dono do café, 40 anos.'
        &&$story['cast']['characters'][Stories::findCharacter($story['cast']['characters'],'Terraço')]['kind']==='place'&&str_contains($vn->settings($sid,$key)['gameplay']['text'],'## Afinidade'),'the person\'s documents go to the design document, chapters, sheets and gameplay notes');
    rejects(fn()=>$vn->importDocuments($sid,[['to'=>'chapter','text'=>'sem título']],'person',$key),'a chapter brought in needs a title',422);
    rejects(fn()=>$vn->importDocuments($plain,[['to'=>'notes','text'=>'x']],'person',$key),'documents come into visual novel projects only',422);

    // -----------------------------------------------------------------------------------------------------------------
    // Over MCP
    $mcp=new Mcp($vault,$jobs,$key,'https://example.test/',['api_timeout'=>60],$api);
    $call=fn(string $name,array $arguments)=>$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$arguments]])['result'];
    $said=fn(array $r)=>implode("\n",array_column(array_filter($r['content'],fn($c)=>$c['type']==='text'),'text'));
    $listed=array_column($mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'])['result']['tools'],null,'name');
    check(isset($listed['write_scene'],$listed['make_sprite'],$listed['draw_comic_page'])&&$listed['check_vn']['annotations']['readOnlyHint']===true&&$listed['delete_scene']['annotations']['destructiveHint']===true,'MCP lists the visual novel\'s tools too');
    $made=json_decode($call('create_story',['title'=>'VN pelo MCP','kind'=>'vn'])['content'][0]['text'],true)['story_id'];
    check($stories->kind($made,$key)==='vn','create_story makes a visual novel project');
    $open=$said($call('open_story',['story_id'=>$made]));
    check(str_contains($open,'## This project is a visual novel')&&str_contains($open,'The visual novel (the person sees all of it'),'open_story gives a visual novel\'s instructions and state');
    $op=fn(string $id)=>preg_match('/^opened: (\S+)/m',$said($call('open_story',['story_id'=>$id])),$mm)?$mm[1]:'';
    $r=$call('write_scene',['story_id'=>$made,'opened'=>$op($made),'title'=>'Começo','script'=>'> Era uma vez.','exit'=>['type'=>'end','title'=>'Fim','kind'=>'normal']]);
    check(empty($r['isError'])&&$vn->scene($made,'comeco',$key)['by']==='mcp','write_scene works over MCP');
    check(!empty($call('write_scene',['story_id'=>$plain,'opened'=>$op($plain),'title'=>'x','script'=>'> x'])['isError']),'and refuses a story that isn\'t a visual novel');
    $listing=json_decode($call('list_stories',[])['content'][0]['text'],true)['stories'];
    check(array_column($listing,'kind','id')[$made]==='vn','list_stories says which are visual novels');

    $stories->delete($sid);
    check((int)$vault->db->query("SELECT COUNT(*) FROM vn_scenes WHERE story_id='$sid'")->fetchColumn()===0,'deleting a project deletes its scenes');
    echo "\n$count visual novel checks passed. No real API calls.\n";
} finally {
    if(is_dir($dir)){ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); rmdir($dir); }
}
