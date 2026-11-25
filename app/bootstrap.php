<?php
declare(strict_types=1);
namespace Studio;

/** An error with the HTTP status to answer and a message for the person. $kind names a case the page treats apart (credit). */
final class HttpError extends \RuntimeException
{ public function __construct(public readonly int $status, string $message, public readonly ?string $kind=null) { parent::__construct($message); } }

/** The app's version: the newest one in CHANGELOG.md, changed with each release. Settings shows it, and MCP clients get it. */
const VERSION = '1.4.2';

require_once __DIR__.'/I18n.php';
require_once __DIR__.'/Crypto.php';
require_once __DIR__.'/Vault.php';
require_once __DIR__.'/Users.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/ImageClient.php';
require_once __DIR__.'/OpenAI.php';
require_once __DIR__.'/Gemini.php';
require_once __DIR__.'/GeminiImages.php';
require_once __DIR__.'/GpuImages.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/Presets.php';
require_once __DIR__.'/Jobs.php';
require_once __DIR__.'/Gpu.php';
require_once __DIR__.'/VideoPrompt.php';
require_once __DIR__.'/Mcp.php';
require_once __DIR__.'/Stream.php';
require_once __DIR__.'/Claude.php';
require_once __DIR__.'/Gpt.php';
require_once __DIR__.'/Stories.php';
require_once __DIR__.'/Repetitions.php';
require_once __DIR__.'/Sketch.php';
require_once __DIR__.'/Comics.php';
require_once __DIR__.'/Vn.php';
require_once __DIR__.'/WriterVn.php';
require_once __DIR__.'/Writer.php';
require_once __DIR__.'/History.php';
require_once __DIR__.'/Spawn.php';
require_once __DIR__.'/HostTest.php';
require_once __DIR__.'/KeyTest.php';
require_once __DIR__.'/Worker.php';

function configuration(?string $directory=null): array
{
    // The example is a template, not a runtime dependency: users may rename it.
    $defaults=['setup_token'=>'','data_dir'=>'','require_https'=>true,'trusted_proxy'=>false,'session_minutes'=>60,'api_timeout'=>600,'max_parallel'=>4,'max_video_mb'=>2048,'run_in_background'=>true,'separate_process'=>true];
    $file=($directory??dirname(__DIR__)).'/config.php';
    $config=$defaults;
    if (file_exists($file)) {
        if (!is_file($file) || !is_readable($file)) throw new HttpError(503,t('[CONFIG-READ] Could not read config.php. Check that file\'s permissions.'));
        // Never let accidental output from a configuration file expose secrets.
        ob_start();
        try { $custom=require $file; }
        catch (\Throwable $e) {
            logFailure($e);
            throw new HttpError(503,t('[CONFIG-PHP] config.php has a PHP error. Check its syntax using config.example.php as a model.'));
        } finally { ob_end_clean(); }
        if (!is_array($custom)) throw new HttpError(503,t('[CONFIG-RETURN] config.php must start with <?php and return an array with return [...];. Use config.example.php as a model.'));
        $config=array_merge($defaults,$custom);
    }
    foreach (['setup_token','data_dir'] as $name) if (!is_string($config[$name])) throw new HttpError(503,t('[CONFIG-VALUE] {name} must be a quoted string in config.php.', ['name'=>$name]));
    foreach (['require_https','trusted_proxy','run_in_background','separate_process'] as $name) if (!is_bool($config[$name])) throw new HttpError(503,t('[CONFIG-VALUE] {name} must be true or false, without quotes, in config.php.', ['name'=>$name]));
    foreach (['api_timeout','session_minutes','max_parallel','max_video_mb'] as $name) if (!is_int($config[$name]) && !(is_string($config[$name]) && preg_match('/\A[0-9]+\z/D',$config[$name]))) throw new HttpError(503,t('[CONFIG-VALUE] {name} must be a whole number in config.php.', ['name'=>$name]));
    $config['api_timeout']=max(60,min(1200,(int)$config['api_timeout']));
    $config['session_minutes']=max(5,min(1440,(int)$config['session_minutes']));
    $config['max_parallel']=max(1,min(16,(int)$config['max_parallel']));
    $config['max_video_mb']=max(0,min(1048576,(int)$config['max_video_mb']));
    return $config;
}
/** Whether the request arrived over HTTPS (directly, or through a trusted proxy). */
function isSecure(array $config): bool
{ return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || ($config['trusted_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'); }
function isLocal(): bool
{ return in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) && in_array($_SERVER['SERVER_NAME']??'',['localhost','127.0.0.1','::1'],true); }
/** Public address of the app's folder, as the client reached it, with a trailing slash. */
function baseUrl(bool $secure): string
{
    $host=(string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'localhost');
    if (!preg_match('/\A[A-Za-z0-9.\-\[\]:]{1,255}\z/D',$host)) $host='localhost';
    return ($secure?'https':'http').'://'.$host.rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php')),'/').'/';
}
function privateDirectory(array $config): string
{
    $doc=@realpath($_SERVER['DOCUMENT_ROOT']??dirname(__DIR__))?:dirname(__DIR__);
    $path=$config['data_dir']?:automaticDirectory($doc,dirname(__DIR__));
    if (!str_starts_with($path,'/') || str_contains($path,'..')) throw new HttpError(503,t('Set data_dir to an absolute path outside the public folder.'));
    $resolved=@realpath($path);
    if (!$resolved) {
        $ancestor=$path;
        while (!@is_dir($ancestor) && $ancestor!=='/') $ancestor=dirname($ancestor);
        $base=@realpath($ancestor);
        if ($base===false) throw new HttpError(503,t('[DATA-PATH] PHP cannot reach the private folder. Set data_dir outside the public folder and inside the paths the host allows (open_basedir).'));
        $resolved=$base.substr($path,strlen($ancestor));
    }
    foreach ([$doc,dirname(__DIR__)] as $public) {
        if ($resolved===$public || str_starts_with($resolved,rtrim($public,'/').'/')) throw new HttpError(503,t('The private folder must be outside the server\'s public folder.'));
    }
    return $resolved;
}
/**
 * The private folder when config.php names none. It used to be derived only from where the app's folder sits, so moving
 * the folder (a new subdomain, a renamed folder) pointed the app at a new, empty library. Now the app writes down where
 * its library is (data-dir.php, next to config.php, so it moves with the folder) and, when nothing is written down and the
 * usual place holds no library, looks for the library a previous location left behind. It never picks between two.
 */
