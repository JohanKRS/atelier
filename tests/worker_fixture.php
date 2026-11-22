<?php
declare(strict_types=1);
// The separate process (bin/worker.php) with Claude and the image API played back, for tests/stories.php: the same
// Worker::main, started through Studio\Spawn with the key on a pipe. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
Studio\I18n::use('pt-BR'); // the tests read the Portuguese interface (assets/i18n/pt-BR.json)
$claude = function (string $url, array $headers, array $body, Closure $onEvent): array {
    $text = 'Escrito num processo separado (pid '.getmypid().').';
    foreach ([
        ['message_start', ['type'=>'message_start', 'message'=>['model'=>'claude-opus-5-5', 'content'=>[], 'usage'=>['input_tokens'=>100, 'output_tokens'=>0]]]],
        ['content_block_start', ['type'=>'content_block_start', 'index'=>0, 'content_block'=>['type'=>'text', 'text'=>'']]],
        ['content_block_delta', ['type'=>'content_block_delta', 'index'=>0, 'delta'=>['type'=>'text_delta', 'text'=>$text]]],
        ['content_block_stop', ['type'=>'content_block_stop', 'index'=>0]],
        ['message_delta', ['type'=>'message_delta', 'delta'=>['stop_reason'=>'end_turn'], 'usage'=>['output_tokens'=>12]]],
    ] as [$event, $data]) $onEvent($event, $data);
    return ['status'=>200, 'body'=>'', 'sent'=>true, 'errno'=>0, 'retry_after'=>null];
};
$image = imagecreatetruecolor(8, 8); ob_start(); imagepng($image); $png = (string)ob_get_clean();
$openai = new Studio\OpenAI('test', 60, fn(string $path, array $body) => ['data'=>[['b64_json'=>base64_encode($png)]], 'usage'=>['total_tokens'=>10]]);
exit(Studio\Worker::main($argv, STDIN, fn(Studio\Writer $writer) => $writer->withTransports($claude, null), $openai));
