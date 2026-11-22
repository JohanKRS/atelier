<?php
declare(strict_types=1);
namespace Studio;

/**
 * The Claude Messages API over plain HTTPS (no SDK: the app is deployed as a folder of files, with nothing to install).
 * One call is one streamed request; the agent loop that strings calls together lives in Writer.
 */
final class Claude
{
    public const URL = 'https://api.anthropic.com/v1/messages';
    public const VERSION = '2026-10-21';
    /**
     * Refused requests retried on another model by the API; progress notes between tool calls; thinking kept safe when
     * history changes; the reasoning level changed by a message in the conversation, which keeps the cache.
     */
    public const BETAS = 'server-side-fallback-2026-07-01,thinking-display-updates-2026-08-18,thinking-binding-controls-2026-08-01,mid-conversation-output-config-2026-07-01';
    /**
     * Prices per 1M tokens (input, cache write, cache read, output), US$, for the cost shown in the stories, and the
     * most a model writes in one answer (max_tokens), thinking included: at the highest effort the thinking alone can
     * take 64 000 tokens, so each answer gets the model's whole limit.
     */
    public const MODELS = [
        'claude-opus-5-5'=>['label'=>'Claude Opus 5.5', 'input'=>4.0, 'write'=>5.0, 'read'=>0.20, 'output'=>20.0, 'max_tokens'=>128000],
        'claude-sonnet-5-5'=>['label'=>'Claude Sonnet 5.5', 'input'=>2.0, 'write'=>2.5, 'read'=>0.10, 'output'=>10.0, 'max_tokens'=>128000],
        'claude-fable-5-2'=>['label'=>'Claude Fable 5.2', 'input'=>8.0, 'write'=>10.0, 'read'=>0.20, 'output'=>40.0, 'max_tokens'=>128000],
    ];
    public static function noCreditMessage(): string
    { return t('No credit at Anthropic: the account of this Claude key ran out of balance, and the request was refused. Top up at platform.claude.com, under Billing, and try again.'); }

    /** @param ?\Closure $transport same signature as Stream::post, for tests */
    public function __construct(private string $key, private int $timeout = 900, private ?\Closure $transport = null) {}