function automaticDirectory(string $doc, string $app): string
{
    $pinFile=$app.'/data-dir.php';
    $pinned=readDataPin($pinFile);
    // A pin is written only for a folder that held accounts, so finding its database is enough.
    if ($pinned!==null && @is_file($pinned.'/vault.sqlite')) return $pinned;
    $name='.imagegen-'.substr(hash('sha256',$app),0,12);
    $usual=dirname($doc).'/'.$name;
    if (hasLibrary($usual)) { writeDataPin($pinFile,$usual,$pinned); return $usual; }
    $found=[];
    foreach (libraryCandidates($doc) as $dir) {
        $real=@realpath($dir);
        if ($real!==false && !isset($found[$real]) && hasLibrary($real)) $found[$real]=@filemtime($real.'/vault.sqlite')?:0;
    }
    if (count($found)===1) { $dir=(string)array_key_first($found); writeDataPin($pinFile,$dir,$pinned); return $dir; }
    if (count($found)>1) {
        arsort($found);
        error_log('Atelier: more than one library from a previous location: '.implode(', ',array_keys($found)).'. Set data_dir in config.php.');
        $places=implode('; ',array_map(fn($dir,$time)=>'…/'.basename(dirname($dir)).'/'.basename($dir).($time?' '.t('(changed on {date})',['date'=>I18n::date($time)]):''),array_keys($found),$found));
        throw new HttpError(503,t('[DATA-MANY] This host has more than one library from earlier installations: {places}. Name the right one in data_dir, in config.php. The full path is in PHP\'s error log (entry Atelier).',['places'=>$places]));
    }
    // A new installation. Inside a public_html (a subdomain whose folder sits in the main site's), the folder next to the
    // DocumentRoot would be published by the main site, so the library goes beside the outermost public_html instead.
    $public=strpos($doc.'/','/public_html/');
    return ($public!==false ? substr($doc,0,$public) : dirname($doc)).'/'.$name;
}
/** Folders a previous location of the app may have used: next to every folder above the DocumentRoot, up to the account's. */
function libraryCandidates(string $doc): array
{
    $dirs=[];
    for ($dir=dirname($doc); substr_count($dir,'/')>=2; $dir=dirname($dir)) {
        $patterns=["$dir/.imagegen-*"];
        // Hostinger and similar panels keep each domain and subdomain in domains/<name>/public_html.
        if (basename($dir)==='domains') array_push($patterns,"$dir/*/.imagegen-*","$dir/*/public_html/.imagegen-*");
        foreach ($patterns as $pattern) foreach (@glob($pattern,GLOB_ONLYDIR|GLOB_NOSORT)?:[] as $match) $dirs[]=$match;
    }
    return $dirs;
}
/** Whether the folder holds a library with at least one account (an empty one is what a new location creates). */
function hasLibrary(string $dir): bool
{
    $file=$dir.'/vault.sqlite';
    if (!@is_file($file) || !@is_readable($file)) return false;
    try {
        $db=new \PDO('sqlite:'.$file,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::SQLITE_ATTR_OPEN_FLAGS=>\PDO::SQLITE_OPEN_READONLY]);
        $db->exec('PRAGMA busy_timeout=2000');
        $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','settings')")->fetchAll(\PDO::FETCH_COLUMN);
        if (in_array('users',$tables,true) && $db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn()) return true;
        // A library from before accounts existed keeps its key in settings until its first unlock.
        return in_array('settings',$tables,true) && (bool)$db->query("SELECT 1 FROM settings WHERE name='wrapped_key'")->fetchColumn();
    } catch (\Throwable) { return false; }
}
/** data-dir.php starts with exit, so the server never shows the path even where .htaccess is ignored. */
function readDataPin(string $file): ?string
{
    $text=@file_get_contents($file,false,null,0,4096);
    if (!is_string($text) || !preg_match('/\A<\?php exit; \?>\n(\/[^\n\0]+)\n/',$text,$m) || str_contains($m[1],'..')) return null;
    return $m[1];
}
function writeDataPin(string $file, string $dir, ?string $current): void
{
    if ($current===$dir) return;
    $temporary=$file.'.'.bin2hex(random_bytes(4)).'.tmp';
    // The app's folder may not be writable; the search above then simply runs again on the next request.
    if (@file_put_contents($temporary,"<?php exit; ?>\n".$dir."\n// Where this installation keeps its library. Written by the app; see docs/install.md.\n")===false) return;
    @chmod($temporary,0600);
    if (!@rename($temporary,$file)) @unlink($temporary);
}
function logFailure(\Throwable $error): void
{
    // Do not log exception messages, request bodies, prompts, passwords or keys. A database error says what SQLite said
    // ("database disk image is malformed", "UNIQUE constraint failed: stories.id"): the app's own SQL binds every value,
    // so those words name what broke, never what a person wrote. Without them a damaged database looked like a bug.
    error_log('Atelier: '.get_class($error).' in '.basename($error->getFile()).':'.$error->getLine().databaseReason($error));
}
function databaseReason(\Throwable $error): string
{
    if (!$error instanceof \PDOException) return '';
    $info=is_array($error->errorInfo) ? $error->errorInfo : [];
    $code=is_string($info[0]??null) ? $info[0] : (string)$error->getCode();
    $words=is_string($info[2]??null) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/',' ',$info[2]) ?? ''),0,200) : '';
    return ' (SQLSTATE '.$code.(is_int($info[1]??null) ? ', SQLite '.$info[1] : '').($words!=='' ? ': '.$words : '').')';
}
function headers(): void
{
    header('Cache-Control: no-store, no-transform, private, max-age=0'); header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive, noimageindex');
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}
function input(): array
{
    if (!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) throw new HttpError(415,'Envie JSON.');
    $max=48*1024*1024;
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>$max) throw new HttpError(413,t('The references go over the limit of 48 MB per request.'));
    $raw=file_get_contents('php://input',false,null,0,$max+1);
    if ($raw===false || strlen($raw)>$max) throw new HttpError(413,t('Request too large.'));
    try { $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new HttpError(400,t('Invalid JSON.')); }
    if (!is_array($data)) throw new HttpError(400,t('Invalid request.')); return $data;
}
/** Bytes of a php.ini size such as "8M" or "1G"; 0 means no limit. */
function iniBytes(string|false $value): int
{
    $value=trim((string)$value);
    if ($value==='' || !preg_match('/\A([0-9]+)\s*([KMG]?)\z/i',$value,$m)) return 0;
    return (int)$m[1]*['' =>1,'K'=>1024,'M'=>1048576,'G'=>1073741824][strtoupper($m[2])];
}
/**
 * Size of each piece of a video upload: a whole number of storage chunks that fits in post_max_size, so the
 * host's upload limits never cap the size of a video. Null when post_max_size is too small even for one chunk.
 */
