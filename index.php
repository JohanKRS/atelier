<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
use Studio\{Auth,Vault,OpenAI,Jobs,Users,HttpError,Stories,Writer,Images,Gemini,Catalog};
use function Studio\t;
Studio\headers();
$action=(string)($_GET['action']??'');
if ($action==='') { require __DIR__.'/app/shell.php'; exit; }
try {
    foreach (['pdo_sqlite','sodium','curl','gd','mbstring'] as $ext) if (!extension_loaded($ext)) throw new HttpError(503,t('Turn on the PHP extension {ext} on the server.', ['ext'=>$ext]));
    if (PHP_VERSION_ID<80200) throw new HttpError(503,t('Use PHP 8.2 or later.'));
    $config=Studio\configuration();
    $secure=Studio\isSecure($config);
    if ($config['require_https'] && !$secure && !Studio\isLocal()) throw new HttpError(503,t('Use HTTPS to protect your password and your images.'));
    if ($secure) header('Strict-Transport-Security: max-age=31536000');
    $vault=new Vault(Studio\privateDirectory($config)); $users=new Users($vault); $auth=new Auth($vault,$users,$config,$secure); $jobs=new Jobs($vault,$config); $stories=new Stories($vault); $writer=new Writer($vault,$stories,$jobs,$config);
    $method=$_SERVER['REQUEST_METHOD'];
    $reads=['state','gallery','library','captions','albums','image','detail','jobs','history','lineage','preview','users','video','frames','balance','stories','story','story-poll','story-usage','story-review-estimate','reader-about','story-export','story-export-messages','host-test-status','comic-sketch','vn','vn-export'];
    if ($method!== (in_array($action,$reads,true)?'GET':'POST')) throw new HttpError(405,t('Method not allowed.'));
    // A piece of a video upload is raw bytes, not JSON.
    if ($method==='POST') { $auth->checkCsrf(); $input=$action==='video-chunk' ? [] : Studio\input(); }
    if ($action==='state') {
        $unlocked=$auth->isUnlocked();
        // The models the account's keys listed last time (Catalog): the page asks for fresh lists when these are old.
        if ($unlocked) Catalog::use($vault->modelLists($auth->key(false)));
        Studio\json(['initialized'=>$vault->initialized(),'unlocked'=>$unlocked,'csrf'=>$auth->csrf(),'setup_ready'=>strlen($config['setup_token'])>=24,'session_minutes'=>$config['session_minutes'],
            'max_parallel'=>$config['max_parallel'],'api_timeout'=>$config['api_timeout'],'stale_after'=>Jobs::STALE_AFTER,
            'has_key'=>$unlocked && $vault->apiKey($auth->key(false))!=='','models'=>Images::models(),'sizes'=>OpenAI::SIZES,
            'user'=>$unlocked ? Users::summary($auth->user()) : null,'mcp_url'=>$unlocked ? Studio\baseUrl($secure).'mcp.php' : null,
            'max_video_bytes'=>$config['max_video_mb']*1048576,'has_claude_key'=>$unlocked && $vault->claudeKey($auth->key(false))!=='','has_gemini_key'=>$unlocked && $vault->geminiKey($auth->key(false))!=='','writers'=>Writer::catalog(),'models_fetched'=>Catalog::fetched(),'models_fresh'=>Catalog::FRESH,
            'reader'=>$unlocked ? $vault->readerPrefs($auth->key(false)) : null,'story_prefs'=>$unlocked ? (object)$vault->storyPrefs($auth->key(false)) : null]);
    }
    if ($action==='setup') { $auth->setup((string)($input['name']??''),(string)($input['password']??''),(string)($input['token']??'')); Studio\json(['ok'=>true,'csrf'=>$auth->csrf()]); }
    if ($action==='login') { $auth->login((string)($input['name']??''),(string)($input['password']??''),(string)($input['website']??'')); Studio\json(['ok'=>true,'csrf'=>$auth->csrf()]); }
    // Background reads (thumbnails, previews, polling) don't extend the idle timeout.
    $key=$auth->key(!in_array($action,['image','jobs','preview','captions','story-poll','story-warm','comic-sketch'],true));
    Catalog::use($vault->modelLists($key));
    // Release the session lock: thumbnails, polling and long generations must not wait for one another.
    if (!in_array($action,['logout','password'],true)) session_write_close();
    // Once per account: the albums the stories made before keep their pictures out of the library (Stories::adoptAlbums).
    if (in_array($action,['library','albums','jobs','stories','story'],true)) { try { $stories->adoptAlbums($key); } catch (\Throwable $e) { Studio\logFailure($e); } }
    if ($action==='logout') { $auth->logout(); Studio\json(['ok'=>true]); }
    if ($action==='password') { $auth->changePassword((string)($input['current']??''),(string)($input['password']??'')); Studio\json(['ok'=>true,'csrf'=>$auth->csrf()]); }
    // The account's OpenAI key, for the images, the prompt planning and the GPT writer. An empty key removes it.
    if ($action==='settings') {
        $apiKey=trim((string)($input['api_key']??''));
        if ($apiKey==='' && ($input['remove']??false)===true) { $vault->setApiKey(null,$key); Studio\json(['ok'=>true,'has_key'=>false]); }
        if (strlen($apiKey)<20 || strlen($apiKey)>500 || !preg_match('/\Ask-[A-Za-z0-9_-]+\z/D',$apiKey)) throw new HttpError(422,t('Enter a valid OpenAI key.'));
        $vault->setApiKey($apiKey,$key); sodium_memzero($apiKey); Studio\json(['ok'=>true,'has_key'=>true]);
    }
    // The account's Claude key, for the writer of the stories. An empty key removes it.
    if ($action==='claude-key') {
        $claudeKey=trim((string)($input['key']??''));
        if ($claudeKey==='') { $vault->setClaudeKey(null,$key); Studio\json(['ok'=>true,'has_claude_key'=>false]); }
        if (strlen($claudeKey)<20 || strlen($claudeKey)>500 || !preg_match('/\Ask-ant-[A-Za-z0-9_-]+\z/D',$claudeKey)) throw new HttpError(422,t('Enter a valid Claude API key (it starts with sk-ant-).'));
        $vault->setClaudeKey($claudeKey,$key); sodium_memzero($claudeKey); Studio\json(['ok'=>true,'has_claude_key'=>true]);
    }
    // The account's Google Gemini key, for the writer and the Nano Banana images. An empty key removes it.
    if ($action==='gemini-key') {
        $geminiKey=trim((string)($input['key']??''));
        if ($geminiKey==='') { $vault->setGeminiKey(null,$key); Studio\json(['ok'=>true,'has_gemini_key'=>false]); }
        if (strlen($geminiKey)<30 || strlen($geminiKey)>300 || !preg_match('/\A[A-Za-z0-9_.\-]+\z/D',$geminiKey) || str_starts_with($geminiKey,'sk-'))
            throw new HttpError(422,t('Enter a valid Gemini API key (created at aistudio.google.com, under API keys; it usually starts with AIza).'));
        $vault->setGeminiKey($geminiKey,$key); sodium_memzero($geminiKey); Studio\json(['ok'=>true,'has_gemini_key'=>true]);
    }
    if ($action==='models') {
        // The models of the account's keys (Catalog): asked again when the lists are older than Catalog::FRESH, or
        // right away after a key changes (force).
        $errors=[];
        $stale=time()-Catalog::fetched()>=Catalog::FRESH;
        if ($method==='POST' && ((($input['refresh']??false)===true && $stale) || ($input['force']??false)===true)) $errors=(array)(Catalog::refresh($vault,$key)['errors']??[]);
        Studio\json(['models'=>Images::models(),'writers'=>Writer::catalog(),'fetched'=>Catalog::fetched(),'errors'=>(object)$errors]);
    }
    // Testar, next to each key: the key with its models (free) and one tiny request (a fraction of a cent). The Gemini one
    // makes it a tool call, as its writer does.
    if ($action==='openai-test' || $action==='claude-test') {
        $openai=$action==='openai-test';
        $secret=$openai ? $vault->apiKey($key) : $vault->claudeKey($key);
        if ($secret==='') throw new HttpError(422,$openai ? t('Save the OpenAI key before testing.') : t('Save the Claude key before testing.'));
        $result=$openai ? Studio\KeyTest::openai($secret) : Studio\KeyTest::claude($secret); sodium_memzero($secret); Studio\json($result);
    }
    if ($action==='gemini-test') {
        $geminiKey=$vault->geminiKey($key);
        if ($geminiKey==='') throw new HttpError(422,t('Save the Gemini key before testing.'));
        $result=Gemini::selfTest($geminiKey); sodium_memzero($geminiKey); Studio\json($result);
    }
    // Stories: the text, the bible and the plan (only when asked: it holds the spoilers), and the writer's chat.
    if ($action==='stories') Studio\json(['stories'=>$stories->all($key,$jobs)]);
    if ($action==='story') {
        $id=(string)($_GET['id']??'');
        $story=$stories->get($id,$key,$jobs,($_GET['plan']??'')==='1');
        $story['writer']=Writer::settings($story['writer']); $story['comic']=$writer->comicView($id,$key,($_GET['plan']??'')==='1');
        if ($story['kind']==='vn') $story['vn']=$stories->vn()->view($id,$key);
        Studio\json(['story'=>$story]+$writer->view($id,$key)+['spent'=>$writer->spent($id,$key),
            'pending'=>array_values($stories->resolveIllustrations($id,$key,$jobs)),'warm_in'=>$writer->warmIn($id,$key)]);
    }
    if ($action==='story-poll') {
        $id=(string)($_GET['id']??''); $pending=$stories->resolveIllustrations($id,$key,$jobs);
        Studio\json(['stamp'=>$stories->stamp($id)]+$writer->view($id,$key,max(0,(int)($_GET['after']??0)))+['pending'=>array_values($pending),
            'warm_in'=>$writer->warmIn($id,$key)]);
    }
    // What the writer's requests used in a story: the cache read and written, input, output and reasoning.
    if ($action==='story-usage') Studio\json($writer->usage((string)($_GET['id']??''),$key));
    // Keeps Claude's cache of the conversation warm while the story is open (it doesn't extend the idle timeout).
    if ($action==='story-warm') Studio\json($writer->warm((string)($input['story']??''),$key));
    if ($action==='story-create') {
        $story=$stories->create((string)($input['title']??''),$key,($input['kind']??'story')==='vn'?'vn':'story');
        if (is_array($input['writer']??null)) $story=$stories->update($story['id'],['writer'=>Writer::settings(null,$input['writer'])],$key);
        Studio\json(['story'=>$story],201);
    }
    // The cover, from the list or the story: covers made from a description (a few, each in another style, to choose
    // from), a new version of it, one that failed made again or set aside (job: one cover of a set). Choosing one (from
    // the covers made or the gallery), or removing it, is story-update with cover.
    if ($action==='story-cover') {
        $id=(string)($input['id']??''); $mode=(string)($input['mode']??''); $choice=is_string($input['job']??null)?$input['job']:null;
        if ($mode==='generate') Studio\json(['jobs'=>$writer->makeCover($id,$input,$key)],201);
        if ($mode==='edit') Studio\json(['jobs'=>[$writer->editCover($id,$input,$key)]],201);
        if ($mode==='retry') Studio\json(['jobs'=>$writer->retryCover($id,$key,$choice)],201);
        if ($mode==='dismiss') { $stories->dropCoverJob($id,$key,$choice); Studio\json(['ok'=>true]); }
        throw new HttpError(422,t('Invalid cover request.'));
    }
    // The character sheets: a character added, renamed, its note, pictures added from the gallery, captions, removed,
    // the main one, the character removed; or a picture that failed, made again. space: the book's sheets (story) or
    // the comic adaptation's own (comic), which can also bring in a sheet of the book (from_book).
    if ($action==='story-cast') {
        $sid=(string)($input['story']??''); $space=($input['space']??'story')==='comic'?'comic':'story';
        if ($space==='comic' && !$stories->comics()->settings($sid,$key)['started']) throw new HttpError(422,t('Start the comic adaptation first.'));
        if (isset($input['retry'])) {
            $from=(string)$input['retry']; $created=$jobs->retry(bin2hex(random_bytes(16)),$from,null,$key,$space==='comic'?$stories->album($sid,$key,'comic'):null);
            $stories->retryCastPicture($sid,$from,$created[0]['id'],$key);
            Studio\json(['jobs'=>$created,'cast'=>$stories->cast($sid,$key,$space)],201);
        }
        // The visual style: its description, pictures added from the gallery or removed, the lettering and its sample
        // (a gallery image, or one made from the description). The adaptation's style is in its settings: here only its lettering.
        if (isset($input['style'])) Studio\json(['cast'=>$stories->updateStyle($sid,is_array($input['style'])?array_intersect_key($input['style'],array_flip($space==='comic'?['lettering','lettering_image']:['text','add','remove','lettering','lettering_image'])):[],'person',$key,$space)]);
        if (($input['lettering_sample']??false)===true) Studio\json(['jobs'=>[$writer->letteringSample($sid,null,'person',$key,$space)],'cast'=>$stories->cast($sid,$key,$space)],201);
        if ($space==='comic' && ($input['from_book']??false)===true) Studio\json($stories->sheetFromBook($sid,(string)($input['character']??''),'person',$key));
        $change=array_intersect_key($input,array_flip(['character','kind','new_name','note','add','captions','remove','main','delete']));
        Studio\json($stories->updateCast($sid,$change,'person',$key,$space));
    }
    // The comic adaptation: its settings, a page's script changed by hand, deleted or moved, a page drawn (or a new
    // version of it), a drawing that failed made again or set aside, and where the person stopped reading.
    if ($action==='story-comic') {
        $sid=(string)($input['story']??''); $mode=(string)($input['mode']??''); $comics=$stories->comics(); $pid=(string)($input['page']??'');
        if ($mode==='settings') Studio\json(['comic'=>$comics->setSettings($sid,is_array($input['settings']??null)?array_intersect_key($input['settings'],array_flip(['kind','reading','color','aspect','scale','style_text','style_add','style_remove'])):[],'person',$key)]);
        // drop_sketch: back to the app's sketch from the script (the writer's own one no longer matches it).
        if ($mode==='page-save') Studio\json(['page'=>$comics->updatePage($sid,$pid,array_intersect_key($input,array_flip(['summary','notes','panels','rows','chapter']))+(($input['drop_sketch']??false)===true?['sketch'=>'']:[]),'person',$key,isset($input['rev'])?(int)$input['rev']:null)]);
        if ($mode==='page-add') Studio\json(['page'=>$comics->addPage($sid,array_intersect_key($input,array_flip(['summary','notes','panels','rows','chapter'])),isset($input['index'])?(int)$input['index']:null,'person',$key)],201);
        if ($mode==='page-delete') { $comics->deletePage($sid,$pid,'person',$key); Studio\json(['ok'=>true]); }
        if ($mode==='page-move') { $comics->movePage($sid,$pid,(int)($input['index']??0),'person',$key); Studio\json(['ok'=>true]); }
        if ($mode==='draw') Studio\json(['jobs'=>[$writer->drawPage($sid,$pid,['instruction'=>$input['instruction']??'','references'=>is_array($input['references']??null)?$input['references']:[],'previous'=>($input['previous']??true)!==false],'person',$key)]],201);
        if ($mode==='edit') Studio\json(['jobs'=>[$writer->editPage($sid,$pid,$input,$key)]],201);
        if ($mode==='retry') Studio\json(['jobs'=>[$writer->retryPage($sid,$pid,$key)]],201);
        if ($mode==='dismiss') { $comics->dropJob($sid,$pid,$key); Studio\json(['ok'=>true]); }
        if ($mode==='reading') { $comics->setReading($sid,$pid,$key); Studio\json(['ok'=>true]); }
        throw new HttpError(422,t('Invalid request for the comic.'));
    }
    // A page's storyboard, drawn from its sketch (the writer's SVG, or the app's from the script), as the image model gets it.
    if ($action==='comic-sketch') {
        $comics=$stories->comics(); $page=$comics->page((string)($_GET['story']??''),(string)($_GET['page']??''),$key);
        $png=Studio\Comics::sketchPng($page,$comics->settings((string)$_GET['story'],$key));
        header('Content-Type: image/png'); header('Content-Length: '.strlen($png)); echo $png; exit;
    }
    // A visual novel project (a story of kind vn): the project for the page (full: with each scene as the preview plays
    // it), a script checked as it is typed, the scenes, the settings and lists, the gameplay notes, the art, the
    // person's documents brought in, and the game for Ren'Py.
    if ($action==='vn') {
        $id=(string)($_GET['id']??''); $stories->resolveIllustrations($id,$key,$jobs);
        Studio\json(['vn'=>$stories->vn()->view($id,$key,($_GET['full']??'')==='1'),'stamp'=>$stories->stamp($id)]);
    }
    if ($action==='vn-parse') {
        $sid=(string)($input['story']??''); $stories->vn()->project($sid,$key);
        $names=array_values(array_map(fn($c)=>$c['name'],array_filter($stories->cast($sid,$key)['characters'],fn($c)=>$c['kind']==='character')));
        Studio\json(Studio\Vn::parse(Studio\Stories::cleanText($input['script']??'',Studio\Vn::MAX_SCRIPT,'O roteiro'),$names));
    }
    if ($action==='vn-scene') {
        $sid=(string)($input['story']??''); $mode=(string)($input['mode']??''); $vn=$stories->vn(); $scene=(string)($input['scene']??'');
        $fields=array_intersect_key(is_array($input['fields']??null)?$input['fields']:[],array_flip(['label','title','chapter','route','summary','notes','script','exit','pos']));
        if ($mode==='add') Studio\json(['scene'=>$vn->addScene($sid,$fields,isset($input['index'])?(int)$input['index']:null,'person',$key)],201);
        if ($mode==='save') Studio\json(['scene'=>$vn->updateScene($sid,$scene,$fields,'person',$key,isset($input['rev'])?(int)$input['rev']:null)]);
        if ($mode==='delete') { $vn->deleteScene($sid,$scene,$key); Studio\json(['ok'=>true]); }
        if ($mode==='move') { $vn->moveScene($sid,$scene,(int)($input['index']??0),$key); Studio\json(['ok'=>true]); }
        if ($mode==='positions') { $vn->setPositions($sid,$input['positions']??null,$key); Studio\json(['ok'=>true]); }
        throw new HttpError(422,t('Invalid request for the scenes.'));
    }
    if ($action==='vn-settings') Studio\json(['settings'=>$stories->vn()->setSettings((string)($input['story']??''),array_intersect_key(is_array($input['change']??null)?$input['change']:[],array_flip(['name','aspect','framing','start','variables','routes','audio'])),'person',$key)]);
    if ($action==='vn-gameplay') Studio\json(['gameplay'=>$stories->vn()->writeGameplay((string)($input['story']??''),['text'=>$input['text']??''],'person',$key,isset($input['rev'])?(int)$input['rev']:null)]);
    if ($action==='vn-art') {
        $sid=(string)($input['story']??''); $mode=(string)($input['mode']??''); $vn=$stories->vn(); $kind=(string)($input['kind']??'');
        if ($mode==='generate') {
            $entry=array_intersect_key($input,array_flip(['character','outfit','expression','place','variant','name','title','prompt','from_scratch','references','characters','note']));
            [$made,$job]=$writer->makeVnArt($sid,$writer->storySettings($sid,$key),$kind,$entry,'person',$key);
            Studio\json(['art'=>$made,'jobs'=>[$job]],201);
        }
        if ($mode==='put') {
            $entry=$vn->artKey($sid,$kind,$input,$key); unset($entry['who']);
            if (isset($input['image'])) $entry['image']=(string)$input['image'];
            if (isset($input['note'])) $entry['note']=$input['note'];
            Studio\json(['art'=>$vn->putArt($sid,$kind,$entry,'person',$key)]);
        }
        if ($mode==='remove') Studio\json(['art'=>$vn->removeArt($sid,$kind,(string)($input['id']??''),'person',$key)]);
        if ($mode==='retry') Studio\json(['jobs'=>[$writer->retryVnArt($sid,(string)($input['job']??''),$key)]],201);
        if ($mode==='dismiss') { $vn->retryJob($sid,(string)($input['job']??''),null,$key); Studio\json(['ok'=>true]); }
        throw new HttpError(422,t('Invalid request for the art.'));
    }
    if ($action==='vn-import') Studio\json($stories->vn()->importDocuments((string)($input['story']??''),$input['items']??null,'person',$key));
    if ($action==='vn-export') Studio\json($stories->vn()->renpy((string)($_GET['id']??''),$key));
    if ($action==='story-update') {
        $fields=array_intersect_key($input,array_flip(['title','synopsis','series','cover','cover_design']));
        if (array_key_exists('writer',$input)) $fields['writer']=Writer::settings($stories->get((string)($input['id']??''),$key)['writer'],is_array($input['writer'])?$input['writer']:[]);
        Studio\json(['story'=>$stories->update((string)($input['id']??''),$fields,$key)]);
    }
    // The settings of the stories' reader (size, font, spacing, width, theme, pages), kept with the account.
    if ($action==='reader-prefs') Studio\json(['reader'=>(object)$vault->setReaderPrefs($input['reader']??null,$key)]);
    // How the chat shows the writer (its replies can give the story away, so they start hidden), kept with the account.
    if ($action==='story-prefs') Studio\json(['stories'=>(object)$vault->setStoryPrefs($input['stories']??null,$key)]);
    // What the person wrote about themselves as a reader, which the writer reads in every story (Configurações).
    // Beside it, the writer's own notes on the person's taste (update_reader_notes), which the person can edit too.
    if ($action==='reader-about') Studio\json(['text'=>$vault->readerAbout($key),'notes'=>$vault->readerNotes($key)['text'],'max'=>Studio\Vault::ABOUT_MAX]);
    if ($action==='reader-about-save') { $text=$vault->setReaderAbout($input['text']??null,$key,$input['notes']??null); Studio\json(['text'=>$text,'notes'=>$vault->readerNotes($key)['text']]); }
    if ($action==='story-reading') { $stories->setReading((string)($input['id']??''),$input,$key); Studio\json(['ok'=>true]); }
    if ($action==='story-favorite') { $stories->favorite((string)($input['id']??''),($input['on']??true)!==false); Studio\json(['ok'=>true]); }
    if ($action==='story-delete') { $stories->delete((string)($input['id']??'')); Studio\json(['ok'=>true]); }
    // The whole story in a file, which the page makes: the story, then the conversation's messages a page at a time; the
    // page adds the pictures and makes the zip.
    if ($action==='story-export') {
        $id=(string)($_GET['id']??''); $pack=$stories->export($id,$key,$jobs);
        Studio\json(['pack'=>['conversation'=>$writer->exportConversation($id,$key)]+$pack]);
    }
    if ($action==='story-export-messages') Studio\json($writer->exportMessages((string)($_GET['id']??''),max(0,(int)($_GET['after']??0)),$key));
    // A story from such a file: which of its pictures the account already has (the page uploads the others), the story
    // with those pictures' ids, then its conversation's messages a page at a time.
    if ($action==='story-import-images') {
        $have=[];
        foreach (array_slice(is_array($input['ids']??null)?$input['ids']:[],0,5000) as $image) {
            if (!is_string($image) || !preg_match('/\A[a-f0-9]{32}\z/D',$image)) continue;
            try { $vault->still($image,$key); $have[]=$image; } catch (HttpError) {}
        }
        Studio\json(['have'=>$have]);
    }
    if ($action==='story-import') {
        $pack=is_array($input['pack']??null) ? $input['pack'] : throw new HttpError(422,t('The file does not hold a story.'));
        try { $settings=Writer::settings(null,is_array($pack['story']['writer']??null)?$pack['story']['writer']:[]); } catch (HttpError) { $settings=Writer::settings(null); }
        $made=$stories->import($pack,$settings,$key);
        // The pictures' old ids (the file's) => the ones they have here, for the conversation, which names them.
        $images=array_filter(is_array($input['images']??null)?$input['images']:[],fn($v,$k)=>is_string($k)&&is_string($v),ARRAY_FILTER_USE_BOTH);
        if (is_array($input['conversation']??null)) $writer->importConversation($made['id'],['title'=>$stories->listed($made['id'],$key)['title']]+$input['conversation'],$made['map']+$images,$key);
        // The pictures it sent to the gallery go into the story's albums (the comic's into the adaptation's), as an
        // illustration would: they are found there, out of the library. One the account already had stays where it was.
        $brought=array_values(array_filter(is_array($input['pictures']??null)?array_slice($input['pictures'],0,5000):[],fn($v)=>is_string($v)&&preg_match('/\A[a-f0-9]{32}\z/D',$v)));
        if ($brought) { $stories->album($made['id'],$key,'book',$brought); if ($stories->pictures($made['id'],'comic',$key)) $stories->album($made['id'],$key,'comic',$brought); }
        $limit=Studio\iniBytes(ini_get('post_max_size'));
        Studio\json(['story'=>$stories->listed($made['id'],$key),'dropped'=>$made['dropped'],'batch'=>$limit>0?min(Writer::PACK_BATCH,(int)($limit*0.6)):Writer::PACK_BATCH],201);
    }
    if ($action==='story-import-messages') Studio\json($writer->importMessages((string)($input['story']??''),$input['messages']??null,($input['done']??false)===true,$key));
    // A copy of the story to change on its own: chapters, bible, plan, sheets, cover, comic and the conversation with the writer.
    if ($action==='story-duplicate') Studio\json(['story'=>$writer->duplicate((string)($input['id']??''),$input['title']??null,$key)],201);
    if ($action==='story-document') {
        // The book's bible and plan, or the comic adaptation's own.
        $name=(string)($input['name']??''); if (!in_array($name,['notes','plan','comic_notes','comic_plan'],true)) throw new HttpError(422,t('Invalid document.'));
        $rev=isset($input['rev']) ? (int)$input['rev'] : null;
        Studio\json(['document'=>$stories->writeDocument((string)($input['story']??''),$name,['text'=>$input['text']??''],'person',$key,$rev)]);
    }
    if ($action==='chapter-add') {
        $index=isset($input['index']) ? (int)$input['index'] : null;
        Studio\json(['chapter'=>$stories->addChapter((string)($input['story']??''),(string)($input['title']??''),(string)($input['text']??''),'',$index,'person',$key)],201);
    }
    if ($action==='chapter-save') {
        $change=array_intersect_key($input,array_flip(['title','text','summary']));
        Studio\json(['chapter'=>$stories->updateChapter((string)($input['story']??''),(string)($input['id']??''),$change,'person',$key,isset($input['rev'])?(int)$input['rev']:null)]);
    }
    if ($action==='chapter-delete') { $stories->deleteChapter((string)($input['story']??''),(string)($input['id']??'')); Studio\json(['ok'=>true]); }
    if ($action==='chapter-move') { $stories->moveChapter((string)($input['story']??''),(string)($input['id']??''),(int)($input['index']??0),$key); Studio\json(['ok'=>true]); }
    if ($action==='story-send') Studio\json(['turn'=>$writer->send((string)($input['story']??''),$input['text']??'',is_array($input['writer']??null)?$input['writer']:[],$key,$input['credit']??null,$input['images']??null)],201);
    if ($action==='story-resume') Studio\json(['turn'=>$writer->resume((string)($input['story']??''),$key,$input['credit']??null)],201);
    if ($action==='story-cancel') Studio\json(['turn'=>$writer->cancel((string)($input['turn']??''),$key)]);
    if ($action==='story-review') Studio\json(['turn'=>$writer->review((string)($input['story']??''),is_array($input['writer']??null)?$input['writer']:[],$key,$input['credit']??null)],201);
    if ($action==='story-review-estimate') Studio\json($writer->reviewEstimate((string)($_GET['id']??''),$key));
    if ($action==='story-new-conversation') { $writer->newConversation((string)($input['story']??''),$key); Studio\json(['ok'=>true]); }
    if ($action==='story-run') {
        // A turn can take many minutes, and the web server may stop its PHP processes long before (LiteSpeed on
        // Hostinger stopped the writer at about two minutes, whatever max_execution_time says). Where it can, the turn
        // runs in a separate command line process, which the web server doesn't stop.
        if ($config['separate_process']) { $turn=$writer->runSeparately((string)($input['turn']??''),$key); if ($turn!==null) Studio\json(['turn'=>$turn,'background'=>true,'separate'=>true]); }
        // Otherwise like an image request: answered as soon as the turn is claimed, then on in the background.
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $answered=false; $onClaimed=null;
        $finish=!$config['run_in_background'] ? null : (function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null));
        if ($finish!==null) $onClaimed=static function(array $turn) use ($finish,&$answered): void {
            @ini_set('zlib.output_compression','0');
            while (ob_get_level()>0 && @ob_end_clean());
            http_response_code(200); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['turn'=>$turn,'background'=>true],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            $answered=true; $finish();
        };
        try { $result=['turn'=>$writer->run((string)($input['turn']??''),$key,$onClaimed)]; $code=200; }
        catch (Throwable $e) {
            if (!$e instanceof HttpError) Studio\logFailure($e);
            $result=['error'=>$e instanceof HttpError?$e->getMessage():t('[SERVER] Unexpected failure on the server. See the Atelier entry in PHP\'s error log.')]; $code=$e instanceof HttpError?$e->status:500;
        }
        if ($answered) exit;
        Studio\json($result,$code);
    }
    // The hosting test (Configurações): processes that only wait, to see when and how this server stops each kind. Only
    // the administrator runs it: it starts processes that live up to 10 minutes and shows how the server is set up.
    if (str_starts_with($action,'host-test')) $auth->requireAdmin(t('Only the administrator can use the hosting test.'));
    if ($action==='host-test-status') Studio\json(['test'=>(new Studio\HostTest($vault->dir))->status()]);
    if ($action==='host-test-start') Studio\json(['test'=>(new Studio\HostTest($vault->dir))->begin((int)($input['seconds']??600))]);
    if ($action==='host-test' || $action==='host-test-plain') {
        $test=new Studio\HostTest($vault->dir); $run=(string)($input['run']??''); $kind=(string)($input['kind']??'');
        if (!preg_match('/\A[a-f0-9]{16}\z/D',$run) || !in_array($kind,Studio\HostTest::KINDS,true) || ($kind==='web-plain')!==($action==='host-test-plain')) throw new HttpError(422,t('Invalid test.'));
        if ($kind==='separate') {
            $why=Studio\Spawn::unavailable() ?? (Studio\Spawn::start(__DIR__.'/bin/worker.php',['host-test',$run,$vault->dir]) ? null : t('the process could not be started (proc_open failed)'));
            if ($why!==null) $test->unavailable($run,$kind,$why);
            Studio\json(['started'=>$why===null]);
        }
        // As the writer's turn runs in the web server: answered at once, then on in the background.
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $finish=function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
        if ($finish===null) { $test->unavailable($run,$kind,t('this server does not answer before finishing (no litespeed_finish_request or fastcgi_finish_request)')); Studio\json(['started'=>false]); }
        while (ob_get_level()>0 && @ob_end_clean());
        http_response_code(200); header('Content-Type: application/json; charset=utf-8'); echo '{"started":true}'; $finish();
        $test->run($run,$kind);
        exit;
    }
    // The API balance as this app follows it: the amount entered, the monthly top-up and the tokens spent since then.
    if ($action==='balance') Studio\json($vault->balance($key));
    // The balance of one account (openai, claude, gemini: Vault::ACCOUNTS); OpenAI's when none is named.
    if ($action==='billing') { $vault->setBilling(is_array($input['billing']??null)?$input['billing']:null,$key,is_string($input['account']??null)?$input['account']:'openai'); Studio\json($vault->balance($key)); }
    if ($action==='account') { $users->rename($auth->user()['id'],(string)($input['name']??'')); Studio\json(['user'=>Users::summary($users->find($auth->user()['id']))]); }
    // MCP access for the signed-in account: the token is returned once and never stored in a readable form.
    if ($action==='mcp-token') {
        $token=$users->issueToken($auth->user()['id'],$key);
        Studio\json(['token'=>$token,'url'=>Studio\baseUrl($secure).'mcp.php','user'=>Users::summary($users->find($auth->user()['id']))]);
    }
    if ($action==='mcp-revoke') { $users->revokeToken($auth->user()['id']); Studio\json(['user'=>Users::summary($users->find($auth->user()['id']))]); }
    if ($action==='users') { $auth->requireAdmin(); Studio\json(['users'=>$users->all()]); }
    if ($action==='user-create') {
        $auth->requireAdmin();
        // A new account gets its own random key; its library starts empty and only its password (or token) opens it.
        $newKey=random_bytes(32);
        $created=$users->create((string)($input['name']??''),(string)($input['password']??''),$newKey);
        $copyKey=!empty($input['copy_api_key']) ? $vault->apiKey($key) : '';
        if ($copyKey!=='') { $vault->useOwner($created['id']); $vault->setApiKey($copyKey,$newKey); $vault->useOwner($auth->user()['id']); sodium_memzero($copyKey); }
        $token=!empty($input['mcp']) ? $users->issueToken($created['id'],$newKey) : null;
        sodium_memzero($newKey);
        Studio\json(['user'=>Users::summary($users->find($created['id'])),'token'=>$token,'url'=>Studio\baseUrl($secure).'mcp.php'],201);
    }
    if ($action==='user-delete') {
        $auth->requireAdmin();
        $id=(string)($input['id']??''); Vault::validId($id);
        if ($id===$auth->user()['id']) throw new HttpError(409,t('You cannot delete your own user.'));
        $auth->confirmPassword((string)($input['password']??''));
        if (!$users->find($id)) throw new HttpError(404,t('User not found.'));
        $users->delete($id); Studio\json(['ok'=>true]);
    }
    if ($action==='gallery') {
        Studio\json($vault->gallery($key,mb_substr((string)($_GET['q']??''),0,200),($_GET['favorite']??'')==='1',($_GET['trash']??'')==='1',
            max(0,(int)($_GET['offset']??0)),(int)($_GET['limit']??30),($_GET['group']??'')==='1',in_array($_GET['kind']??'',['image','video'],true)?$_GET['kind']:null));
    }
    if ($action==='library') {
        // Every item of the view at once, as compact rows: the browser lays out and scrolls through all of them.
        // The origin "hidden" is the Ocultas filter: the items hidden from the library, wherever they came from.
        $origin=(string)($_GET['origin']??''); $sort=(string)($_GET['sort']??''); $album=(string)($_GET['album']??'');
        Studio\json($vault->library($key,['search'=>mb_substr((string)($_GET['q']??''),0,200),'favorites'=>($_GET['favorite']??'')==='1','trash'=>($_GET['trash']??'')==='1',
            'origin'=>in_array($origin,Vault::ORIGINS,true)?$origin:null,'hidden'=>$origin==='hidden','album'=>$album===''?null:$album,'group'=>($_GET['group']??'')==='1',
            'sort'=>in_array($sort,Vault::SORTS,true)?$sort:'newest']));
    }
    if ($action==='captions') Studio\json(['items'=>$vault->captions(array_values(array_filter(explode(',',(string)($_GET['ids']??'')))),$key)]);
    if ($action==='albums') Studio\json(['albums'=>$vault->albums($key)]);
    // Whether an album's items show in the library too, or only in the album.
    if ($action==='album-library') { $vault->setAlbumLibrary((string)($input['id']??''),($input['library']??true)!==false); Studio\json(['ok'=>true]); }
    if ($action==='album-create') {
        $album=$vault->createAlbum((string)($input['name']??''),$key);
        if (!empty($input['ids'])) $album['count']=$vault->bulk('album-add',$input['ids'],$album['id']);
        Studio\json(['album'=>$album],201);
    }
    if ($action==='album-rename') { $vault->renameAlbum((string)($input['id']??''),(string)($input['name']??''),$key); Studio\json(['ok'=>true]); }
    if ($action==='album-delete') { $vault->deleteAlbum((string)($input['id']??'')); Studio\json(['ok'=>true]); }
    if ($action==='bulk') Studio\json(['count'=>$vault->bulk((string)($input['op']??''),$input['ids']??null,isset($input['album'])?(string)$input['album']:null)]);
    if ($action==='hide') Studio\json(['count'=>$vault->hide($input['ids']??null,($input['hidden']??true)!==false,!empty($input['whole']),$key)]);
    if ($action==='stack') Studio\json($vault->stack($input['ids']??null,$key));
    if ($action==='unstack') Studio\json(['count'=>$vault->unstack($input['ids']??null,!empty($input['whole']),$key)]);
    if ($action==='cover') { $vault->setCover((string)($input['id']??''),$key,($input['cover']??true)!==false); Studio\json(['ok'=>true]); }
    if ($action==='reorder') { $vault->reorder($input['ids']??null,is_string($input['before']??null)?$input['before']:null); Studio\json(['ok'=>true]); }
    if ($action==='detail') {
        $item=$vault->detail((string)($_GET['id']??''),$key);
        // The prompt as it went to the image API, with what the server added to it.
        if (is_string($item['job_id']??null)) $item['sent_prompt']=$jobs->sentPrompt($item['job_id'],$key);
        Studio\json(['image'=>$item]);
    }
    if ($action==='lineage') Studio\json($vault->lineage((string)($_GET['id']??''),$key));
    if ($action==='image') {
        // Trashed images stay viewable so the trash can show what it holds.
        $id=(string)($_GET['id']??''); $variant=($_GET['variant']??'')==='thumb'?'thumb':'full';
        $item=$vault->image($id,$key,true); $bytes=$vault->bytes($id,$variant,$key,true);
        // A video's still is its poster, a frame picked when it was uploaded.
        if (($item['kind']??'')==='video') { $item['mime']=$item['poster_mime']; unset($_GET['download']); }
        header('Content-Type: '.($variant==='thumb'?'image/webp':$item['mime']));
        if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="atelier-'.$id.'.'.(['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$item['mime']]).'"');
        header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
    }
    if ($action==='preview') {
        $bytes=$vault->preview((string)($_GET['id']??''),$key); $info=@getimagesizefromstring($bytes);
        header('Content-Type: '.($info['mime']??'application/octet-stream')); header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
    }
    if (in_array($action,['favorite','trash','restore','metadata'],true)) {
        $id=(string)($input['id']??''); $vault->image($id,$key,true);
        if ($action==='metadata') {
            $title=trim((string)($input['title']??'')); $prompt=trim((string)($input['prompt']??''));
            if (mb_strlen($title)>160 || mb_strlen($prompt)>32000) throw new HttpError(422,t('Title or prompt too long.'));
            $vault->editMeta($id,$title,$prompt,$key);
        } else $vault->mark($id,$action);
        Studio\json(['ok'=>true]);
    }
    if ($action==='purge') { $vault->purge((string)($input['id']??'')); Studio\json(['ok'=>true]); }
    if ($action==='import') {
        $image=OpenAI::imageInput((string)($input['data']??''));
        $bytes=base64_decode(substr($image['url'],strpos($image['url'],',')+1),true);
        // Limpar, and a crop made with the frame of Expandir: what the device made becomes a version of the image it came from.
        if (isset($input['parent_id'])) Studio\json(['image'=>!empty($input['crop'])
            ? $vault->croppedImage((string)$bytes,(string)$input['parent_id'],$key)
            : $vault->cleanedImage((string)$bytes,(string)$input['parent_id'],(string)($input['clean']??''),$key)],201);
        // A frame saved from a video says which video and where in it.
        $source=is_array($input['source']??null) ? $input['source'] : null;
        Studio\json(['image'=>$vault->importImage((string)$bytes,(string)($input['name']??''),$key,$source)],201);
    }
    if ($action==='video-start') {
        $piece=Studio\videoPieceBytes(ini_get('post_max_size'));
        if ($piece===null) throw new HttpError(503,t('[VIDEO-POST] PHP\'s post_max_size is below 2 MB. Raise it to upload videos.'));
        $size=$input['size']??null;
        if (!is_int($size)) throw new HttpError(422,t('Invalid video size.'));
        Studio\json(['id'=>$vault->startUpload($size,$config['max_video_mb']*1048576),'piece'=>$piece],201);
    }
    if ($action==='video-chunk') {
        $offset=filter_var($_GET['offset']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($offset===false) throw new HttpError(422,t('Invalid position.'));
        $max=8*Vault::VIDEO_CHUNK;
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>$max) throw new HttpError(413,t('Video piece too large.'));
        $data=file_get_contents('php://input',false,null,0,$max+1);
        // PHP drops a body larger than post_max_size: it then arrives empty.
        if ($data===false || $data==='') throw new HttpError(413,t('The video piece arrived empty. Check post_max_size and upload_max_filesize in PHP.'));
        Studio\json(['received'=>$vault->appendUpload((string)($_GET['id']??''),$offset,$data,$key)]);
    }
    if ($action==='video-finish') Studio\json(['image'=>$vault->finishUpload((string)($input['id']??''),$input,(string)($input['poster']??''),$key)],201);
    if ($action==='video-abort') { $vault->abortUpload((string)($input['id']??'')); Studio\json(['ok'=>true]); }
    if ($action==='frames') Studio\json(['frames'=>$vault->frames((string)($_GET['id']??''),$key)]);
    if ($action==='video') {
        // Playback asks for byte ranges as it seeks. Each answer decrypts only the chunks it covers; an open range is cut
        // to 8 MB. A download (?download=1) sends the whole file.
        $item=$vault->video((string)($_GET['id']??''),$key); $size=(int)$item['size']; $download=isset($_GET['download']);
        try { [$first,$last,$partial]=Studio\byteRange($_SERVER['HTTP_RANGE']??null,$size,$download?$size:8*Vault::VIDEO_CHUNK); }
        catch (HttpError) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
        @ini_set('zlib.output_compression','0');
        while (ob_get_level()>0 && @ob_end_clean());
        if (function_exists('set_time_limit')) @set_time_limit(0);
        http_response_code($partial?206:200);
        header('Content-Type: '.$item['mime']); header('Accept-Ranges: bytes'); header('Content-Length: '.($last-$first+1));
        if ($partial) header("Content-Range: bytes $first-$last/$size");
        if ($download) header('Content-Disposition: attachment; filename="atelier-'.$item['id'].'.'.($item['mime']==='video/webm'?'webm':'mp4').'"');
        // The status line is already out: a failure past this point can only be logged.
        try { $vault->streamVideo($item,$first,$last,$key,static function(string $bytes): bool { echo $bytes; @flush(); return !connection_aborted(); }); }
        catch (Throwable $e) { Studio\logFailure($e); }
        exit;
    }
    if ($action==='jobs') {
        $scope=(string)($_GET['scope']??''); $scope=in_array($scope,['active','feed'],true)?$scope:'history';
        $ids=array_values(array_filter(explode(',',(string)($_GET['ids']??''))));
        Studio\json(['jobs'=>$jobs->list($key,$scope,$ids),'now'=>time()]);
    }
    if ($action==='history') Studio\json((new Studio\History($vault,$jobs,$stories))->page($key,['q'=>(string)($_GET['q']??''),'kind'=>(string)($_GET['kind']??'all'),
        'story'=>(string)($_GET['story']??''),'cursor'=>(string)($_GET['cursor']??''),'limit'=>(int)($_GET['limit']??30)])+['now'=>time()]);
    if ($action==='create') { $created=$jobs->create($input,$key); Studio\json(['job'=>$created[0],'jobs'=>$created],201); }
    if ($action==='retry') { $created=$jobs->retry((string)($input['id']??''),(string)($input['from']??''),$input['n']??null,$key); Studio\json(['job'=>$created[0],'jobs'=>$created],201); }
    if ($action==='cancel') Studio\json(['job'=>$jobs->cancel((string)($input['id']??''),$key)]);
    if ($action==='dismiss') { $jobs->dismiss((string)($input['id']??''),$key); Studio\json(['ok'=>true]); }
    if ($action==='run') {
        $id=(string)($input['id']??'');
        // A generation can take minutes, and the web server may stop its PHP processes first (Hostinger's LiteSpeed: at
        // 120 s). Where it can, the generation runs in a separate command line process, which the web server doesn't stop.
        if ($config['separate_process']) { $job=$jobs->runSeparately($id,$key); if ($job!==null) Studio\json(['job'=>$job,'background'=>true,'separate'=>true]); }
        // Hosting may disable these functions; in PHP 8 calling a disabled function throws.
        $detached=false;
        if (function_exists('ignore_user_abort')) { ignore_user_abort(true); $detached=(bool)ignore_user_abort(); }
        if (function_exists('set_time_limit')) @set_time_limit($config['api_timeout']*2+120);
        $keepalive=null; $onClaimed=null; $answered=false;
        $finish=!$config['run_in_background'] ? null : (function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null));
        if ($finish!==null) {
            // The browser gets its answer as soon as the request is claimed, and the generation goes on in this process
            // with no connection left open for a CDN, a proxy or the web server to cut, however long it takes. The browser
            // follows it by polling, as it does with every request.
            $onClaimed=static function(array $job) use ($finish,&$answered): void {
                @ini_set('zlib.output_compression','0');
                while (ob_get_level()>0 && @ob_end_clean());
                http_response_code(200); header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['job'=>$job,'background'=>true],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                $answered=true; $finish();
            };
        } elseif ($detached) {
            // Leading spaces are valid JSON whitespace. They keep FastCGI and proxies from closing a silent request.
            // Only safe when the script survives a closed connection: otherwise output after a disconnect would stop it.
            @ini_set('zlib.output_compression','0');
            while (ob_get_level()>0 && @ob_end_clean());
            header('Content-Type: application/json; charset=utf-8'); header('X-Accel-Buffering: no');
            $keepalive=static function(): void { echo ' '; @flush(); };
        }
        try { $result=['job'=>$jobs->run($id,$key,null,$keepalive,$onClaimed)]; $code=200; }
        catch (Throwable $e) {
            if (!$e instanceof HttpError) Studio\logFailure($e);
            $result=['error'=>$e instanceof HttpError?$e->getMessage():t('[SERVER] Unexpected failure on the server. See the Atelier entry in PHP\'s error log.')]; $code=$e instanceof HttpError?$e->status:500;
        }
        // The browser already has its answer and learns the end by polling.
        if ($answered) exit;
        // Once keepalive bytes went out the status is already 200: the client reads `error` from the body.
        if (headers_sent()) { echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
        Studio\json($result,$code);
    }
    throw new HttpError(404,t('Route not found.'));
} catch (Throwable $e) {
    if (!$e instanceof HttpError) Studio\logFailure($e);
    Studio\json(['error'=>$e instanceof HttpError?$e->getMessage():t('[SERVER] Unexpected failure on the server. See the Atelier entry in PHP\'s error log.')], $e instanceof HttpError?$e->status:500);
}
