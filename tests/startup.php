<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
use Studio\{HttpError,Vault};

$count=0;
function check(bool $ok,string $message): void { global $count; if(!$ok)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }
function startupRejects(callable $fn,string $code): void {
    try { $fn(); } catch(HttpError $e) { check($e->status===503 && str_contains($e->getMessage(),$code),'actionable startup error '.$code);return; }
    throw new RuntimeException('Expected '.$code);
}
$dir=sys_get_temp_dir().'/imagegen-startup-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
    $defaults=Studio\configuration($dir);
    check($defaults['require_https'] && $defaults['setup_token']==='','fresh installation has safe defaults without example file');
    check($defaults===(require dirname(__DIR__).'/config.example.php'),'example matches built-in defaults');
    file_put_contents($dir.'/config.php',"<?php return ['setup_token'=>'installation-token-test-only','session_minutes'=>30];");
    $c=Studio\configuration($dir);
    check($c['setup_token']==='installation-token-test-only' && $c['session_minutes']===30 && $c['require_https'],'renamed example configuration loads without template dependency');
    file_put_contents($dir.'/config.php',"<?php echo 'must-never-be-disclosed'; return null;");
    ob_start();startupRejects(fn()=>Studio\configuration($dir),'CONFIG-RETURN');$output=ob_get_clean();
    check(!str_contains($output,'must-never-be-disclosed'),'configuration output is never exposed');
    file_put_contents($dir.'/config.php',"<?php return [ 'setup_token' => ;");
    startupRejects(fn()=>Studio\configuration($dir),'CONFIG-PHP');
    foreach(['data_dir'=>[], 'require_https'=>'false', 'session_minutes'=>[]] as $field=>$value) {
        file_put_contents($dir.'/config.php','<?php return '.var_export([$field=>$value],true).';');
        startupRejects(fn()=>Studio\configuration($dir),'CONFIG-VALUE');
    }
    file_put_contents($dir.'/occupied','file, not directory');
    startupRejects(fn()=>new Vault($dir.'/occupied'),'DATA-CREATE');
    mkdir($dir.'/invalid-db',0700);file_put_contents($dir.'/invalid-db/vault.sqlite','invalid sqlite file');
    startupRejects(fn()=>new Vault($dir.'/invalid-db'),'DATA-SQLITE');
    mkdir($dir.'/public_html',0700);$_SERVER['DOCUMENT_ROOT']=$dir.'/public_html';
    check(Studio\privateDirectory(['data_dir'=>$dir.'/private'])===$dir.'/private','custom private directory remains outside document root');
    try {Studio\privateDirectory(['data_dir'=>$dir.'/public_html/private']);throw new RuntimeException('Public storage accepted');}
    catch(HttpError $e){check($e->status===503,'storage inside public directory still rejected');}

    // Moving the app's folder (a new subdomain) keeps the library.
    $library=function(string $path,bool $account=true): void {
        if(!is_dir($path))mkdir($path,0700,true);
        $db=new PDO('sqlite:'.$path.'/vault.sqlite');$db->exec('CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, name TEXT)');
        if($account)$db->exec("INSERT INTO users VALUES ('u','dono')");
    };
    $usual=fn(string $doc,string $app)=>dirname($doc).'/.imagegen-'.substr(hash('sha256',$app),0,12);
    $domains=$dir.'/home/conta/domains';$site=$domains.'/exemplo.com/public_html';
    $old=$site.'/imagegen';mkdir($old,0700,true);
    $data=$usual($site,$old);$library($data);
    check(Studio\automaticDirectory($site,$old)===$data,'an installation that never moved keeps its library');
    check(Studio\readDataPin($old.'/data-dir.php')===$data,'the app writes down where its library is');
    $shown=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($old.'/data-dir.php'));
    check($shown===''||$shown===null,'the written-down path never shows when the file is opened');
    $new=$site.'/atelier';rename($old,$new);
    check(Studio\automaticDirectory($new,$new)===$data,'a moved folder finds its library through what it wrote down');
    unlink($new.'/data-dir.php');$library($usual($new,$new),false);
    check(Studio\automaticDirectory($new,$new)===$data,'a moved folder without the note finds the library left behind, not the empty one');
    check(Studio\readDataPin($new.'/data-dir.php')===$data,'and writes it down');
    $own=$domains.'/atelier.exemplo.com/public_html';mkdir($own,0700,true);
    check(Studio\automaticDirectory($own,$own)===$data,'a subdomain with its own folder finds the library of the main site');
    unlink($own.'/data-dir.php');$library($domains.'/outro.com/.imagegen-0123456789ab');
    startupRejects(fn()=>Studio\automaticDirectory($own,$own),'DATA-MANY');
    $fresh=$dir.'/novo/home/conta/domains/novo.com/public_html/app';mkdir($fresh,0700,true);
    check(Studio\automaticDirectory($fresh,$fresh)===$dir.'/novo/home/conta/domains/novo.com/'.basename($usual($fresh,$fresh)),'a new installation inside public_html keeps its library above public_html');
    check(!file_exists($fresh.'/data-dir.php'),'an empty new library is not written down');
    $legacy=$dir.'/antigo/site';mkdir($legacy,0700,true);$before=$usual($legacy,$legacy);mkdir($before,0700,true);
    (new PDO('sqlite:'.$before.'/vault.sqlite'))->exec("CREATE TABLE settings (name TEXT PRIMARY KEY, value BLOB NOT NULL); INSERT INTO settings VALUES ('wrapped_key',x'00')");
    check(Studio\hasLibrary($before),'a library from before accounts still counts');
    echo "\n$count startup checks passed. No real API calls.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);
}
