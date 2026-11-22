<?php
declare(strict_types=1);
// The albums of the stories, comics and visual novels: their pictures go into them and stay out of the library, a
// library picture a story only uses stays in the library, and the albums made before (adoptAlbums) are brought in line.
// No real API calls. Run: php tests/albums.php
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{Crypto,Vault,OpenAI,Jobs,Users,Stories};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }

$dir=sys_get_temp_dir().'/imagegen-albums-'.bin2hex(random_bytes(6));
try{
    $vault=new Vault($dir);$users=new Users($vault);
    $key=random_bytes(32);$owner=$users->create('Pessoal','test-password-long-enough',$key,true);$vault->useOwner($owner['id']);
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $jobs=new Jobs($vault,['api_timeout'=>60]);$stories=new Stories($vault);
    $api=new OpenAI('test',60,function(string $path,array $body){
        $im=imagecreatetruecolor(32,32);imagefill($im,0,0,imagecolorallocate($im,crc32($body['prompt']??'')&0xff,90,140));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
        return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]];
    });
    // A picture from a request (into an album, or as a version of another picture), as the app makes them.
    $picture=function(string $prompt,?string $album=null,?string $parent=null)use($jobs,$key,$api,$vault):string{
        $in=['id'=>bin2hex(random_bytes(16)),'prompt'=>$prompt,'options'=>['model'=>'gpt-image-2','quality'=>'low','size'=>'1024x1024','n'=>1]]+($album?['album'=>$album]:[]);
        if($parent!==null)$in+=['parent_id'=>$parent,'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($vault->bytes($parent,'full',$key))]]];
        $job=$jobs->create($in,$key)[0];
        return $jobs->run($job['id'],$key,$api)['image_ids'][0];
    };
    $library=fn(array $view=[])=>array_column($vault->library($key,$view)['items'],0);
    $inAlbum=fn(string $album)=>$library(['album'=>$album]);
    $albumRow=fn(string $album)=>array_column($vault->albums($key),null,'id')[$album]??null;

    // A story's album: made the first time a picture is made for it, kept out of the library, without the library
    // pictures the story only uses.
    $chosen=$picture('a picture of the library');
    $sid=$stories->create('A Casa do Lago',$key)['id'];
    $stories->update($sid,['cover'=>$chosen],$key);
    $stories->updateCast($sid,['character'=>'Lia','add'=>[['image'=>$chosen]]],'person',$key);
    $album=$stories->album($sid,$key);
    $row=$albumRow($album);
    check($row['name']==='A Casa do Lago'&&$row['kind']==='story'&&$row['story']===$sid&&$row['library']===false&&$row['count']===0,'a story\'s album has its title, says it is a story\'s and keeps its pictures out of the library');
    check($library()===[$chosen]&&$stories->album($sid,$key)===$album,'a picture of the library the story uses (its cover, a sheet) stays in the library, out of the album');
    $made=$picture('an illustration',$album);
    check($inAlbum($album)===[$made]&&$library()===[$chosen]&&in_array($made,array_column($vault->gallery($key,'',false,false,0,300)['items'],'id'),true),'a picture made for the story goes only into its album; the reference picker still has it');
    $version=$picture('the same, in the evening',null,$made);
    check($inAlbum($album)===[$version,$made]&&$library()===[$chosen],'a version made of it in the library\'s viewer joins its album too');
    $fromLibrary=$picture('the chosen cover, edited for the story',$album,$chosen);
    check($inAlbum($album)===[$fromLibrary,$version,$made]&&$library()===[$chosen]&&$library(['group'=>true])===[$chosen],'a version made for the story of a library picture goes into the album; the library keeps the original');
    $own=$picture('the chosen cover, edited in the library',null,$chosen);
    check($library()===[$own,$chosen]&&!in_array($own,$inAlbum($album),true),'a version of a library picture made in the library stays in the library');
    $job=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'still being made','options'=>[],'album'=>$album],$key)[0];
    $plain=$jobs->create(['id'=>bin2hex(random_bytes(16)),'prompt'=>'a picture of mine','options'=>[]],$key)[0];
    check($job['album']===$album&&$job['apart']===true&&$plain['album']===null&&$plain['apart']===false,'a request says when its album keeps it out of the library');
    $vault->setAlbumLibrary($album,true);
    check($library()===[$own,$fromLibrary,$version,$made,$chosen]&&(new Jobs($vault,['api_timeout'=>60]))->list($key,'active',[$job['id']])[0]['apart']===false,'the person can show a story\'s album in the library too');
    $vault->setAlbumLibrary($album,false);
    // Deleted by the person: its pictures are back in the library, and the album made again starts empty.
    $vault->deleteAlbum($album);
    check($library()===[$own,$fromLibrary,$version,$made,$chosen],'deleting a story\'s album brings its pictures back to the library');
    $again=$stories->album($sid,$key);
    check($again!==$album&&$inAlbum($again)===[]&&$stories->albums($sid,$key)===['book'=>$again,'comic'=>null],'made again, the album holds only what comes next');
    $vault->bulk('trash',[$made,$version,$fromLibrary,$own]);$vault->bulk('purge',[$made,$version,$fromLibrary,$own]);
    $stories->update($sid,['title'=>'A Casa do Lago, de novo'],$key);
    check($albumRow($again)['name']==='A Casa do Lago, de novo','the album follows the story\'s title');

    // A copy makes its own album, with the pictures made for the story; a story brought from a file, with all of them.
    $illustration=$picture('the lake',$again);
    $stories->update($sid,['cover'=>$illustration],$key);
    $copy=$stories->copy($sid,'A cópia',$key)['id'];
    $copyAlbum=$stories->album($copy,$key);
    check($copyAlbum!==$again&&$inAlbum($copyAlbum)===[$illustration]&&$albumRow($copyAlbum)['story']===$copy,'a copy\'s album has the pictures made for the story, not the library ones it uses');
    $imported=$vault->importImage((string)$vault->bytes($chosen,'full',$key),'capa.png',$key);
    $brought=$stories->create('Do arquivo',$key)['id'];$stories->update($brought,['cover'=>$imported['id']],$key);
    $stories->updateCast($brought,['character'=>'Ana','add'=>[['image'=>$chosen]]],'person',$key);
    $broughtAlbum=$stories->album($brought,$key,'book',[$imported['id']]);
    check($inAlbum($broughtAlbum)===[$imported['id']]&&!in_array($imported['id'],$library(),true)&&in_array($chosen,$library(),true),'a story brought from a file takes the pictures it sent to the gallery into its album; one the account already had stays in the library');

    // The albums the stories made before: adopted once per account.
    $oldKey=random_bytes(32);$old=$users->create('Antiga','another-password-long-enough',$oldKey);$vault->useOwner($old['id']);$key=$oldKey;
    $vault->setApiKey('sk-test-placeholder-for-unit-tests',$key);
    $jobs=new Jobs($vault,['api_timeout'=>60]);$stories=new Stories($vault);$comics=$stories->comics();
    $picture=function(string $prompt,?string $album=null,?string $parent=null)use($jobs,$key,$api,$vault):string{
        $in=['id'=>bin2hex(random_bytes(16)),'prompt'=>$prompt,'options'=>['model'=>'gpt-image-2','quality'=>'low','size'=>'1024x1024','n'=>1]]+($album?['album'=>$album]:[]);
        if($parent!==null)$in+=['parent_id'=>$parent,'operation'=>'edit','references'=>[['data'=>'data:image/png;base64,'.base64_encode($vault->bytes($parent,'full',$key))]]];
        $job=$jobs->create($in,$key)[0];
        return $jobs->run($job['id'],$key,$api)['image_ids'][0];
    };
    $library=fn(array $view=[])=>array_column($vault->library($key,$view)['items'],0);
    $inAlbum=fn(string $album)=>array_column($vault->library($key,['album'=>$album])['items'],0);
    $albumRow=fn(string $album)=>array_column($vault->albums($key),null,'id')[$album]??null;
    // What the old code left: a plain album named after the story, its id in the story, the pictures the story had then
    // added when the album was made, and every picture made for the story added as it was saved.
    $setAlbum=function(string $story,string $album)use($vault,$key){
        $raw=(string)$vault->db->query("SELECT meta FROM stories WHERE id='$story'")->fetchColumn();
        $m=json_decode(Crypto::open($raw,$key,"story:$story"),true);$m['album']=$album;
        $vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([Crypto::seal(json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$key,"story:$story"),$story]);
    };
    $t=time()-86400;
    $libraryPick=$picture('a picture of mine');
    $oldImport=$vault->importImage((string)$vault->bytes($libraryPick,'full',$key),'old.png',$key)['id'];
    $vault->db->prepare('UPDATE images SET created=? WHERE id=?')->execute([$t-7200,$oldImport]);
    $fileImport=$vault->importImage((string)$vault->bytes($libraryPick,'full',$key),'from-the-file.png',$key)['id'];
    $vault->db->prepare('UPDATE images SET created=? WHERE id=?')->execute([$t-60,$fileImport]);
    $tale=$stories->create('O Farol',$key)['id'];
    $oldAlbum=$vault->createAlbum('O Farol',$key);$setAlbum($tale,$oldAlbum['id']);
    $vault->db->prepare('UPDATE albums SET created=? WHERE id=?')->execute([$t,$oldAlbum['id']]);
    $stories->update($tale,['cover'=>$libraryPick],$key);
    $stories->updateCast($tale,['character'=>'Ana','add'=>[['image'=>$oldImport],['image'=>$fileImport]]],'person',$key);
    $vault->bulk('album-add',[$libraryPick,$oldImport,$fileImport],$oldAlbum['id']);
    $vault->db->prepare('UPDATE album_items SET added=? WHERE album_id=?')->execute([$t,$oldAlbum['id']]);
    $illustration=$picture('an illustration',$oldAlbum['id']);
    $sheet=$picture('Ana, the book\'s sheet',$oldAlbum['id']);$stories->updateCast($tale,['character'=>'Ana','add'=>[['image'=>$sheet]]],'person',$key);
    // Its comic, drawn with the book's pictures before the adaptation had an album: pages, a version of one, a page
    // drawn again, an adaptation sheet, and the book's sheet the adaptation uses too.
    $comics->setSettings($tale,['kind'=>'comic'],'person',$key);
    $stories->sheetFromBook($tale,'Ana','person',$key);
    $page=$picture("One complete, finished comic book page.\n\nAttached image 1 is a rough storyboard sketch of this page.",$oldAlbum['id']);
    $redrawn=$picture("One complete, finished comic book page, again.\n\nAttached image 1 is a rough storyboard sketch of this page.",$oldAlbum['id']);
    $fixedPage=$picture('Fix the hand.',$oldAlbum['id'],$redrawn);
    $adaptationSheet=$picture("Bruno.\n\nA reference picture for the story's comic book adaptation: drawn as its pages are, in full color.",$oldAlbum['id']);
    $p=$comics->addPage($tale,['panels'=>[['description'=>'Ana arrives.']]],null,'person',$key);
    $comics->setJob($tale,$p['id'],str_repeat('f',32),$key);$comics->finishJob($tale,str_repeat('f',32),$fixedPage,$key);
    $novel=$stories->create('Meu Jogo',$key,'vn')['id'];
    $vnAlbum=$vault->createAlbum('Meu Jogo',$key);$setAlbum($novel,$vnAlbum['id']);
    $sprite=$picture('a sprite',$vnAlbum['id']);
    $mine=$vault->createAlbum('Viagem',$key);$vault->bulk('album-add',[$libraryPick],$mine['id']);
    $gone=$stories->create('Apagada',$key)['id'];$goneAlbum=$vault->createAlbum('Apagada',$key);$setAlbum($gone,$goneAlbum['id']);$lost=$picture('of a deleted story',$goneAlbum['id']);$stories->delete($gone);
    $before=$library();
    check(count($before)===11,'before, every picture of the stories shows in the library');

    $stories->adoptAlbums($key);
    $book=$albumRow($oldAlbum['id']);$comicAlbum=$stories->albums($tale,$key)['comic'];$comicRow=$comicAlbum!==null?$albumRow($comicAlbum):null;
    check($book['kind']==='story'&&$book['story']===$tale&&$book['library']===false&&$book['name']==='O Farol','the story\'s album says it is the story\'s and keeps its pictures out of the library');
    $bookItems=$inAlbum($oldAlbum['id']);sort($bookItems);$expected=[$illustration,$sheet,$fileImport];sort($expected);
    check($bookItems===$expected,'it keeps the pictures made for the book and the ones that came with the story from a file; the library pictures it was made with go back to the library, and the comic\'s pictures move out');
    $comicItems=$comicRow?$inAlbum($comicAlbum):[];sort($comicItems);$expected=[$page,$redrawn,$fixedPage,$adaptationSheet,$sheet,$fileImport];sort($expected);
    check($comicRow!==null&&$comicRow['kind']==='comic'&&$comicRow['story']===$tale&&$comicRow['library']===false&&$comicRow['name']==='O Farol (HQ)'&&$comicItems===$expected,'the comic gets an album of its own: its pages (redrawn ones and versions too), its adaptation sheets and the book\'s sheets it uses (not the library pictures in them)');
    check($albumRow($vnAlbum['id'])['kind']==='vn'&&$albumRow($vnAlbum['id'])['library']===false&&$inAlbum($vnAlbum['id'])===[$sprite],'a visual novel\'s album is a visual novel\'s');
    check($albumRow($mine['id'])['kind']===null&&$albumRow($mine['id'])['library']===true&&$albumRow($goneAlbum['id'])['kind']===null&&$albumRow($goneAlbum['id'])['library']===true,'the person\'s own albums, and the album of a story deleted before, stay as they were');
    $after=$library();sort($after);$expected=[$libraryPick,$oldImport,$lost];sort($expected);
    check($after===$expected,'the library now shows only the person\'s own pictures');
    $vault->bulk('album-add',[$libraryPick],$oldAlbum['id']);$stories->adoptAlbums($key);
    check(in_array($libraryPick,$inAlbum($oldAlbum['id']),true)&&$vault->get(Stories::ADOPTED.$old['id'])==='done','it runs once per account');
    $vault->useOwner($owner['id']);
    check($vault->get(Stories::ADOPTED.$owner['id'])===null,'and each account on its own');
    $users->delete($old['id']);
    check($vault->get(Stories::ADOPTED.$old['id'])===null,'deleting the account forgets it');

    echo "\n$count album checks passed. No real API calls.\n";
}finally{
    if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($dir);}
}