    /**
     * One streamed request. $onDelta receives what can be shown while it runs: ('text', chunk), ('update', chunk) for
     * the short progress notes the model writes between tool calls, ('tool', name) when a tool call starts, ('input',
     * chunk) as the tool call's input streams in, ('fallback', model) when the API moved the request to another model,
     * and ('retry', why) before trying again after a failure.
     * @return array{model:string,content:list<array>,stop_reason:?string,stop_details:mixed,usage:array,invalid:array<int,string>}
     *         invalid: the raw input of tool calls whose input is not a JSON object, by index in content
     */
    public function send(array $body, \Closure $onDelta, \Closure $tick): array
    {
        $body['stream'] = true;
        $headers = ['x-api-key: '.$this->key, 'anthropic-version: '.self::VERSION, 'anthropic-beta: '.self::BETAS];
        $plain = false;
        for ($attempt = 0; ; $attempt++) {
            $message = ['model'=>(string)($body['model'] ?? ''), 'content'=>[], 'stop_reason'=>null, 'stop_details'=>null, 'usage'=>[], 'invalid'=>[]];
            $partial = []; $streamError = null; $seen = false;
            $onEvent = function (string $event, array $data) use (&$message, &$partial, &$streamError, &$seen, $onDelta): void {
                $type = (string)($data['type'] ?? $event);
                if ($type === 'message_start') {
                    $m = is_array($data['message'] ?? null) ? $data['message'] : [];
                    if (is_string($m['model'] ?? null)) $message['model'] = $m['model'];
                    if (is_array($m['usage'] ?? null)) $message['usage'] = $m['usage'];
                } elseif ($type === 'content_block_start') {
                    $i = (int)($data['index'] ?? 0); $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : ['type'=>'unknown'];
                    $seen = true;
                    if (($block['type'] ?? '') === 'tool_use') { $partial[$i] = ''; $onDelta('tool', (string)($block['name'] ?? '')); }
                    if (($block['type'] ?? '') === 'fallback') $onDelta('fallback', (string)($block['to']['model'] ?? ''));
                    $message['content'][$i] = $block;
                } elseif ($type === 'content_block_delta') {
                    $i = (int)($data['index'] ?? 0); $d = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                    if (!isset($message['content'][$i])) return;
                    switch ($d['type'] ?? '') {
                        case 'text_delta': $message['content'][$i]['text'] = ($message['content'][$i]['text'] ?? '').(string)($d['text'] ?? ''); $onDelta('text', (string)($d['text'] ?? '')); break;
                        case 'thinking_delta': $message['content'][$i]['thinking'] = ($message['content'][$i]['thinking'] ?? '').(string)($d['thinking'] ?? ''); if (($d['thinking'] ?? '') !== '') $onDelta('update', (string)$d['thinking']); break;
                        case 'signature_delta': $message['content'][$i]['signature'] = ($message['content'][$i]['signature'] ?? '').(string)($d['signature'] ?? ''); break;
                        case 'input_json_delta': $partial[$i] = ($partial[$i] ?? '').(string)($d['partial_json'] ?? ''); if (($d['partial_json'] ?? '') !== '') $onDelta('input', (string)$d['partial_json']); break;
                    }
                } elseif ($type === 'content_block_stop') {
                    $i = (int)($data['index'] ?? 0);
                    if (!array_key_exists($i, $partial)) return;
                    $raw = trim($partial[$i]); unset($partial[$i]);
                    $input = $raw === '' ? new \stdClass() : json_decode($raw);
                    if (!$input instanceof \stdClass) { $message['invalid'][$i] = $raw; $input = new \stdClass(); }
                    $message['content'][$i]['input'] = $input;
                } elseif ($type === 'message_delta') {
                    $d = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                    if (array_key_exists('stop_reason', $d)) $message['stop_reason'] = $d['stop_reason'];
                    if (array_key_exists('stop_details', $d)) $message['stop_details'] = $d['stop_details'];
                    if (is_array($data['usage'] ?? null)) $message['usage'] = array_merge($message['usage'], $data['usage']);
                } elseif ($type === 'error') {
                    $streamError = self::identifier($data['error']['type'] ?? null) ?? 'error';
                }
            };
            $result = ($this->transport ?? Stream::post(...))(self::URL, $headers, $body, $onEvent, $tick, $this->timeout);
            // A tool call cut off by the end of the stream still needs an input object to be sent back.
            foreach ($partial as $i => $raw) { $message['invalid'][$i] = $raw; $message['content'][$i]['input'] = new \stdClass(); }
            ksort($message['content']); $message['content'] = array_values($message['content']);
            $status = (int)$result['status']; $errno = (int)$result['errno'];
            if ($errno !== 0) {
                error_log('Atelier: Claude cURL '.$errno.($result['sent'] ? ' after sending' : ' before sending'));
                // Nothing went out: always safe to try again. Cut off midway: once, since what streamed was paid.
                if ((!$result['sent'] && $attempt < 3) || ($result['sent'] && $attempt < 1)) {
                    $this->wait($attempt, null, $tick, $onDelta, $result['sent'] ? t('The connection to Anthropic dropped in the middle of the answer.') : t('Could not connect to Anthropic (network error {errno}).', ['errno'=>$errno]));
                    continue;
                }
                if (!$result['sent']) throw new HttpError(502, t('Could not connect to Anthropic (network error {errno} on the server). Try again.', ['errno'=>$errno]));
                if ($errno === 28) throw new HttpError(504, t('Claude did not finish answering in time. Try again; what was already saved stays in the story.'));
                throw new HttpError(502, t('The connection to Anthropic dropped in the middle of the answer (error {errno}). Try again.', ['errno'=>$errno]));
            }
            // A model that doesn't take the reasoning level in a message (a fallback model, say): once more with the
            // level at the top of the request, as before. The cache of the conversation starts over for this request.
            if ($status === 400 && !$plain && self::turnEffortRefused((string)$result['body']) && self::hasTurnEffort($body)) {
                error_log('Atelier: Claude refused the reasoning level in a message; sent at the top instead');
                $body = self::withoutTurnEffort($body); $plain = true; $attempt--; continue;
            }
            if ($status >= 400) {
                $error = self::failure($status, (string)$result['body']);
                if ($error->kind === 'retry' && $attempt < 3) { $this->wait($attempt, $result['retry_after'], $tick, $onDelta, self::reason($error)); continue; }
                throw $error;
            }
            if ($streamError !== null) {
                error_log('Atelier: Claude stream error '.$streamError);
                if (in_array($streamError, ['overloaded_error', 'api_error', 'rate_limit_error'], true) && $attempt < 2) {
                    $this->wait($attempt, null, $tick, $onDelta, $streamError === 'overloaded_error' ? t('Anthropic is overloaded.') : 'A Anthropic interrompeu a resposta ('.$streamError.').');
                    continue;
                }
                throw new HttpError(502, $streamError === 'overloaded_error' ? t('Anthropic is overloaded right now. Wait a little and try again.') : t('Anthropic interrupted the answer ({streamError}). Try again.', ['streamError'=>$streamError]));
            }
            if ($message['stop_reason'] === null) {
                if ($attempt < 1) { $this->wait($attempt, null, $tick, $onDelta, t('Claude\'s answer came incomplete.')); continue; }
                throw new HttpError(502, t('Claude\'s answer ended incomplete. Try again.'));
            }
            return $message;
        }
    }

