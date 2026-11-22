<?php
declare(strict_types=1);
// Copy to config.php. Never commit that file.
return [
    // Create a long random installation code; used only for the first account.
    // Example generator: php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
    'setup_token' => '',
    // Absolute path OUTSIDE the Apache DocumentRoot. Empty = automatic sibling folder.
    'data_dir' => '',
    // Leave true in production. Set false ONLY for local development over HTTP.
    'require_https' => true,
    // Set true only when your trusted reverse proxy removes/spoofs no client headers
    // and connects exclusively to this origin. It enables X-Forwarded-Proto=https.
    'trusted_proxy' => false,
    'session_minutes' => 60,
    'api_timeout' => 600,
    // Generations that may run at the same time. Each one keeps a PHP worker busy while it waits
    // for OpenAI, so stay below your host's PHP-FPM/LSAPI process limit. Extra requests wait in line.
    'max_parallel' => 4,
    // Largest video accepted, in MB (0 turns video uploads off). Videos arrive in pieces of up to 8 MB, so PHP's
    // upload_max_filesize and post_max_size don't need to be raised; mind the disk space of your plan instead.
    'max_video_mb' => 2048,
    // On PHP-FPM and LiteSpeed the browser gets its answer as soon as a generation starts, and the generation goes on
    // in the background, so no CDN or proxy can cut it however long it takes. Set false to keep the old way: one long
    // request per generation, kept open with keepalive bytes.
    'run_in_background' => true,
    // The story writer's turns run in a separate command line PHP process when the server allows it (proc_open and
    // the php binary), because the web server may stop its own PHP processes after a couple of minutes. Set false to
    // run them in the web server, as before. Configurações › Teste da hospedagem shows what this server does.
    'separate_process' => true,
];
