<?php
declare(strict_types=1);
namespace Studio;

/**
 * A JSON POST whose answer is a stream of server-sent events, for the writing agents (Claude and GPT). Both clients
 * take a transport with the signature of post(), so the tests can play a stream back without the network.
 */
final class Stream
{
    /**
     * @param \Closure $onEvent receives (string $event, array $data) for each event whose data is a JSON object
     * @param \Closure $tick    called about once per second while the request is open; returning true cancels it
     * @return array{status:int,body:string,sent:bool,errno:int,retry_after:?int} body is the raw answer of a failure
     *         (status >= 400), capped, or a JSON answer (a request without stream); the events of a stream are only
     *         passed to $onEvent
     */
    public static function post(string $url, array $headers, array $body, \Closure $onEvent, \Closure $tick, int $timeout): array
    {
        $curl = curl_init($url);
        $raw = ''; $buffer = ''; $retryAfter = null; $max = 64 * 1024 * 1024; $json = null;
        $consume = static function (string $block) use ($onEvent): void {
            $event = ''; $data = '';
            foreach (preg_split('/\r?\n/', $block) as $line) {
                if (str_starts_with($line, 'event:')) $event = trim(substr($line, 6));
                elseif (str_starts_with($line, 'data:')) $data .= ltrim(substr($line, 5));
            }
            if ($data === '' || $data === '[DONE]') return;
            $json = json_decode($data, true);
            if (is_array($json)) $onEvent($event !== '' ? $event : (string)($json['type'] ?? ''), $json);
        };
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json', empty($body['stream']) ? 'Accept: application/json' : 'Accept: text/event-stream'], $headers),
            CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/\Aretry-after:\s*([0-9]{1,5})\s*\z/i', trim($line), $m)) $retryAfter = (int)$m[1];
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$raw, &$buffer, &$json, $max, $consume): int {
                if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 400) {
                    if (strlen($raw) < 65536) $raw .= substr($chunk, 0, 65536 - strlen($raw));
                    return strlen($chunk);
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $max) return 0;
                // An answer that is one JSON object, not events, is kept whole.
                $json ??= ltrim($buffer) === '' ? null : str_starts_with(ltrim($buffer), '{');
                if ($json === true) return strlen($chunk);
                while (preg_match('/\r?\n\r?\n/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                    $consume(substr($buffer, 0, $m[0][1])); $buffer = substr($buffer, $m[0][1] + strlen($m[0][0]));
                }
                return strlen($chunk);
            },
            CURLOPT_NOPROGRESS=>false, CURLOPT_XFERINFOFUNCTION=>static fn() => $tick() === true ? 1 : 0]);
        // A stream may go on for as long as the model writes (a long answer at the highest effort passes 15 minutes):
        // it is given up only after $timeout seconds with nothing arriving, or after three hours. A plain answer has
        // $timeout in all.
        if (!empty($body['stream'])) curl_setopt_array($curl, [CURLOPT_TIMEOUT=>3 * 3600, CURLOPT_LOW_SPEED_LIMIT=>1, CURLOPT_LOW_SPEED_TIME=>max(60, $timeout)]);
        else curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $errno = curl_errno($curl);
        $sent = (float)curl_getinfo($curl, CURLINFO_PRETRANSFER_TIME) > 0; curl_close($curl);
        if ($errno === CURLE_ABORTED_BY_CALLBACK) throw new Cancelled();
        if ($ok !== false && $errno === 0 && $status < 400 && trim($buffer) !== '' && $json !== true) $consume($buffer);
        return ['status'=>$status, 'body'=>$json === true && $status < 400 ? $buffer : $raw, 'sent'=>$sent, 'errno'=>$ok === false ? max(1, $errno) : $errno, 'retry_after'=>$retryAfter];
    }
}
