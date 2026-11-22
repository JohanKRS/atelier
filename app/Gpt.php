<?php
declare(strict_types=1);
namespace Studio;

/**
 * GPT as a writer: the OpenAI Responses API, streamed, with function tools. Nothing is stored at OpenAI (store: false);
 * the reasoning comes back encrypted so the conversation, kept here, can be sent again whole.
 */
final class Gpt
{
    public const URL = 'https://api.openai.com/v1/responses';
    /** Prices per 1M tokens (input, cached input, output), US$, as in assets/pricing.js. */
    public const MODELS = [
        'gpt-6.2-sol'=>['label'=>'GPT-6.2 Sol', 'input'=>2.0, 'cached'=>0.10, 'output'=>10.0],
        'gpt-6.2-astra'=>['label'=>'GPT-6.2 Astra', 'input'=>8.0, 'cached'=>0.40, 'output'=>40.0],
    ];

    /** @param ?\Closure $transport same signature as Stream::post, for tests */
    public function __construct(private string $key, private int $timeout = 900, private ?\Closure $transport = null) {}

    /**
     * One streamed request. $onDelta receives ('text', chunk), ('update', chunk) for reasoning summaries, ('tool', name),
     * ('input', chunk) as a function call's arguments stream in and ('retry', why) before trying again after a failure.
     * @return array{model:string,output:list<mixed>,status:string,usage:array,incomplete:?string}
     *         output: the items as the API returned them (objects kept as objects, to be sent back unchanged)
     */
    public function send(array $body, \Closure $onDelta, \Closure $tick): array
    {
        $body['stream'] = true;
        for ($attempt = 0; ; $attempt++) {
            $items = []; $final = null; $streamError = null;
            $onEvent = function (string $event, array $data) use (&$items, &$final, &$streamError, $onDelta): void {
                $type = (string)($data['type'] ?? $event);
                if ($type === 'response.output_text.delta') $onDelta('text', (string)($data['delta'] ?? ''));
                elseif ($type === 'response.reasoning_summary_text.delta') $onDelta('update', (string)($data['delta'] ?? ''));
                elseif ($type === 'response.reasoning_summary_part.done') $onDelta('update', "\n");
                elseif ($type === 'response.output_item.added' && ($data['item']['type'] ?? '') === 'function_call') $onDelta('tool', (string)($data['item']['name'] ?? ''));
                elseif ($type === 'response.function_call_arguments.delta' && ($data['delta'] ?? '') !== '') $onDelta('input', (string)$data['delta']);
                elseif ($type === 'response.output_item.done' && is_array($data['item'] ?? null)) $items[(int)($data['output_index'] ?? count($items))] = $data['item'];
                elseif (in_array($type, ['response.completed', 'response.incomplete', 'response.failed'], true)) $final = is_array($data['response'] ?? null) ? $data['response'] : [];
                elseif ($type === 'error') $streamError = self::identifier($data['code'] ?? $data['error']['code'] ?? null) ?? 'error';
            };
            $result = ($this->transport ?? Stream::post(...))(self::URL, ['Authorization: Bearer '.$this->key], $body, $onEvent, $tick, $this->timeout);
            $status = (int)$result['status']; $errno = (int)$result['errno'];
            if ($errno !== 0) {
                error_log('Atelier: GPT writer cURL '.$errno.($result['sent'] ? ' after sending' : ' before sending'));
                if ((!$result['sent'] && $attempt < 3) || ($result['sent'] && $attempt < 1)) {
                    $this->wait($attempt, null, $tick, $onDelta, $result['sent'] ? t('The connection to OpenAI dropped in the middle of the answer.') : t('Could not connect to OpenAI (network error {errno}).', ['errno'=>$errno]));
                    continue;
                }
                if (!$result['sent']) throw new HttpError(502, t('Could not connect to OpenAI (network error {errno} on the server). Try again.', ['errno'=>$errno]));
                throw new HttpError(502, t('The connection to OpenAI dropped in the middle of the answer (error {errno}). Try again.', ['errno'=>$errno]));
            }
            if ($status === 400 && isset($body['prompt_cache_retention']) && str_contains((string)$result['body'], 'prompt_cache_retention')) {
                // A model without the 24-hour cache: the same request with the default one.
                unset($body['prompt_cache_retention']); $attempt--; continue;
            }
            if ($status >= 400) {
                $error = OpenAI::failure($status, (string)$result['body'], '/responses');
                $retry = $error->kind === null && ($status >= 500 || ($status === 429 && $error->getMessage() === OpenAI::rateLimitMessage()));
                if ($retry && $attempt < 3) { $this->wait($attempt, $result['retry_after'], $tick, $onDelta, $status === 429 ? t('OpenAI limited the requests per minute for this key.') : t('OpenAI failed (HTTP {status}).', ['status'=>$status])); continue; }
                throw $error;
            }
            if ($final === null) {
                if ($streamError !== null) error_log('Atelier: GPT writer stream error '.$streamError);
                if ($attempt < 1) { $this->wait($attempt, null, $tick, $onDelta, t('GPT\'s answer came incomplete.')); continue; }
                throw new HttpError(502, t('GPT\'s answer ended incomplete. Try again.'));
            }
            if (($final['status'] ?? '') === 'failed') {
                $code = self::identifier($final['error']['code'] ?? null);
                error_log('Atelier: GPT writer failed'.($code ? ' code='.$code : ''));
                if ($code !== null && preg_match('/insufficient_quota|billing/i', $code)) throw new HttpError(402, OpenAI::noCreditMessage(), 'credit');
                if ($code !== null && preg_match('/server_error|rate_limit/i', $code) && $attempt < 2) { $this->wait($attempt, null, $tick, $onDelta, t('GPT failed ({code}).', ['code'=>$code])); continue; }
                throw new HttpError(502, t('GPT could not finish the answer{detail}. Try again.', ['detail'=>($code ? ' ('.$code.')' : '')]));
            }
            ksort($items);
            // Kept as the API wrote them: objects stay objects when the conversation is sent again.
            $output = json_decode(json_encode(array_values($items), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), false, 512, JSON_THROW_ON_ERROR);
            return ['model'=>(string)($final['model'] ?? $body['model'] ?? ''), 'output'=>$output, 'status'=>(string)($final['status'] ?? 'completed'),
                'usage'=>is_array($final['usage'] ?? null) ? $final['usage'] : [], 'incomplete'=>self::identifier($final['incomplete_details']['reason'] ?? null)];
        }
    }

