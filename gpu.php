<?php
declare(strict_types=1);
// The GPU worker's endpoint (Gpu): a notebook on a GPU, such as Google Colab, takes the account's video and picture
// requests from here and sends the results back. Every call is short, so no web server limit gets in the way, and the
// worker never needs an address of its own. Authenticated by the account's worker token, never by cookies:
//   POST gpu.php?action=hello|pulse|claim|progress|upload|finish|fail|bye   Authorization: Bearer <token>, JSON body
//   POST gpu.php?action=chunk&job=<id>&upload=<id>&offset=<n>                a piece of the video or picture, raw bytes
//   POST gpu.php?action=media&job=<id>&index=<n>&offset=<n>                  a piece of a video the request starts from
//   POST gpu.php?action=input&job=<id>&index=<n|mask>                        a picture a picture request sends
//   POST gpu.php?action=worker                                               the worker's code, as this server has it
require __DIR__.'/app/bootstrap.php';
use Studio\{Vault,Jobs,Gpu,HttpError};
use function Studio\t;

Studio\headers();
try {
    foreach (['pdo_sqlite','sodium','curl','gd','mbstring'] as $ext) if (!extension_loaded($ext)) throw new HttpError(503,t('Turn on the PHP extension {ext} on the server.', ['ext'=>$ext]));
    if (PHP_VERSION_ID<80200) throw new HttpError(503,t('Use PHP 8.2 or later.'));
    $config=Studio\configuration();
    $secure=Studio\isSecure($config);
    if ($config['require_https'] && !$secure && !Studio\isLocal()) throw new HttpError(503,t('Use HTTPS to protect your password and your images.'));
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { header('Allow: POST'); throw new HttpError(405,t('Method not allowed.')); }
    $vault=new Vault(Studio\privateDirectory($config));

    // Some PHP setups only pass the Authorization header through the rewrite rule in .htaccess.
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if ($header==='' && function_exists('getallheaders')) foreach (getallheaders() as $name=>$value) if (strcasecmp($name,'Authorization')===0) $header=(string)$value;
    $token=preg_match('/\ABearer\s+(\S+)\z/i',trim($header),$m) ? $m[1] : '';
    $gpu=new Gpu($vault,$config);
    $resolved=$token==='' ? null : $gpu->resolveToken($token);
    if (!$resolved) {
        $vault->throttle('gpu:'.($_SERVER['REMOTE_ADDR']??''),30,900);
        header('WWW-Authenticate: Bearer realm="Atelier"');
        throw new HttpError(401,$token==='' ? t('Missing worker token.') : t('Invalid or revoked worker token. Make a new one in Atelier, Settings › GPU.'));
    }
    [$user,$key]=$resolved;
    $vault->useOwner($user['id']);
    $jobs=new Jobs($vault,$config);
    $action=(string)($_GET['action']??'');
    // The notebook loads the worker from here, so it always matches this server's version.
    if ($action==='worker') {
        $code=@file_get_contents(__DIR__.'/colab/atelier_worker.py');
        if ($code===false) throw new HttpError(503,t('The worker\'s file (colab/atelier_worker.py) is missing on the server. Upload the whole app again.'));
        header('Content-Type: text/x-python; charset=utf-8'); header('Cache-Control: no-store');
        echo $code; exit;
    }
    // A video of a request (the one it continues or follows, or a reference), 8 MB at a time from offset, as stored.
    if ($action==='media') {
        $index=filter_var($_GET['index']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($index===false || $offset===false) throw new HttpError(422,t('Invalid position.'));
        $item=$jobs->videoMedia((string)($_GET['job']??''),$index,$key); $size=(int)$item['size'];
        if ($offset>=$size) throw new HttpError(416,t('Invalid range.'));
        $last=min($size,$offset+8*Vault::VIDEO_CHUNK)-1;
        @ini_set('zlib.output_compression','0');
        while (ob_get_level()>0 && @ob_end_clean());
        header('Content-Type: application/octet-stream'); header('Content-Length: '.($last-$offset+1)); header('X-Size: '.$size); header('Cache-Control: no-store');
        try { $vault->streamVideo($item,$offset,$last,$key,static function(string $bytes): bool { echo $bytes; @flush(); return !connection_aborted(); }); }
        catch (Throwable $e) { Studio\logFailure($e); }
        exit;
    }
    // A picture a request sends (the one it changes, a reference, the painted area), whole: tens of MB at most.
    if ($action==='input') {
        $picture=$jobs->inputPicture((string)($_GET['job']??''),(string)($_GET['index']??''),$key);
        @ini_set('zlib.output_compression','0');
        while (ob_get_level()>0 && @ob_end_clean());
        header('Content-Type: '.$picture['mime']); header('Content-Length: '.strlen($picture['bytes'])); header('Cache-Control: no-store');
        echo $picture['bytes']; exit;
    }
    $input=$action==='chunk' ? [] : Studio\input();
    // Every call also says how the worker is, so the page knows it is on and what it does.
    $directives=is_array($input['state']??null) ? $gpu->heartbeat($input['state']) : null;

    if ($action==='hello') {
        $piece=Studio\videoPieceBytes(ini_get('post_max_size'));
        Studio\json(['account'=>(string)$user['name'],'piece'=>$piece,'max_video_bytes'=>$config['max_video_mb']*1048576,
            'engines'=>Gpu::workerEngines(),'queued'=>$jobs->queuedEngines($key),'recent'=>$jobs->recentEngine($key)]+($directives??$gpu->heartbeat(['phase'=>'setup'])));
    }
    if ($action==='claim') {
        // Waits up to 20 s for a request, so a new one starts at once without the worker calling every second.
        $engines=array_values(array_filter(is_array($input['engines']??null)?$input['engines']:[],'is_string'));
        $until=microtime(true)+max(0,min(20,(int)($input['wait']??0)));
        if (function_exists('set_time_limit')) @set_time_limit(60);
        $first=true;
        do {
            // A token revoked (or replaced) while this call waits takes no request: its notebook turns off instead.
            if (!$first && !$gpu->resolveToken($token)) throw new HttpError(401,t('Invalid or revoked worker token. Make a new one in Atelier, Settings › GPU.'));
            $first=false;
            $job=$jobs->claimGpu($engines,$key);
            if ($job!==null) break;
            $directives=$gpu->directives();
            if ($directives['stop'] || microtime(true)>=$until || connection_aborted()) break;
            usleep(1000000);
        } while (true);
        Studio\json(['job'=>$job]+($directives??[]));
    }
    if ($action==='pulse') Studio\json($directives??$gpu->directives());
    if ($action==='progress') Studio\json(['cancel'=>$jobs->videoProgress((string)($input['job']??''),$input,$key)]+($directives??[]));
    if ($action==='upload') {
        $piece=Studio\videoPieceBytes(ini_get('post_max_size'));
        if ($piece===null) throw new HttpError(503,t('[VIDEO-POST] PHP\'s post_max_size is below 2 MB. Raise it to upload videos.'));
        $size=$input['size']??null;
        if (!is_int($size)) throw new HttpError(422,t('Invalid video size.'));
        Studio\json(['upload'=>$jobs->startVideoUpload((string)($input['job']??''),$size,$config['max_video_mb']*1048576,$key),'piece'=>$piece],201);
    }
    if ($action==='chunk') {
        $offset=filter_var($_GET['offset']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($offset===false) throw new HttpError(422,t('Invalid position.'));
        $upload=(string)($_GET['upload']??'');
        $jobs->videoUpload((string)($_GET['job']??''),$upload,$key);
        $max=8*Vault::VIDEO_CHUNK;
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>$max) throw new HttpError(413,t('Video piece too large.'));
        $data=file_get_contents('php://input',false,null,0,$max+1);
        if ($data===false || $data==='') throw new HttpError(413,t('The video piece arrived empty. Check post_max_size and upload_max_filesize in PHP.'));
        Studio\json(['received'=>$vault->appendUpload($upload,$offset,$data,$key)]);
    }
    if ($action==='finish') {
        $info=array_intersect_key($input,array_flip(['width','height','duration','fps','frames','audio','seed','took','gpu','planned_prompt']));
        Studio\json(['job'=>$jobs->finishVideo((string)($input['job']??''),(string)($input['upload']??''),$info,(string)($input['poster']??''),$key)]);
    }
    if ($action==='fail') Studio\json(['job'=>$jobs->failVideo((string)($input['job']??''),(string)($input['error']??''),($input['cancelled']??false)===true,$key)]);
    if ($action==='bye') { $gpu->bye((string)($input['reason']??'')); Studio\json(['ok'=>true]); }
    throw new HttpError(404,t('Route not found.'));
} catch (Throwable $e) {
    if (!$e instanceof HttpError) Studio\logFailure($e);
    Studio\json(['error'=>$e instanceof HttpError?$e->getMessage():t('[SERVER] Unexpected failure on the server. See the Atelier entry in PHP\'s error log.')], $e instanceof HttpError?$e->status:500);
}
