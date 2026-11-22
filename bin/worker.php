<?php
declare(strict_types=1);
// The separate process for long work (a turn of the story writer), started by Studio\Spawn. Command line only; the
// folder is closed to the web by its .htaccess.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
exit(Studio\Worker::main($argv, STDIN));