    /**
     * The same request with max_tokens 0 and no stream: Claude reads the conversation (from the cache, which restarts
     * the cache's hour) and answers nothing. Returns the usage.
     */
    public function warm(array $body): array
    {
        unset($body['stream']); $body['max_tokens'] = 0;
        $headers = ['x-api-key: '.$this->key, 'anthropic-version: '.self::VERSION, 'anthropic-beta: '.self::BETAS];
        $result = ($this->transport ?? Stream::post(...))(self::URL, $headers, $body, static fn() => null, static fn() => false, $this->timeout);
        if ((int)$result['errno'] === 0 && (int)$result['status'] === 400 && self::turnEffortRefused((string)$result['body']) && self::hasTurnEffort($body))
            $result = ($this->transport ?? Stream::post(...))(self::URL, $headers, self::withoutTurnEffort($body), static fn() => null, static fn() => false, $this->timeout);
        if ((int)$result['errno'] !== 0) throw new HttpError(502, t('Could not reach Claude right now.'), 'retry');
        if ((int)$result['status'] >= 400) throw self::failure((int)$result['status'], (string)$result['body']);
        $json = json_decode((string)$result['body'], true);
        return is_array($json['usage'] ?? null) ? $json['usage'] : [];
    }

    /**
     * Waits before trying again: a few seconds, longer each time, or what the API asked for. The page is told why and for
     * how long. Cancellable.
     */
    private function wait(int $attempt, ?int $retryAfter, \Closure $tick, \Closure $onDelta, string $why): void
    {
        $seconds = (int)min(60, max(1, $retryAfter ?? [2, 6, 15, 30][min(3, $attempt)]));
        $onDelta('retry', t('{reason} Trying again in {seconds} s (attempt {attempt}).', ['reason'=>$why, 'seconds'=>$seconds, 'attempt'=>$attempt + 2]));
        if ($this->transport !== null) return;
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) { if ($tick() === true) throw new Cancelled(); usleep(250000); }
    }

    /** An error worth retrying, as the reason for the retry (without its advice to try again). */
    public static function reason(HttpError $e): string
    {
        $message = $e->getMessage();
        foreach ([t('Wait a little and try again.'), t('Try again.')] as $advice)
            if (str_ends_with($message, ' '.$advice)) return substr($message, 0, -strlen($advice) - 1);
        return $message;
    }

    /**
     * An error answer as a message for the person. Only the error type, its code and the place in the request it points
     * at are read: messages can quote the conversation, so they are neither shown nor logged. kind 'retry' marks the
     * ones worth trying again.
     */
    public static function failure(int $status, string $body): HttpError
    {
        $json = json_decode($body, true); $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $type = self::identifier($error['type'] ?? null); $code = self::identifier($error['details']['error_code'] ?? null);
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        // Where in the request a 400 points (messages.4.content.1, tools.3...): no content of the conversation.
        $where = preg_match('/\A((?:messages|tools|system|thinking|output_config|fallbacks)(?:\.[A-Za-z0-9_]+){0,4})\s*:/', $message, $m) ? $m[1] : null;
        error_log('Atelier: Claude HTTP '.$status.($type ? ' type='.$type : '').($code ? ' code='.$code : '').($where ? ' at='.$where : ''));
        if ($type === 'billing_error' || ($status === 400 && stripos($message, 'credit balance') !== false)) return new HttpError(402, self::noCreditMessage(), 'claude-credit');
        if ($code === 'enforced_spend_limit_reached') return new HttpError(402, t('The Anthropic account reached its monthly spending limit. Raise the limit at platform.claude.com (Limits) or wait for next month.'), 'claude-credit');
        if ($status === 401) return new HttpError(502, t('The Claude key was not accepted. Check it in Settings.'));
        if ($status === 403) return new HttpError(502, t('This Claude key has no access to this model or feature.'));
        if ($status === 404) return new HttpError(502, t('The chosen model is not available for this Claude key. Choose another.'));
        if ($status === 413 || ($status === 400 && preg_match('/prompt is too long|context window|too many tokens/i', $message))) return new HttpError(413, t('The conversation grew too large for the model. Start a new conversation: the agent still knows the story (chapters, bible and planning).'), 'too-long');
        if ($status === 429) return new HttpError(429, t('Anthropic limited the number of requests per minute for this key. Wait a little and try again.'), 'retry');
        if ($status === 529 || $type === 'overloaded_error') return new HttpError(503, t('Anthropic is overloaded right now. Wait a little and try again.'), 'retry');
        if ($status >= 500) return new HttpError(502, t('Anthropic could not complete the request (HTTP {status}). Try again.', ['status'=>$status]), 'retry');
        $vars = ['status'=>$status, 'type'=>$type ? ', '.$type : '', 'where'=>(string)$where];
        return new HttpError(502, $where ? t('Anthropic refused the request (HTTP {status}{type} at {where}).', $vars) : t('Anthropic refused the request (HTTP {status}{type}).', $vars));
    }

    /** A 400 about the reasoning level in a message of the conversation (only matched, never logged: it may quote it). */
    private static function turnEffortRefused(string $body): bool
    {
        $json = json_decode($body, true); $message = is_string($json['error']['message'] ?? null) ? $json['error']['message'] : '';
        return (bool)preg_match('/output_config|per-turn effort/i', $message);
    }

    private static function hasTurnEffort(array $body): bool
    {
        foreach ($body['messages'] ?? [] as $m) if (self::turnEffort($m) !== false) return true;
        return false;
    }

    /** The level a message of the app sets, null when it sets none, false when it isn't one. */
    private static function turnEffort(mixed $m): string|null|false
    {
        $m = is_object($m) ? (array)$m : (is_array($m) ? $m : []);
        if (($m['role'] ?? '') !== 'system' || !isset($m['output_config'])) return false;
        $config = is_object($m['output_config']) ? (array)$m['output_config'] : (array)$m['output_config'];
        return is_string($config['effort'] ?? null) ? $config['effort'] : null;
    }

    /** The request with the messages that set the reasoning level taken out, and the last level they set at the top. */
    private static function withoutTurnEffort(array $body): array
    {
        $messages = [];
        foreach ($body['messages'] ?? [] as $m) {
            $effort = self::turnEffort($m);
            if ($effort === false) { $messages[] = $m; continue; }
            if ($effort !== null) $body['output_config']['effort'] = $effort;
        }
        $body['messages'] = $messages;
        return $body;
    }

    /** Provider identifiers are safe to log and show; anything else is dropped. */
    private static function identifier(mixed $value): ?string
    { return is_string($value) && preg_match('/\A[A-Za-z0-9_.\-]{1,80}\z/D', $value) ? $value : null; }

    /** What a request cost, US$, from its usage (top-level usage covers the attempt that answered; iterations, every attempt). */
    public static function cost(string $model, array $usage): float
    {
        $parts = is_array($usage['iterations'] ?? null) && $usage['iterations'] ? $usage['iterations'] : [$usage + ['model'=>$model]];
        $total = 0.0;
        foreach ($parts as $u) {
            if (!is_array($u)) continue;
            $r = Catalog::writer('claude', (string)($u['model'] ?? $model)) ?? Catalog::writer('claude', $model) ?? self::MODELS['claude-opus-5-5'];
            // Cache writes: 1.25x the input price for five minutes, 2x for an hour (usage.cache_creation splits them).
            $written = (int)($u['cache_creation_input_tokens'] ?? 0);
            $hour = is_array($u['cache_creation'] ?? null) ? min($written, (int)($u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0)) : 0;
            $total += ((int)($u['input_tokens'] ?? 0) * $r['input'] + ($written - $hour) * $r['write'] + $hour * $r['input'] * 2
                + (int)($u['cache_read_input_tokens'] ?? 0) * $r['read'] + (int)($u['output_tokens'] ?? 0) * $r['output']) / 1e6;
        }
        return $total;
    }
}