function videoPieceBytes(string|false $postMax): ?int
{
    $limit=iniBytes($postMax); $chunk=Vault::VIDEO_CHUNK;
    if ($limit===0) return 8*$chunk;
    $pieces=intdiv(max(0,$limit-65536),$chunk);
    return $pieces<1 ? null : min(8,$pieces)*$chunk;
}
/**
 * The bytes to send for a Range header: [first, last, partial]. One range only; an open range ("bytes=N-") is cut
 * to $cap bytes so a player that seeks around never makes the server decrypt the rest of the file.
 * @return array{0:int,1:int,2:bool}
 */
function byteRange(?string $header, int $size, int $cap): array
{
    if ($header===null || $header==='' || $size<1) return [0,max(0,$size-1),false];
    if (!preg_match('/\Abytes=\s*([0-9]*)\s*-\s*([0-9]*)\s*\z/D',$header,$m) || ($m[1]==='' && $m[2]==='')) return [0,$size-1,false];
    if ($m[1]==='') { $length=min((int)$m[2],$size); if ($length<1) throw new HttpError(416,t('Invalid range.')); return [$size-$length,$size-1,true]; }
    $start=(int)$m[1];
    if ($start>=$size) throw new HttpError(416,t('Invalid range.'));
    $end=$m[2]==='' ? min($size-1,$start+$cap-1) : min($size-1,(int)$m[2]);
    if ($end<$start) throw new HttpError(416,t('Invalid range.'));
    return [$start,$end,true];
}
function json(array $data, int $code=200): never
{ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }

// The interface's language for this request (or this command line process): see I18n.
I18n::use(I18n::detect());
