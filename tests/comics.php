<?php
declare(strict_types=1);
// The comic adaptation of a story: the storyboard sketches, the pages' scripts, the writer's tools and the drawing of
// a whole page in one request, with a fake image API: no real API calls.
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Vault,OpenAI,Jobs,Users,HttpError,Stories,Writer,Comics,Sketch,ToolContext};

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
/** The colour of the pixel at x, y of a PNG, as [r, g, b]. */
function pixel(string $png,int $x,int $y): array { $im=imagecreatefromstring($png); $c=imagecolorat($im,$x,$y); return [($c>>16)&255,($c>>8)&255,$c&255]; }

$dir=sys_get_temp_dir().'/imagegen-comics-'.bin2hex(random_bytes(6));
$key=random_bytes(32);
try{
    // -----------------------------------------------------------------------------------------------------------------
    // The storyboard: a plain subset of SVG drawn with GD alone
    check(Sketch::units('2:3')===[1000,1500]&&Sketch::units('3:4')===[1000,1333]&&Sketch::units('1:1')===[1000,1000],'the sketch is 1000 units wide and as tall as the page\'s shape');
    rejects(fn()=>Sketch::check(''),'an empty sketch is refused',422);
    rejects(fn()=>Sketch::check('<html><body>oi</body></html>'),'a sketch must be SVG',422,'not SVG');
    rejects(fn()=>Sketch::check('<svg viewBox="0 0 10 10"><image href="x.png"/></svg>'),'a sketch that draws nothing drawable is refused',422,'draws nothing');
    rejects(fn()=>Sketch::check('<svg viewBox="0 0 10 10">'.str_repeat('<rect width="1" height="1"/>',30000).'</svg>'),'a sketch too long is refused',422);
    $svg='<?xml version="1.0"?><!-- a page --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1500">'
        .'<rect x="40" y="40" width="920" height="700" fill="none" stroke="#000" stroke-width="6"/>'
        .'<g transform="translate(500 400) rotate(10)"><circle r="60" fill="rgb(255,0,0)"/></g>'
        .'<path d="M100 1000 C 300 900, 700 1100, 900 1000 a 50 50 0 01 -50 50 Z" style="fill:#00f;stroke:black;stroke-width:4"/>'
        .'<line x1="100" y1="1300" x2="900" y2="1300" stroke="green" stroke-width="10" stroke-dasharray="40 20" marker-end="url(#a)"/>'
        .'<text x="500" y="1450" font-size="60" text-anchor="middle">AÇÃO! <tspan fill="red">É</tspan></text>'
        .'<script>alert(1)</script><foreignObject><div>x</div></foreignObject></svg>';
    $png=Sketch::png(Sketch::check($svg),400,600);
    $info=getimagesizefromstring($png);
    check($info[0]===400&&$info[1]===600&&$info['mime']==='image/png','a sketch becomes a PNG of the size asked');
    check(pixel($png,200,160)[0]>200&&pixel($png,200,160)[1]<60,'shapes are drawn where they are, transforms included');
    check(pixel($png,5,5)===[255,255,255]&&pixel($png,16,16)[0]<90,'on white, with the panel borders in black');
    $wide=Sketch::png('<svg viewBox="0 0 100 100"><rect width="100" height="100" fill="#000"/></svg>',200,100);
    check(pixel($wide,10,50)===[255,255,255]&&pixel($wide,100,50)[0]<10,'a drawing of another shape is fitted without distortion');
    rejects(fn()=>Sketch::png('<svg viewBox="0 0 10 10">'.str_repeat('<polyline points="'.str_repeat('1,1 ',300).'"/>',2500).'</svg>',100,100),'a drawing too detailed is refused before it takes too long',422);
    $grid=Sketch::grid(5,[2,1,2],true,1000,1500);
    check(count($grid)===5&&$grid[0]['x']>$grid[1]['x']&&$grid[0]['y']===$grid[1]['y']&&$grid[2]['w']>900&&$grid[3]['x']>$grid[4]['x'],'the app\'s layout reads right to left for a manga, row by row');
    check(Sketch::defaultRows(5)===[2,1,2]&&array_sum(Sketch::defaultRows(12))===12&&array_sum(Sketch::defaultRows(13))>=13,'a page of any size has a default layout');

    // -----------------------------------------------------------------------------------------------------------------
    // The adaptation and its pages
    $vault=new Vault($dir);$users=new Users($vault);
    $owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $stories=new Stories($vault);$jobs=new Jobs($vault,['api_timeout'=>60,'max_parallel'=>4]);
    $writer=new Writer($vault,$stories,$jobs,['api_timeout'=>60]);
    $comics=$stories->comics();
    $sid=$stories->create('O Farol',$key)['id'];
    $stories->update($sid,['writer'=>Writer::settings(null,['image_model'=>'gpt-image-2'])],$key);
    $one=$stories->addChapter($sid,'A chegada','Ana chega ao farol. Bruno a espera.','',null,'person',$key);
    $s=$comics->settings($sid,$key);
    check(!$s['started']&&$s['kind']==='manga'&&$s['reading']==='rtl'&&$s['color']==='bw'&&$s['aspect']==='2:3'&&$s['scale']==='2','an adaptation starts as a black and white manga of 2:3 pages at twice the size');
    rejects(fn()=>$comics->setSettings($sid,['kind'=>'novel'],'person',$key),'only manga or comic',422);
    $s=$comics->setSettings($sid,['kind'=>'comic','reading'=>'ltr','color'=>'color','scale'=>1.5],'person',$key);
    check($s['started']&&$s['kind']==='comic'&&$s['scale']==='1.5'&&$s['rev']===1&&$s['by']==='person','the settings change, and the story has an adaptation');
    $own=$stories->cast($sid,$key,'comic');
    check($own['characters']===[]&&$own['rev']===0&&$own['style']['lettering']['text']===''&&$stories->readDocument($sid,'comic_notes',$key)['text']===''&&$stories->readDocument($sid,'comic_plan',$key)['rev']===0,
        'a new adaptation starts with a workspace of its own, empty: no sheets, no lettering, no bible, no plan');
    check(str_contains(Comics::settingsLine($s),'comic book, full color, read left to right'),'the writer reads the settings in a line');
    // A graphic novel is a language of its own, beside the comic book: its pages and its cover say so
    $s=$comics->setSettings($sid,['kind'=>'graphic_novel'],'person',$key);
    check($s['kind']==='graphic_novel'&&str_contains(Comics::settingsLine($s),'graphic novel, full color, read left to right'),'a graphic novel is a kind of its own');
    $d=$stories->get($sid,$key)['cover_design'];
    check($d['format']==='graphic_novel'&&$d['why']==='comic'&&$d['volume']==='','its covers are a graphic novel\'s, a book with no volume number');
    $gn=Writer::pagePrompt(['number'=>1,'summary'=>'','notes'=>'','panels'=>[['framing'=>'','place'=>'','characters'=>[],'description'=>'Rain.','text'=>[['type'=>'sfx','by'=>'','text'=>'TAP','where'=>'']]]],'rows'=>null],$s,[],[],null,[],'',1);
    check(str_contains($gn,'One complete, finished graphic novel page, as printed in a graphic novel')&&str_contains($gn,'in the graphic novel way'),'its page is drawn as a graphic novel\'s');
    $comics->setSettings($sid,['kind'=>'manga','reading'=>'rtl','color'=>'bw','scale'=>'2'],'person',$key);

    $panels=[
        ['framing'=>'wide shot, low angle','place'=>'O Farol','characters'=>[['name'=>'Ana','where'=>'left, foreground','doing'=>'looks up at the tower, worried']],
            'description'=>'Dusk, waves crash on the rocks.','text'=>[['type'=>'caption','text'=>'Naquela noite, ninguém dormiu.'],['type'=>'sfx','by'=>'the waves','text'=>'SPLAASH','where'=>'huge, behind Ana']]],
        ['framing'=>'close-up','characters'=>[['name'=>'Bruno','doing'=>'smiles']],'text'=>[['type'=>'speech','by'=>'Bruno','text'=>'Você veio, afinal.'],['type'=>'thought','by'=>'Bruno','text'=>'Ela não sabe.']]],
    ];
    rejects(fn()=>$comics->addPage($sid,['summary'=>'x','panels'=>[]],null,'agent',$key),'a page needs panels',422);
    rejects(fn()=>$comics->addPage($sid,['summary'=>'x','panels'=>[['description'=>'x','text'=>[['type'=>'song','text'=>'la']]]]],null,'agent',$key),'a text is of a known kind',422);
    rejects(fn()=>$comics->addPage($sid,['summary'=>'x','panels'=>$panels,'rows'=>[1,2]],null,'agent',$key),'rows must add up to the panels',422,'soma 3');
    rejects(fn()=>$comics->addPage($sid,['summary'=>'x','panels'=>[['framing'=>'wide']]],null,'agent',$key),'a panel must show something',422);
    $p1=$comics->addPage($sid,['summary'=>'Ana chega ao farol.','panels'=>$panels,'chapter'=>$one['id'],'notes'=>'Cold blue dusk.'],null,'agent',$key);
    check($p1['number']===1&&$p1['rev']===1&&$p1['chapter']===$one['id']&&count($p1['panels'])===2&&$p1['panels'][1]['characters'][0]['where']===''&&$p1['sketch']==='','a page keeps its script, panels trimmed to what is kept');
    $b=Comics::balloons($p1);
    check(array_column($b,'label')===['B1',null,'B2','B3'],'the balloons are numbered in reading order, sound effects aside');
    $p0=$comics->addPage($sid,['summary'=>'Prólogo.','panels'=>[['description'=>'The sea, empty.']],'rows'=>[1]],0,'agent',$key);
    check(array_column($comics->pages($sid,$key),'summary')===['Prólogo.','Ana chega ao farol.']&&$comics->pageAt($sid,2,$key)['id']===$p1['id'],'a page can go first; pages are numbered in order');
    $comics->movePage($sid,$p0['id'],1,'person',$key);
    check(array_column($comics->pages($sid,$key),'summary')===['Ana chega ao farol.','Prólogo.'],'a page moves');
    $comics->deletePage($sid,$p0['id'],'person',$key);
    check(count($comics->pages($sid,$key))===1,'a page is deleted');
    $saved=$comics->updatePage($sid,$p1['id'],['summary'=>'Ana chega ao farol, de noite.'],'person',$key,1);
    check($saved['rev']===2&&$saved['panels']===$p1['panels'],'a change by hand makes a new revision and keeps the rest');
    rejects(fn()=>$comics->updatePage($sid,$p1['id'],['summary'=>'x'],'person',$key,1),'a change from an old revision is refused',409);
    $svg1='<svg viewBox="0 0 1000 1500"><rect x="40" y="40" width="920" height="700" fill="#fff" stroke="#000" stroke-width="6"/><text x="60" y="100" font-size="40">1</text></svg>';
    $sketch=Comics::sketchPng($comics->updatePage($sid,$p1['id'],['sketch'=>$svg1],'agent',$key),$comics->settings($sid,$key));
    check(getimagesizefromstring($sketch)[0]===1024&&getimagesizefromstring($sketch)[1]===1536,'the sketch goes to the image model at the usual size of the page\'s shape');
    rejects(fn()=>$comics->updatePage($sid,$p1['id'],['sketch'=>'<p>oi</p>'],'agent',$key),'a sketch that isn\'t SVG is refused before it is kept',422);
    $view=$comics->view($sid,$key);
    check(!isset($view['pages'][0]['sketch'])&&$view['pages'][0]['own_sketch']===true&&$view['settings']['kind']==='manga','the page gets the pages without the sketches\' SVG');

    // -----------------------------------------------------------------------------------------------------------------
    // The writer's tools
    $png=null;
    if(true){ $im=imagecreatetruecolor(64,96); imagefill($im,0,0,imagecolorallocate($im,200,200,200)); ob_start(); imagepng($im); $png=ob_get_clean(); }
    $sent=[];
    $api=new OpenAI('test',60,function($path,$body) use ($png,&$sent){ $sent[]=['path'=>$path,'body'=>$body]; return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]]; });
    $jobOf=fn(string $id)=>array_values(array_filter($jobs->list($key,'active',[$id]),fn($j)=>$j['id']===$id))[0];
    $wi=$writer->withImageApi($api);
    $ctx=new ToolContext($sid,str_repeat('e',32),Writer::settings(null,['image_model'=>'gpt-image-2']),$key);
    $names=array_column(Writer::claudeTools(),'name');
    foreach(['comic_settings','read_comic','write_comic_page','edit_comic_page','view_comic_page','draw_comic_page','delete_comic_page','move_comic_page'] as $t) check(in_array($t,$names,true),"the writer has $t");
    check(str_contains(Writer::SYSTEM,'## Comics and manga')&&str_contains(Writer::SYSTEM,'sketch_svg'),'the writer\'s instructions explain the comic and its sketches');
    $comicsPart=substr(Writer::SYSTEM,strpos(Writer::SYSTEM,'## Comics and manga'),strpos(Writer::SYSTEM,'## Replies')-strpos(Writer::SYSTEM,'## Comics and manga'));
    check(str_contains($comicsPart,'### An adaptation, not a transcription')&&str_contains($comicsPart,'Read the whole story before planning anything')
        &&str_contains($comicsPart,'- Reorder:')&&str_contains($comicsPart,'- Cut:')&&str_contains($comicsPart,'- Add:')&&str_contains($comicsPart,'- Edit:')&&str_contains($comicsPart,'its own plan (update_plan with comic: true)'),
        'the writer adapts: reads the whole story, then reorders, cuts, adds and edits for the medium, with a plan of the adaptation');
    check(str_contains($comicsPart,'### Everything the medium has')&&str_contains($comicsPart,'black gutters')&&str_contains($comicsPart,'chibi')&&str_contains($comicsPart,'speed lines')
        &&str_contains($comicsPart,'A manga is')&&str_contains($comicsPart,'A comic is')&&str_contains($comicsPart,'A graphic novel is'),'and uses the tools of each medium: manga, comic book and graphic novel');
    $kindTool=array_values(array_filter(Writer::claudeTools(),fn($t)=>$t['name']==='comic_settings'))[0]['input_schema']['properties']['kind'];
    check($kindTool['enum']===['manga','comic','graphic_novel']&&str_contains($kindTool['description'],'graphic_novel'),'the writer can set up a graphic novel');
    [$ok,$out,$label]=$wi->runTool('comic_settings',['kind'=>'manga','style'=>'Clean shoujo manga ink, fine lines, soft screentones.'],$ctx);
    check($ok&&$label==='Mudou as configurações dos quadrinhos'&&str_contains($out,'viewBox="0 0 1000 1500"')&&str_contains($out,'Clean shoujo'),'the writer sets the comic up, and learns the sketches\' units');
    [$ok,$out,$label]=$wi->runTool('write_comic_page',['chapter'=>1,'summary'=>'Bruno e Ana no alto do farol.','panels'=>[
        ['framing'=>'medium shot','place'=>'O Farol','characters'=>[['name'=>'Ana','where'=>'right'],['name'=>'Bruno','where'=>'left']],'description'=>'They face each other.','text'=>[['type'=>'shout','by'=>'Ana','text'=>'Por que mentiu?']]],
        ['framing'=>'extreme close-up','characters'=>[['name'=>'Bruno']],'description'=>'His eyes.']],'rows'=>[2]],$ctx);
    check($ok&&$label==='Escreveu a página 2 dos quadrinhos (2 quadros)'&&is_array($out)&&$out[1]['type']==='image'&&imagecreatefromstring(base64_decode($out[1]['source']['data']))!==false,'the writer writes a page and sees the sketch the app drew');
    check(str_contains($out[0]['text'],'Before it can be drawn, the adaptation\'s sheets need a picture of: Ana, Bruno, O Farol (place)')&&str_contains($out[0]['text'],'from_book: true')&&str_contains($out[0]['text'],'B1 shout, Ana: "Por que mentiu?"'),'and hears which sheets the page still needs');
    [$ok,$out]=$wi->runTool('write_comic_page',['summary'=>'x','panels'=>[['description'=>'x']],'sketch_svg'=>'<div/>'],$ctx);
    check(!$ok&&str_contains($out,'not SVG'),'a sketch the app can\'t draw is refused, and the page isn\'t kept');
    check(count($comics->pages($sid,$key))===2,'(nothing was added)');
    [$ok,$out]=$wi->runTool('draw_comic_page',['page'=>2],$ctx);
    check(!$ok&&str_contains($out,'Ana, Bruno, O Farol (place) have no picture in the adaptation\'s sheets')&&!$ctx->jobs,'a page with characters or places without a sheet is not drawn, and costs nothing');

    // Sheets for everyone, the lettering and its sample
    $lib=[];
    foreach(['ana','bruno','farol','farol2','letras','ana2'] as $n){ $one=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>"imagem $n",'options'=>['model'=>'gpt-image-2','quality'=>'low','size'=>'1024x1024','n'=>1,'previews'=>1]],$key)[0]; $lib[$n]=$jobs->run($one['id'],$key,$api)['image_ids'][0]; }
    $stories->updateCast($sid,['character'=>'Ana','add'=>[['image'=>$lib['ana'],'caption'=>'Aos 20'],['image'=>$lib['ana2'],'caption'=>'De capa de chuva']]],'person',$key);
    $stories->updateCast($sid,['character'=>'Bruno','add'=>[['image'=>$lib['bruno']]]],'person',$key);
    $stories->updateCast($sid,['character'=>'O Farol','kind'=>'place','add'=>[['image'=>$lib['farol']],['image'=>$lib['farol2']]]],'person',$key);
    $stories->updateStyle($sid,['text'=>'Painterly watercolor.','lettering'=>'Elegant book serif.'],'person',$key);
    // The book's sheets are the adaptation's reference only: the pages are drawn with the adaptation's own
    [$ok,$out]=$wi->runTool('draw_comic_page',['page'=>2],$ctx);
    check(!$ok&&str_contains($out,'have no picture in the adaptation\'s sheets')&&!$ctx->jobs,'the book\'s sheets don\'t draw the pages: the adaptation\'s own do');
    [$ok,$out]=$wi->runTool('update_character',['character'=>'Ana','from_book'=>true],$ctx);
    check(!$ok&&str_contains($out,'from_book goes with comic: true'),'a sheet is brought from the book into the adaptation only');
    [$ok,$out,$label]=$wi->runTool('update_character',['character'=>'ana','comic'=>true,'from_book'=>true],$ctx);
    $own=$stories->cast($sid,$key,'comic');
    check($ok&&$label==='Trouxe a ficha de Ana do livro para a adaptação'&&str_contains($out,'(2 pictures)')&&$own['characters'][0]['name']==='Ana'&&array_column($own['characters'][0]['images'],'image')===[$lib['ana'],$lib['ana2']]
        &&$own['characters'][0]['images'][1]['caption']==='De capa de chuva'&&$own['by']==='agent'&&$own['rev']===1,'the writer brings the book\'s sheet of Ana into the adaptation, free, with its pictures and captions');
    [$ok,$out]=$wi->runTool('update_character',['character'=>'Ana','comic'=>true,'from_book'=>true],$ctx);
    check($ok&&count($stories->cast($sid,$key,'comic')['characters'][0]['images'])===2,'bringing it again adds only what the adaptation lacks');
    [$ok,$out,$label]=$wi->runTool('update_character',['character'=>'Ana','comic'=>true,'note'=>'Cabelo curto nos quadrinhos.','captions'=>[['image_id'=>$lib['ana'],'caption'=>'Aos 20, mangá']]],$ctx);
    $book=$stories->cast($sid,$key);
    check($ok&&$label==='Atualizou a ficha da adaptação de Ana'&&$stories->cast($sid,$key,'comic')['characters'][0]['note']==='Cabelo curto nos quadrinhos.'&&$book['characters'][0]['note']===''&&$book['characters'][0]['images'][0]['caption']==='Aos 20',
        'the adaptation\'s sheet changes on its own: the book\'s stays as it was');
    $stories->sheetFromBook($sid,'Bruno','person',$key); $stories->sheetFromBook($sid,'O Farol','person',$key);
    check(array_column($stories->cast($sid,$key,'comic')['characters'],'kind')===['character','character','place'],'the person brings sheets too, with their kind');
    rejects(fn()=>$stories->sheetFromBook($sid,'Ninguém','person',$key),'only a sheet the book has',404);
    [$ok,$out,$label]=$wi->runTool('set_style',['comic'=>true,'lettering'=>'Hand-lettered manga capitals.','lettering_image'=>$lib['letras']],$ctx);
    check($ok&&$label==='Definiu as letras da adaptação'&&str_contains($out,'Lettering of the comic (the app adds it to every page): "Hand-lettered manga capitals."; sample image '.$lib['letras'])
        &&$stories->cast($sid,$key,'comic')['style']['lettering']['image']===$lib['letras']&&$stories->cast($sid,$key)['style']['lettering']===['text'=>'Elegant book serif.','image'=>null,'job'=>null],'the adaptation has letters of its own, apart from the book\'s');
    [$ok,$out]=$wi->runTool('edit_comic_page',['page'=>2,'panel_number'=>2,'panel'=>['framing'=>'extreme close-up','characters'=>[['name'=>'Bruno','sheet'=>str_repeat('a',32)]],'description'=>'His eyes.']],$ctx);
    check($ok&&str_contains(is_array($out)?$out[0]['text']:$out,'is not a picture of the adaptation\'s sheet of Bruno'),'a form that isn\'t in the character\'s sheet is pointed out');
    [$ok,$out]=$wi->runTool('edit_comic_page',['page'=>2,'panel_number'=>1,'panel'=>['framing'=>'medium shot','place'=>'o farol','characters'=>[['name'=>'ana','where'=>'right','sheet'=>$lib['ana2']],['name'=>'Bruno','where'=>'left']],'description'=>'They face each other.','text'=>[['type'=>'shout','by'=>'Ana','text'=>'Por que mentiu?']]]],$ctx);
    [$ok]=$wi->runTool('edit_comic_page',['page'=>2,'panel_number'=>2,'panel'=>['framing'=>'extreme close-up','characters'=>[['name'=>'Bruno']],'description'=>'His eyes.']],$ctx);
    check($ok&&$comics->pageAt($sid,2,$key)['panels'][0]['characters'][0]['sheet']===$lib['ana2'],'the writer changes one panel, naming the form of the character');

    // Page 1 drawn by the writer, page 2 waits for it and takes it as the previous page
    [$ok,$out,$label]=$wi->runTool('draw_comic_page',['page'=>1],$ctx);
    $first=end($ctx->jobs); $job=$jobOf($first);
    check($ok&&$label==='Pediu o desenho da página 1 dos quadrinhos'&&$job['options']['size']==='2048x3072'&&$job['label']==='Página 1 · O Farol','the writer draws a page at the comic\'s size');
    $prompt=$job['prompt'];
    check(str_contains($prompt,'One complete, finished manga page')&&str_contains($prompt,'page 1 of 2')&&str_contains($prompt,'from right to left')&&str_contains($prompt,'black and white'),'the prompt says what the page is');
    check(str_contains($prompt,'Attached image 1 is a rough storyboard sketch of this page')&&str_contains($prompt,'Nothing of the sketch itself shows on the page'),'the sketch goes first, as a guide to the composition only');
    check(str_contains($prompt,"Panel 1 — wide shot, low angle. Setting: O Farol (attached image 4).\n- Ana (attached image 2), left, foreground: looks up at the tower, worried.")
        &&str_contains($prompt,'- Caption box B1: "Naquela noite, ninguém dormiu."')&&str_contains($prompt,'- Sound effect (the sound of the waves): "SPLAASH" — huge, behind Ana')
        &&str_contains($prompt,'- Speech balloon B2, from Bruno: "Você veio, afinal."')&&str_contains($prompt,'- Thought balloon B3, from Bruno: "Ela não sabe."'),'each panel with its setting, characters and exact words, balloons numbered as in the sketch');
    check(str_contains($prompt,'The characters\' reference sheets: Ana is attached image 2 (Aos 20, mangá); Bruno is attached image 3')&&str_contains($prompt,'O Farol is attached image 4'),'the adaptation\'s sheets of the page\'s characters and places go as references, named');
    check(str_contains($prompt,'Notes for the whole page: Cold blue dusk.')&&str_contains($prompt,'The story\'s visual style, the same in all of its pictures: Clean shoujo manga ink')&&!str_contains($prompt,'Painterly'),'the comic\'s own style wins over the story\'s');
    check(str_contains($prompt,'Attached image 5 is the lettering reference')&&$job['reference_count']===5&&str_contains($prompt,'Letter every balloon, caption, sign and sound effect above exactly as written'),'the lettering\'s sample goes last, and every word is to be lettered exactly');
    check($comics->pageAt($sid,1,$key)['job']===$first&&$comics->pageAt($sid,1,$key)['drawn']===3,'the page waits for its drawing, from the script as it is');
    [$ok,$out]=$wi->runTool('draw_comic_page',['page'=>1],$ctx);
    check(!$ok&&str_contains($out,'already being drawn'),'a page is not drawn twice at once');
    $sent=[];
    [$ok,$out]=$wi->runTool('draw_comic_page',['page'=>2,'instruction'=>'Make the rain heavier.','references'=>[$lib['farol2']]],$ctx);
    $second=end($ctx->jobs); $prompt2=$jobOf($second)['prompt'];
    check($ok&&$jobOf($first)['status']==='completed'&&count($sent)===1,'the next page waits for the previous one to be drawn');
    check(str_contains($prompt2,'- ana (attached image 2), right: ')===false&&str_contains($prompt2,'- ana (attached image 2), right.')&&str_contains($prompt2,'Ana is attached image 2 (De capa de chuva)'),'the form named in the script is the one sent');
    check(str_contains($prompt2,'Attached image 5 is the previous page of this manga')&&str_contains($prompt2,'Attached image 6 is a further reference (see the notes below).')&&str_contains($prompt2,'For this drawing: Make the rain heavier.')
        &&$jobOf($second)['reference_count']===7,'with the previous page, the writer\'s other references and notes for this drawing');
    $story=$stories->get($sid,$key,$jobs);
    $page1=$comics->pageAt($sid,1,$key);
    check(is_string($page1['image'])&&$page1['job']===null&&$vault->image($page1['image'],$key)['label']==='Página 1 · O Farol','the drawing becomes the page');

    // The writer looks, fixes a detail, reads the whole adaptation
    [$ok,$out,$label]=$wi->runTool('view_comic_page',['page'=>1,'svg'=>true],$ctx);
    check($ok&&count($out)===4&&$out[1]['type']==='image'&&$out[3]['type']==='image'&&str_contains($out[0]['text'],'Sketch SVG:')&&str_contains($out[0]['text'],'<svg viewBox="0 0 1000 1500">')&&str_contains($out[2]['text'],$page1['image']),'the writer sees the script, the SVG, the sketch and the drawn page');
    [$ok,$out,$label]=$wi->runTool('edit_illustration',['image_id'=>$page1['image'],'instruction'=>'Fix Ana\'s left hand.'],$ctx);
    check($ok&&$label==='Pediu uma nova versão da página (página 1 dos quadrinhos)','the writer fixes a drawn page with edit_illustration');
    $jobs->run(end($ctx->jobs),$key,$api); $stories->get($sid,$key,$jobs);
    $fixed=$comics->pageAt($sid,1,$key)['image'];
    check($fixed!==$page1['image']&&$vault->image($fixed,$key)['parent_id']===$page1['image'],'the new version takes the page\'s place, as a version of it');
    // The adaptation has an album of its own, out of the library: its pages (and their versions) go there, not to the story's.
    $albums=$stories->get($sid,$key)['albums']; $listed=array_column($vault->albums($key),null,'id');
    $inAlbum=fn(?string $album)=>$album===null?[]:array_column($vault->library($key,['album'=>$album])['items'],0);
    check($albums['comic']!==null&&$listed[$albums['comic']]['kind']==='comic'&&$listed[$albums['comic']]['story']===$sid&&$listed[$albums['comic']]['library']===false&&$listed[$albums['comic']]['name']==='O Farol (mangá)'
        &&!array_diff([$page1['image'],$fixed],$inAlbum($albums['comic']))&&!array_intersect([$page1['image'],$fixed],$inAlbum($albums['book']))&&!array_intersect([$page1['image'],$fixed],array_column($vault->library($key,[])['items'],0)),'the pages and their versions go into the adaptation\'s own album, named for its kind, out of the library and of the story\'s album');
    check(!array_intersect(array_values($lib),$inAlbum($albums['comic']))&&!array_diff(array_values($lib),array_column($vault->library($key,[])['items'],0)),'the library pictures its sheets use stay in the library');
    $comics->setSettings($sid,['kind'=>'graphic_novel'],'person',$key);
    check(array_column($vault->albums($key),'name','id')[$albums['comic']]==='O Farol (graphic novel)','the album says the adaptation\'s new kind');
    $comics->setSettings($sid,['kind'=>'manga'],'person',$key);
    [$ok,$out]=$wi->runTool('read_comic',['pages'=>[2]],$ctx);
    check($ok&&str_contains($out,'Comic adaptation: manga, black and white, read right to left, pages 2:3 at 2× the usual size.')&&str_contains($out,'Page 1 (chapter 1): Ana chega ao farol, de noite. — 2 panels; drawn: image '.$fixed)
        &&str_contains($out,'Page 2 (chapter 1): Bruno e Ana no alto do farol. — 2 panels; being drawn')&&str_contains($out,'Page 2 (adapts chapter 1), revision'),'read_comic shows every page and the scripts asked for');
    $jobs->run($second,$key,new OpenAI('test',60,function(){ throw new HttpError(400,'Recusado.'); }));
    [$ok,$out]=$wi->runTool('read_comic',[],$ctx);
    check(str_contains($out,'Page 2 (chapter 1): Bruno e Ana no alto do farol. — 2 panels; the drawing failed'),'a drawing that failed is told to the writer');
    [$ok,$out]=$wi->runTool('move_comic_page',['page'=>2,'to'=>1],$ctx);
    check($ok&&$comics->pageAt($sid,1,$key)['summary']==='Bruno e Ana no alto do farol.','the writer moves a page');
    [$ok,$out]=$wi->runTool('move_comic_page',['page'=>1,'to'=>9],$ctx);
    check(!$ok,'not past the last page');
    $comics->movePage($sid,$comics->pageAt($sid,1,$key)['id'],1,'agent',$key);

    // -----------------------------------------------------------------------------------------------------------------
    // The adaptation's own workspace: its plan and bible, sheets in the comic's look, what the writer reads of it
    $stories->writeDocument($sid,'notes',['text'=>'BÍBLIA DO LIVRO: Ana tem cabelo longo.'],'person',$key);
    [$ok,$out,$label]=$wi->runTool('update_plan',['comic'=>true,'text'=>'ADAPTAÇÃO: abrir no farol; juntar dois capítulos.'],$ctx);
    check($ok&&$label==='Atualizou o planejamento da adaptação (oculto)'&&str_contains($out,'Adaptation plan saved (revision 1')&&$stories->readDocument($sid,'plan',$key)['text']===''
        &&$stories->readDocument($sid,'comic_plan',$key)['by']==='agent','the writer plans the adaptation in its own plan, apart from the book\'s');
    [$ok,$out,$label]=$wi->runTool('update_notes',['comic'=>true,'append'=>'Nos quadrinhos, Ana tem cabelo curto.'],$ctx);
    check($ok&&$label==='Atualizou a bíblia da adaptação'&&$stories->readDocument($sid,'comic_notes',$key)['text']==='Nos quadrinhos, Ana tem cabelo curto.'&&$stories->readDocument($sid,'notes',$key)['text']==='BÍBLIA DO LIVRO: Ana tem cabelo longo.','and keeps the adaptation\'s bible, the book\'s staying as it is');
    [$ok,$out,$label]=$wi->runTool('read_notes',['comic'=>true],$ctx);
    check($ok&&$label==='Leu a bíblia da adaptação'&&str_contains($out,'Adaptation bible, revision 1')&&str_contains($out,'cabelo curto'),'it reads the adaptation\'s bible');
    [$ok,$out]=$wi->runTool('read_notes',[],$ctx);
    check($ok&&str_contains($out,'BÍBLIA DO LIVRO'),'and still the book\'s, for reference');
    [$ok,$out]=$wi->runTool('search_story',['query'=>'abrir no farol'],$ctx);
    check($ok&&str_contains($out,'[adaptation plan]'),'searching the story looks in the adaptation\'s plan and bible too');
    [$ok,$out]=$wi->runTool('get_outline',[],$ctx);
    check($ok&&str_contains($out,'Adaptation bible: revision 1')&&str_contains($out,"The adaptation's lettering and sheets:\nLettering of the comic (the app adds it to every page)"),'the outline shows the adaptation\'s workspace');
    $open=$writer->openState($sid,$key);
    check(str_contains($open,"<adaptation_bible>\nNos quadrinhos, Ana tem cabelo curto.\n</adaptation_bible>")&&str_contains($open,"<adaptation_plan>\nADAPTAÇÃO: abrir no farol")&&str_contains($open,'BÍBLIA DO LIVRO')
        &&substr_count($open,'Lettering of the comic (the app adds it to every page)')===1,'the writer sees the book\'s workspace and the adaptation\'s, each in its place');
    $other=$stories->create('Sem quadrinhos',$key)['id'];
    $octx=new ToolContext($other,str_repeat('f',32),Writer::settings(null,['image_model'=>'gpt-image-2']),$key);
    [$ok,$out]=$wi->runTool('read_notes',['comic'=>true],$octx);
    check(!$ok&&str_contains($out,'set it up with comic_settings first'),'a story without an adaptation has no workspace of one');
    check($writer->comicView($other,$key)['settings']['started']===false&&!isset($writer->comicView($other,$key)['cast']),'(and its page shows none)');
    $stories->delete($other);
    // What the writer is told when the person changes the adaptation's workspace
    $state=fn()=>(fn()=>$this->stateBlock($sid,Writer::settings(null,['image_model'=>'gpt-image-2']),$key,null,null))->call($writer);
    $update=fn()=>(fn()=>$this->updateBlock($sid,Writer::settings(null,['image_model'=>'gpt-image-2']),$key))->call($writer);
    $state();
    $stories->writeDocument($sid,'comic_notes',['text'=>'Ana tem cabelo curto e franja.'],'person',$key);
    $stories->writeDocument($sid,'comic_plan',['text'=>'Começar pelo naufrágio.'],'person',$key);
    $stories->updateCast($sid,['character'=>'Bruno','note'=>'Mais velho nos quadrinhos.'],'person',$key,'comic');
    $u=(string)$update();
    check(str_contains($u,"The person edited the adaptation bible. It now reads:\n<adaptation_bible>\nAna tem cabelo curto e franja.")&&str_contains($u,'The person edited the adaptation plan. Treat their changes as direction.')
        &&str_contains($u,"The person changed the adaptation's lettering or sheets.")&&str_contains($u,'Mais velho nos quadrinhos'),'the person\'s changes to the adaptation\'s bible, plan and sheets reach the writer');
    check($update()===null,'(once)');
    // The adaptation's sheets drawn in the comic's look; a picture of them edited; a request that comes back
    [$ok,$out,$label]=$wi->runTool('character_sheet',['character'=>'Rosa','comic'=>true,'prompt'=>'Rosa, the keeper\'s mother, full body.','caption'=>'Aos 12','note'=>'a filha do faroleiro'],$ctx);
    $sheetJob=$jobOf(end($ctx->jobs));
    check($ok&&$label==='Fez a ficha da adaptação de Rosa · “Aos 12”'&&$sheetJob['label']==='Ficha da adaptação de Rosa · Aos 12'&&str_contains($sheetJob['prompt'],"A reference picture for the story's manga adaptation: drawn as its pages are, in black and white.")
        &&str_contains($sheetJob['prompt'],'Clean shoujo manga ink')&&!str_contains($sheetJob['prompt'],'Painterly'),'a sheet of the adaptation is made in the comic\'s look and style');
    check(Stories::findCharacter($stories->cast($sid,$key,'comic')['characters'],'Rosa')!==null&&Stories::findCharacter($stories->cast($sid,$key)['characters'],'Rosa')===null,'and goes to the adaptation\'s sheets only');
    $jobs->run($sheetJob['id'],$key,$api); $stories->get($sid,$key,$jobs);
    $carla=$stories->cast($sid,$key,'comic')['characters'][Stories::findCharacter($stories->cast($sid,$key,'comic')['characters'],'Rosa')];
    check(is_string($carla['images'][0]['image'])&&$carla['images'][0]['job']===null,'the picture takes its place in the adaptation\'s sheet when it is ready');
    [$ok,$out,$label]=$wi->runTool('edit_illustration',['image_id'=>$carla['images'][0]['image'],'instruction'=>'Shorter hair.'],$ctx);
    check($ok&&$label==='Pediu uma nova versão da imagem (ficha da adaptação de Rosa)'&&str_contains($out,'the adaptation\'s sheet of Rosa'),'a picture of the adaptation\'s sheets gets a new version, as any other');
    $jobs->run(end($ctx->jobs),$key,$api); $stories->get($sid,$key,$jobs);
    check($stories->cast($sid,$key,'comic')['characters'][Stories::findCharacter($stories->cast($sid,$key,'comic')['characters'],'Rosa')]['images'][0]['image']!==$carla['images'][0]['image'],'(which takes its place there)');
    [$ok,$out,$label]=$wi->runTool('set_style',['comic'=>true,'make_lettering_sample'=>true],$ctx);
    $sample=$jobOf(end($ctx->jobs));
    check($ok&&$label==='Pediu uma amostra das letras da adaptação'&&str_starts_with($sample['prompt'],'A lettering reference sheet for the pages of a manga')&&str_contains($sample['label'],'Amostra das letras da adaptação')
        &&$stories->cast($sid,$key,'comic')['style']['lettering']['job']===$sample['id']&&$stories->cast($sid,$key)['style']['lettering']['job']===null,'a sample of the adaptation\'s letters goes to the adaptation\'s sheets');
    $jobs->run($sample['id'],$key,$api); $stories->get($sid,$key,$jobs);
    $letras=$stories->cast($sid,$key,'comic')['style']['lettering']['image'];
    check($letras!==$lib['letras']&&is_string($letras),'(and becomes its sample once ready)');
    [$ok,$out,$label]=$wi->runTool('set_style',['comic'=>true,'text'=>'Clean shoujo manga ink, fine lines, soft screentones.'],$ctx);
    check($ok&&$label==='Definiu o estilo da adaptação'&&$comics->settings($sid,$key)['style']['text']==='Clean shoujo manga ink, fine lines, soft screentones.'&&$stories->cast($sid,$key)['style']['text']==='Painterly watercolor.','the adaptation\'s style is the comic\'s own, as comic_settings sets it');
    $view=$writer->comicView($sid,$key); $withPlan=$writer->comicView($sid,$key,true);
    check($view['notes']['text']==='Ana tem cabelo curto e franja.'&&!isset($view['plan']['text'])&&$view['plan']['words']===3&&$withPlan['plan']['text']==='Começar pelo naufrágio.'&&count($view['cast']['characters'])===4,
        'the comic\'s page gets its workspace, the plan\'s text only when asked for');
    // A comic started before the adaptation had a workspace of its own keeps drawing with what it drew with: a copy of the book's sheets and letters
    $old=$stories->create('Antiga',$key)['id']; $comics->setSettings($old,['kind'=>'manga'],'person',$key);
    $stories->updateCast($old,['character'=>'Ana','add'=>[['image'=>$lib['ana']]]],'person',$key);
    $stories->updateStyle($old,['lettering'=>'Old letters.','lettering_image'=>$lib['letras']],'person',$key);
    $vault->db->prepare('UPDATE stories SET comic_cast=NULL WHERE id=?')->execute([$old]);
    $copy=$stories->cast($old,$key,'comic');
    check($copy['characters']===$stories->cast($old,$key)['characters']&&$copy['style']['lettering']['text']==='Old letters.'&&$copy['style']['lettering']['image']===$lib['letras']&&$copy['rev']===0&&$copy['by']===null,
        'an adaptation from before gets a copy of the book\'s sheets and letters, once, not counted as a change');
    $stories->updateCast($old,['character'=>'Ana','note'=>'Só nos quadrinhos.'],'person',$key,'comic');
    check($stories->cast($old,$key)['characters'][0]['note']===''&&$stories->cast($old,$key,'comic')['characters'][0]['note']==='Só nos quadrinhos.','and from then on the two are apart');
    $stories->delete($old);

    // -----------------------------------------------------------------------------------------------------------------
    // From the comic's view: the person draws, retries, fixes and sets a failed drawing aside
    $p2=$comics->pageAt($sid,2,$key);
    $again=$writer->retryPage($sid,$p2['id'],$key);
    check($again['prompt']===$jobOf($second)['prompt']&&$comics->pageAt($sid,2,$key)['job']===$again['id']&&$comics->pageAt($sid,2,$key)['drawn']===$p2['drawn'],'a failed drawing is made again as it was asked');
    rejects(fn()=>$writer->drawPage($sid,$p2['id'],[],'person',$key),'the person can\'t draw a page while it is being drawn',422,'já está sendo desenhada');
    $jobs->run($again['id'],$key,$api); $stories->get($sid,$key,$jobs);
    check(is_string($comics->pageAt($sid,2,$key)['image']),'and it becomes the page');
    $p3=$comics->addPage($sid,['summary'=>'O porão.','panels'=>[['place'=>'O Porão','description'=>'Dark cellar.','characters'=>[['name'=>'Carla']]]]],null,'person',$key);
    rejects(fn()=>$writer->drawPage($sid,$p3['id'],[],'person',$key),'the person is told which sheets are missing',422,'Falta a ficha com imagem de: Carla, O Porão (lugar)');
    $comics->updatePage($sid,$p3['id'],['panels'=>[['description'=>'Dark cellar, empty.']]],'person',$key);
    $drawn=$writer->drawPage($sid,$p3['id'],['previous'=>false],'person',$key);
    check(!str_contains($drawn['prompt'],'previous page')&&$drawn['reference_count']===2&&str_contains($drawn['prompt'],'page 3 of 3'),'a page with no characters is drawn from the sketch, the style and the letters');
    $jobs->run($drawn['id'],$key,$api); $stories->get($sid,$key,$jobs);
    $edit=$writer->editPage($sid,$p3['id'],['kind'=>'edit','instruction'=>'Add a candle.'],$key);
    check($edit['reference_count']===2&&str_contains($edit['prompt'],'Attached image 2 is the lettering reference of this comic'),'a fix from the page takes the lettering along');
    rejects(fn()=>$writer->editPage($sid,$p3['id'],['kind'=>'photoreal'],$key),'a page is fixed, enhanced or upscaled, not made a photograph',422);
    $sketchRoute=Comics::sketchPng($comics->page($sid,$p3['id'],$key),$comics->settings($sid,$key));
    check(getimagesizefromstring($sketchRoute)['mime']==='image/png','the app draws a sketch for a page the writer didn\'t draw');
    $comics->setReading($sid,$p3['id'],$key);
    check($comics->view($sid,$key)['reading_page']===$p3['id'],'where the person stopped reading the comic is kept');
    // A copy of the story takes the comic along: new pages, pointing at the copy's chapters
    $before=$comics->view($sid,$key);
    $cid=$writer->duplicate($sid,'O Farol (cópia)',$key)['id']; $dup=$comics->view($cid,$key);
    $chapterOf=fn(string $story)=>array_combine(array_column($stories->chapters($story,$key),'title'),array_column($stories->chapters($story,$key),'id'));
    $strip=fn(array $pages)=>array_map(fn($p)=>array_diff_key($p,['id'=>1,'chapter'=>1,'updated'=>1]),$pages);
    check(count($dup['pages'])===count($before['pages'])&&$strip($dup['pages'])===$strip($before['pages'])&&!array_intersect(array_column($dup['pages'],'id'),array_column($before['pages'],'id'))
        &&array_column($dup['pages'],'image')===array_column($before['pages'],'image'),'a copy of the story has the comic\'s pages, as new rows with the same drawings');
    check($dup['pages'][0]['chapter']===$chapterOf($cid)['A chegada']&&$before['pages'][0]['chapter']===$chapterOf($sid)['A chegada']&&$dup['settings']===$before['settings']&&$dup['reading_page']===null,'its pages adapt the copy\'s chapters, with the same settings, read from the start');
    check($stories->cast($cid,$key,'comic')===$stories->cast($sid,$key,'comic')&&$stories->readDocument($cid,'comic_notes',$key)['text']==='Ana tem cabelo curto e franja.'&&$stories->readDocument($cid,'comic_plan',$key)['text']==='Começar pelo naufrágio.',
        'the copy takes the adaptation\'s workspace along: its bible, plan, sheets and letters');
    $comics->deletePage($cid,$dup['pages'][0]['id'],'person',$key);
    check(count($comics->pages($sid,$key))===count($before['pages']),'and a page deleted in the copy stays in the original');
    $stories->delete($cid);
    // The comic goes to a file with the story and comes back as it was: settings, scripts, sketches, pages and their chapters
    $before=$comics->view($sid,$key);
    $pack=json_decode(json_encode($stories->export($sid,$key,$jobs),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);
    check(count($pack['comic']['pages'])===count($before['pages'])&&$pack['comic']['pages'][0]['chapter']===1&&$pack['comic']['settings']['kind']===$before['settings']['kind']
        &&!array_diff(array_filter(array_column($before['pages'],'image')),array_keys($pack['images'])),'the comic goes to the file with its pages, each naming its chapter by number, and their pictures');
    $made=$stories->import($pack,Writer::settings(null),$key); $imp=$comics->view($made['id'],$key);
    // The file doesn't keep when the settings changed: the import is that moment (a second later, sometimes).
    check($strip($imp['pages'])===$strip($before['pages'])&&array_diff_key($imp['settings'],['at'=>1])===array_diff_key($before['settings'],['at'=>1])&&$imp['reading_page']===null&&$imp['pages'][0]['chapter']===$chapterOf($made['id'])['A chegada']
        &&isset($made['map'][$before['pages'][0]['id']])&&$made['map'][$before['pages'][0]['id']]===$imp['pages'][0]['id'],'and comes back as it was, adapting the new story\'s chapters');
    // The same sheets, whatever the order of the keys inside them.
    $sorted=function(mixed $v) use (&$sorted): mixed { if(!is_array($v)) return $v; if(!array_is_list($v)) ksort($v); return array_map($sorted,$v); };
    $strip2=fn(array $c)=>$sorted(array_diff_key($c,['at'=>1]));
    check($pack['comic']['notes']['text']==='Ana tem cabelo curto e franja.'&&$pack['comic']['plan']['text']==='Começar pelo naufrágio.'&&count($pack['comic']['cast']['characters'])===4
        &&!array_diff(array_filter(array_merge(...array_map(fn($c)=>array_column($c['images'],'image'),$pack['comic']['cast']['characters']))),array_keys($pack['images'])),'the file keeps the adaptation\'s workspace, with its pictures');
    check($strip2($stories->cast($made['id'],$key,'comic'))===$strip2($stories->cast($sid,$key,'comic'))&&$stories->readDocument($made['id'],'comic_plan',$key)['text']==='Começar pelo naufrágio.'&&$stories->readDocument($made['id'],'comic_notes',$key)['text']==='Ana tem cabelo curto e franja.',
        'and brings it back');
    $legacy=$pack; unset($legacy['comic']['notes'],$legacy['comic']['plan'],$legacy['comic']['cast']);
    $was=$stories->import($legacy,Writer::settings(null),$key);
    check(array_column($stories->cast($was['id'],$key,'comic')['characters'],'name')===array_column($stories->cast($was['id'],$key)['characters'],'name')&&$stories->readDocument($was['id'],'comic_notes',$key)['text']==='',
        'a file from before the adaptation had a workspace gets the copy of the book\'s sheets, as it drew with them');
    $stories->delete($was['id']);
    $broken=$pack; $broken['comic']['pages'][0]['sketch']='<svg><script>alert(1)</script></svg>';
    rejects(fn()=>$stories->import($broken,Writer::settings(null),$key),'a page whose sketch the app wouldn\'t draw is refused, saying which page',422);
    try { $stories->import($broken,Writer::settings(null),$key); } catch (HttpError $e) { check(str_starts_with($e->getMessage(),'Página 1 dos quadrinhos: '),'(saying which page)'); }
    $stories->delete($made['id']);
    $stories->delete($sid);
    check((int)$vault->db->query("SELECT COUNT(*) FROM comic_pages WHERE story_id='$sid'")->fetchColumn()===0,'deleting the story deletes its pages');

    echo "\n$count comic checks passed\n";
} finally {
    if (is_dir($dir)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); rmdir($dir); }
}