    /** Waits before trying again, as Claude::wait does. */
    private function wait(int $attempt, ?int $retryAfter, \Closure $tick, \Closure $onDelta, string $why): void
    {
        $seconds = (int)min(60, max(1, $retryAfter ?? [2, 6, 15, 30][min(3, $attempt)]));
        $onDelta('retry', t('{reason} Trying again in {seconds} s (attempt {attempt}).', ['reason'=>$why, 'seconds'=>$seconds, 'attempt'=>$attempt + 2]));
        if ($this->transport !== null) return;
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) { if ($tick() === true) throw new Cancelled(); usleep(250000); }
    }

    private static function identifier(mixed $value): ?string
    { return is_string($value) && preg_match('/\A[A-Za-z0-9_.\-]{1,80}\z/D', $value) ? $value : null; }

    public static function cost(string $model, array $usage): float
    {
        $r = Catalog::writer('openai', $model) ?? self::MODELS['gpt-6.2-sol'];
        $input = (int)($usage['input_tokens'] ?? 0); $cached = min($input, (int)($usage['input_tokens_details']['cached_tokens'] ?? 0));
        return (($input - $cached) * $r['input'] + $cached * $r['cached'] + (int)($usage['output_tokens'] ?? 0) * $r['output']) / 1e6;
    }
}
