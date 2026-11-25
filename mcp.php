<?php
declare(strict_types=1);
// Remote MCP endpoint (Streamable HTTP). Authenticated by a per-account token, never by cookies:
//   POST mcp.php                      Authorization: Bearer <token>   (or mcp.php?token=<token> for clients without headers)
//   GET  mcp.php?file=<link>          full image (or video, in byte ranges) from a download link returned by a tool
require __DIR__.'/app/bootstrap.php';
use Studio\{Vault,Users,Jobs,Mcp,HttpError};
// MCP clients are models: they get the app's own words, in English, whatever the browser says.
Studio\I18n::use('en');

Studio\headers();
$method=$_SERVER['REQUEST_METHOD']??'GET';
$fail=static function(int $status, string $message, int $code=-32000): never {
    if ($status===401) header('WWW-Authenticate: Bearer realm="Atelier"');
    Studio\json(Mcp::error(null,$code,$message),$status);
};
try {
    foreach (['pdo_sqlite','sodium','curl','gd','mbstring'] as $ext) if (!extension_loaded($ext)) throw new HttpError(503,'Turn on the PHP extension '.$ext.' on the server.');
    if (PHP_VERSION_ID<80200) throw new HttpError(503,'Use PHP 8.2 or later.');
    $config=Studio\configuration();
    $secure=Studio\isSecure($config);
    if ($config['require_https'] && !$secure && !Studio\isLocal()) throw new HttpError(503,'Use HTTPS.');
    $vault=new Vault(Studio\privateDirectory($config));

    if ($method==='GET' && isset($_GET['file'])) {
        [$item,$linkKey]=$vault->linkKey((string)$_GET['file']);
        if (($item['kind']??'image')==='video') {
            // A video in byte ranges, as the app's player asks for them (index.php?action=video): only the chunks asked for are decrypted.
            $item=$vault->video((string)$item['id'],$linkKey); $size=(int)$item['size'];
            try { [$first,$last,$partial]=Studio\byteRange($_SERVER['HTTP_RANGE']??null,$size,$size); }
            catch (HttpError) { sodium_memzero($linkKey); http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
            @ini_set('zlib.output_compression','0');
            while (ob_get_level()>0 && @ob_end_clean());
            if (function_exists('set_time_limit')) @set_time_limit(0);
            http_response_code($partial?206:200);
            header('Content-Type: '.$item['mime']); header('Accept-Ranges: bytes'); header('Content-Length: '.($last-$first+1));
            if ($partial) header("Content-Range: bytes $first-$last/$size");
            header('Content-Disposition: attachment; filename="atelier-'.$item['id'].'.'.($item['mime']==='video/webm'?'webm':'mp4').'"');
            try { $vault->streamVideo($item,$first,$last,$linkKey,static function(string $bytes): bool { echo $bytes; @flush(); return !connection_aborted(); }); }
            catch (Throwable $e) { Studio\logFailure($e); }
            finally { sodium_memzero($linkKey); }
            exit;
        }
        try { $bytes=$vault->bytes((string)$item['id'],'full',$linkKey); } finally { sodium_memzero($linkKey); }
        $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$item['mime']];
        header('Content-Type: '.$item['mime']); header('Content-Length: '.strlen($bytes));
        header('Content-Disposition: attachment; filename="atelier-'.$item['id'].'.'.$extension.'"');
        echo $bytes; exit;
    }
    // No server-initiated stream is offered (GET) and there is no session to end (DELETE).
    if ($method!=='POST') { header('Allow: POST'); $fail(405,'Use POST.'); }

    // Some PHP setups only pass the Authorization header through the rewrite rule in .htaccess.
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if ($header==='' && function_exists('getallheaders')) foreach (getallheaders() as $name=>$value) if (strcasecmp($name,'Authorization')===0) $header=(string)$value;
    $token=preg_match('/\ABearer\s+(\S+)\z/i',trim($header),$m) ? $m[1] : (string)($_GET['token']??'');
    $users=new Users($vault);
    $resolved=$token==='' ? null : $users->resolveToken($token);
    if (!$resolved) {
        $vault->throttle('mcp:'.($_SERVER['REMOTE_ADDR']??''),30,900);
        $fail(401,$token==='' ? 'Missing access token.' : 'Invalid or revoked access token.');
    }
    [$user,$key]=$resolved;
    $vault->useOwner($user['id']);
    Studio\Catalog::use($vault->modelLists($key));
    // Origin is not checked: authentication is a bearer token that a browser never sends on its own, and there are
    // no cookies or CORS headers here, so another site can neither call nor read this endpoint.

    try { $body=Studio\input(); }
    catch (HttpError $e) { $fail($e->status,$e->status===400 ? 'Parse error' : $e->getMessage(),$e->status===400 ? -32700 : -32600); }
    $batch=array_is_list($body) && $body!==[];
    $messages=$batch ? $body : [$body];
    $mcp=new Mcp($vault,new Jobs($vault,$config),$key,Studio\baseUrl($secure),$config);
    $answer=static function(mixed $message,?Closure $keepalive=null) use ($mcp): ?array {
        try { return $mcp->handle($message,$keepalive); }
        catch (Throwable $e) {
            if (!$e instanceof HttpError) Studio\logFailure($e);
            return Mcp::error(is_array($message) ? ($message['id']??null) : null,-32603,$e instanceof HttpError ? $e->getMessage() : 'Internal error. See the Atelier entry in the PHP error log.');
        }
    };

    if (!array_filter($messages,[Mcp::class,'isLong'])) {
        $responses=array_values(array_filter(array_map($answer,$messages)));
        if (!$responses) { http_response_code(202); exit; }
        Studio\json($batch ? $responses : $responses[0]);
    }

    // A generation waits minutes for the provider. Answer as a server-sent event stream when the client accepts one
    // (comments or progress notifications every 10 s keep proxies from closing it), otherwise as JSON preceded by
    // whitespace. Either way the request keeps running if the client goes away, and its images stay in the library.
    $sse=str_contains(strtolower($_SERVER['HTTP_ACCEPT']??''),'text/event-stream');
    if (function_exists('ignore_user_abort')) ignore_user_abort(true);
    @ini_set('zlib.output_compression','0');
    while (ob_get_level()>0 && @ob_end_clean());
    header('Content-Type: '.($sse ? 'text/event-stream' : 'application/json; charset=utf-8')); header('X-Accel-Buffering: no');
    $emit=static function(array $message) use ($sse): void {
        $json=json_encode($message,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        echo $sse ? "event: message\ndata: $json\n\n" : $json; @flush();
    };
    $responses=[];
    foreach ($messages as $message) {
        $progress=is_array($message) ? ($message['params']['_meta']['progressToken']??null) : null;
        $started=time(); $last=time();
        $keepalive=static function() use ($sse,$progress,$started,&$last,$emit): void {
            if (time()-$last<8) return;
            $last=time();
            if ($sse && (is_string($progress) || is_int($progress))) $emit(['jsonrpc'=>'2.0','method'=>'notifications/progress','params'=>['progressToken'=>$progress,'progress'=>time()-$started,'message'=>'Generating…']]);
            else { echo $sse ? ": keepalive\n\n" : ' '; @flush(); }
        };
        $response=$answer($message,Mcp::isLong($message) ? $keepalive : null);
        if ($response!==null) $responses[]=$response;
    }
    if (!$responses) exit;
    if ($sse) { foreach ($responses as $response) $emit($response); exit; }
    $emit($batch ? $responses : $responses[0]); exit;
} catch (Throwable $e) {
    if (!$e instanceof HttpError) Studio\logFailure($e);
    if (headers_sent()) exit;
    $fail($e instanceof HttpError ? $e->status : 500,$e instanceof HttpError ? $e->getMessage() : 'Internal error. See the Atelier entry in the PHP error log.',-32603);
}
