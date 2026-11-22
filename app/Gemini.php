<?php
declare(strict_types=1);
namespace Studio;

/**
 * Google's Gemini over plain HTTPS (no SDK), through the Interactions API: the writer of the stories here, and the
 * requests of GeminiImages (pictures and the planning of their prompts).
 *
 * Nothing is kept at Google (store: false): the whole conversation is sent each time, as with Claude and GPT, and the
 * prefix that repeats is read from Google's implicit cache, at a tenth of the input price or less, with nothing to set
 * up or pay to keep. One call is one streamed request; the agent loop lives in Writer.
 *
 * The Interactions API speaks in steps: what the person sends is a user_input step, and an answer is a list of steps
 * (thought, with the signature that has to go back unchanged; model_output, with the text; function_call). The results
 * of the tools go back as function_result steps. An answer is kept as it came and sent again as it is.
 */
final class Gemini
{
    public const URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    public const MODELS_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    /** When the prices below were taken from Google's page (ai.google.dev/gemini-api/docs/pricing, Standard tier). */
    public const PRICING_CHECKED = '2026-11-20';
    /**
     * The writers, the first one the default. Prices per 1M tokens, US$: input, input read from the cache, output
     * (thinking included), each until a date (midnight in California, as Google's prices change) and after it. Gemini 4
     * Argon doubles on 1 December 2026, Gemini 3.8 Flash on 1 January 2027; Gemini 4 Neon (launched 18 November 2026,
     * price from Google's announcement) has no date. max_tokens: the most one streamed answer may write, thinking
     * included. efforts: the thinking levels the model takes (Neon goes to xhigh and refuses minimal).
     */
    public const MODELS = [
        'gemini-4-neon'=>['label'=>'Gemini 4 Neon', 'max_tokens'=>65536, 'efforts'=>['low', 'medium', 'high', 'xhigh'], 'prices'=>[
            ['until'=>null, 'input'=>3.0, 'cached'=>0.15, 'output'=>15.0]]],
        'gemini-4-argon'=>['label'=>'Gemini 4 Argon', 'max_tokens'=>65536, 'efforts'=>['low', 'medium', 'high'], 'prices'=>[
            ['until'=>'2026-12-01T08:00:00Z', 'input'=>2.0, 'cached'=>0.10, 'output'=>10.0],
            ['until'=>null, 'input'=>4.0, 'cached'=>0.20, 'output'=>20.0]]],
        'gemini-3.8-flash'=>['label'=>'Gemini 3.8 Flash', 'max_tokens'=>65536, 'efforts'=>['low', 'medium', 'high'], 'prices'=>[
            ['until'=>'2027-01-01T08:00:00Z', 'input'=>0.75, 'cached'=>0.075, 'output'=>3.75],
            ['until'=>null, 'input'=>1.5, 'cached'=>0.15, 'output'=>7.5]]],
    ];
    /** The reasoning levels a Gemini model takes when the table doesn't say. */
    public const EFFORTS = ['low', 'medium', 'high'];
    public static function noCreditMessage(): string
    { return t('No credit at Gemini: the account of this Google key ran out of balance (or reached its spending or quota limit), and the request was refused. Check at aistudio.google.com, under Billing and Usage, and try again.'); }
    public static function keyMessage(): string
    { return t('Google did not accept the Gemini key. Check it in Settings (create one at aistudio.google.com, under API keys).'); }
    /** Errors of a generation the model blocked: Google's own filters, which the app doesn't loosen past their default. */
    private const BLOCKED = ['safety', 'recitation', 'language', 'prohibited_content', 'spii', 'blocklist', 'image_safety', 'image_prohibited_content', 'image_recitation', 'image_other', 'content_blocked', 'jailbreak', 'no_image'];

    /**
     * Google's adjustable filters, all off (the lowest level the API has): harassment, hate, sexual content and dangerous
     * content. What Google always blocks (child safety, some personal data, its prohibited content) and the image
     * models' own filters on the pictures they make have no setting; when one of them blocks, the message says which.
     */
    public const SAFETY_CATEGORIES = ['HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH', 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT'];
    public static function safety(): array
    { return array_map(fn(string $c) => ['category'=>$c, 'threshold'=>'OFF'], self::SAFETY_CATEGORIES); }

    /** Optional fields the API refused in this process: left out of the next requests instead of being refused again. */
    private static array $dropped = [];
    public static function resetDropped(): void { self::$dropped = []; }
    private static function withoutDropped(array $body): array
    {
        foreach (self::$dropped as $field => $_) $body = self::without($body, $field) ?? $body;
        return $body;
    }

    /** @param ?\Closure $transport same signature as Stream::post, for tests */
    public function __construct(private string $key, private int $timeout = 900, private ?\Closure $transport = null) {}

    public static function headers(string $key): array { return ['x-goog-api-key: '.$key]; }

    /** The reasoning levels of a model (the image models and the planner: the default ones). */
    public static function efforts(?string $model = null): array
    { return self::MODELS[$model]['efforts'] ?? ($model === null ? null : Catalog::writer('gemini', $model)['efforts'] ?? null) ?? self::EFFORTS; }

    /** The app's reasoning level as the model's thinking_level: below its lowest, its lowest; above its highest, its highest. */
    public static function thinking(string $effort, ?string $model = null): string
    {
        $levels = self::efforts($model);
        if (in_array($effort, $levels, true)) return $effort;
        return $effort === 'none' || $effort === 'minimal' ? $levels[0] : $levels[count($levels) - 1];
    }

    /**
     * One streamed request. $onDelta receives what can be shown while it runs: ('text', chunk), ('update', chunk) for the
     * summaries of its thinking, ('tool', name) when a function call starts, ('input', chunk) as its arguments stream in,
     * and ('retry', why) before trying again after a failure.
     * @return array{model:string,steps:list<object>,status:string,usage:array,invalid:array<int,string>,code:?string}
     *         steps: the answer's steps, as objects (to be sent back as they came); invalid: the raw arguments of function
     *         calls that are not a JSON object, by index in steps; code: why a generation failed that the writer can go
     *         on from (a malformed call, say)
     */
    public function send(array $body, \Closure $onDelta, \Closure $tick): array
    {
        $body['stream'] = true; $body = self::withoutDropped($body);
        for ($attempt = 0; ; $attempt++) {
            $steps = []; $partial = []; $final = null; $streamError = null; $model = (string)($body['model'] ?? '');
            $onEvent = function (string $event, array $data) use (&$steps, &$partial, &$final, &$streamError, &$model, $onDelta): void {
                $type = (string)($data['event_type'] ?? $event);
                if ($type === 'interaction.created' || $type === 'interaction.status_update') {
                    if (is_string($data['interaction']['model'] ?? null)) $model = $data['interaction']['model'];
                } elseif ($type === 'step.start') {
                    $i = (int)($data['index'] ?? count($steps)); $step = is_array($data['step'] ?? null) ? $data['step'] : ['type'=>'unknown'];
                    if (($step['type'] ?? '') === 'function_call') {
                        // The arguments stream in as text; what came with the start is kept if nothing follows.
                        $partial[$i] = '';
                        $onDelta('tool', (string)($step['name'] ?? ''));
                    }
                    if (($step['type'] ?? '') === 'model_output' && !is_array($step['content'] ?? null)) $step['content'] = [];
                    $steps[$i] = $step;
                } elseif ($type === 'step.delta') {
                    $i = (int)($data['index'] ?? 0); $d = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                    if (!isset($steps[$i])) $steps[$i] = ['type'=>($d['type'] ?? '') === 'arguments_delta' ? 'function_call' : (in_array($d['type'] ?? '', ['thought_summary', 'thought_signature'], true) ? 'thought' : 'model_output')];
                    switch ($d['type'] ?? '') {
                        case 'text':
                            $chunk = (string)($d['text'] ?? '');
                            $content = is_array($steps[$i]['content'] ?? null) ? $steps[$i]['content'] : [];
                            $last = count($content) - 1;
                            if ($last >= 0 && ($content[$last]['type'] ?? '') === 'text') $content[$last]['text'] = (string)($content[$last]['text'] ?? '').$chunk;
                            else $content[] = ['type'=>'text', 'text'=>$chunk];
                            $steps[$i]['content'] = $content;
                            if ($chunk !== '') $onDelta('text', $chunk);
                            break;
                        case 'thought_summary':
                            $chunk = (string)($d['content']['text'] ?? $d['text'] ?? '');
                            $summary = is_array($steps[$i]['summary'] ?? null) ? $steps[$i]['summary'] : [];
                            if (!$summary) $summary[] = ['type'=>'text', 'text'=>''];
                            $summary[count($summary) - 1]['text'] = (string)($summary[count($summary) - 1]['text'] ?? '').$chunk;
                            $steps[$i]['summary'] = $summary;
                            if ($chunk !== '') $onDelta('update', $chunk);
                            break;
                        case 'thought_signature':
                            $steps[$i]['signature'] = (string)($steps[$i]['signature'] ?? '').(string)($d['signature'] ?? '');
                            break;
                        case 'arguments_delta':
                            $chunk = is_string($d['arguments'] ?? null) ? $d['arguments'] : (is_array($d['arguments'] ?? null) ? json_encode($d['arguments']) : '');
                            $partial[$i] = ($partial[$i] ?? '').$chunk;
                            if ($chunk !== '') $onDelta('input', $chunk);
                            break;
                        case 'image':
                            $steps[$i]['content'][] = ['type'=>'image', 'mime_type'=>(string)($d['mime_type'] ?? 'image/png'), 'data'=>(string)($d['data'] ?? '')];
                            break;
                    }
                } elseif ($type === 'step.stop') {
                    $i = (int)($data['index'] ?? 0);
                    if (array_key_exists($i, $partial)) $this->closeCall($steps, $partial, $i);
                } elseif (in_array($type, ['interaction.completed', 'interaction.complete', 'interaction.failed', 'interaction.incomplete'], true)) {
                    $final = is_array($data['interaction'] ?? null) ? $data['interaction'] : $data;
                } elseif ($type === 'error') {
                    $streamError = self::identifier($data['error']['code'] ?? $data['code'] ?? null) ?? 'error';
                }
            };
            $result = ($this->transport ?? Stream::post(...))(self::URL, self::headers($this->key), $body, $onEvent, $tick, $this->timeout);
            $status = (int)$result['status']; $errno = (int)$result['errno'];
            // A function call cut off by the end of the stream still needs arguments to be sent back.
            foreach (array_keys($partial) as $i) $this->closeCall($steps, $partial, $i);
            if ($errno !== 0) {
                error_log('Atelier: Gemini cURL '.$errno.($result['sent'] ? ' after sending' : ' before sending'));
                if ((!$result['sent'] && $attempt < 3) || ($result['sent'] && $attempt < 1)) {
                    $this->wait($attempt, null, $tick, $onDelta, $result['sent'] ? t('The connection to Google dropped in the middle of the answer.') : t('Could not connect to Google (network error {errno}).', ['errno'=>$errno]));
                    continue;
                }
                if (!$result['sent']) throw new HttpError(502, t('Could not connect to Google (network error {errno} on the server). Try again.', ['errno'=>$errno]));
                if ($errno === 28) throw new HttpError(504, t('Gemini did not finish answering in time. Try again; what was already saved stays in the story.'));
                throw new HttpError(502, t('The connection to Google dropped in the middle of the answer (error {errno}). Try again.', ['errno'=>$errno]));
            }
            if ($status >= 400) {
                // A field this model or this version of the API doesn't take: once more without it.
                $field = self::rejectedField((string)$result['body']);
                if ($status === 400 && $field !== null && ($trimmed = self::without($body, $field)) !== null) {
                    error_log('Atelier: Gemini refused the field '.$field.'; sent again without it');
                    self::$dropped[$field] = true; $body = $trimmed; $attempt--; continue;
                }
                $error = self::failure($status, (string)$result['body']);
                if ($error->kind === 'retry' && $attempt < 3) { $this->wait($attempt, $result['retry_after'], $tick, $onDelta, self::reason($error)); continue; }
                throw $error;
            }
            if ($streamError !== null && $final === null) {
                error_log('Atelier: Gemini stream error '.$streamError);
                if (in_array($streamError, ['api_error', 'service_unavailable', 'gateway_timeout', 'deadline_exceeded', 'rate_limit_exceeded', 'too_many_requests', 'error'], true) && $attempt < 2) {
                    $this->wait($attempt, null, $tick, $onDelta, 'O Google interrompeu a resposta ('.$streamError.').');
                    continue;
                }
                throw self::codeError($streamError);
            }
            // An answer that came as one JSON object instead of events.
            if ($final === null && $steps === [] && str_starts_with(ltrim((string)$result['body']), '{')) {
                $final = json_decode((string)$result['body'], true) ?: null;
            }
            if ($final === null) {
                if ($attempt < 1) { $this->wait($attempt, null, $tick, $onDelta, t('Gemini\'s answer came incomplete.')); continue; }
                throw new HttpError(502, t('Gemini\'s answer ended incomplete. Try again.'));
            }
            if (!$steps && is_array($final['steps'] ?? null)) $steps = array_values(array_filter($final['steps'], 'is_array'));
            ksort($steps);
            $list = []; $invalid = [];
            foreach ($steps as $s) {
                if (isset($s['_invalid'])) { $invalid[count($list)] = (string)$s['_invalid']; unset($s['_invalid']); }
                elseif (($s['type'] ?? '') === 'function_call' && isset($s['arguments']) && !is_array($s['arguments'])) { $invalid[count($list)] = (string)$s['arguments']; $s['arguments'] = []; }
                $list[] = $s;
            }
            $state = (string)($final['status'] ?? 'completed');
            $code = null;
            if ($state === 'failed' || $state === 'cancelled') {
                $code = self::identifier($final['errors'][0]['code'] ?? $final['error']['code'] ?? null);
                $code = $code !== null ? strtolower(preg_replace('/^.*\//', '', $code)) : null;
                error_log('Atelier: Gemini interaction '.$state.($code ? ' code='.$code : ''));
                // The model wrote a call it couldn't finish: the writer is told and goes on.
                if (!in_array($code, ['malformed_function_call', 'malformed_tool_call', 'unexpected_tool_call', 'too_many_tool_calls'], true)) {
                    if (in_array($code, ['api_error', 'service_unavailable', 'gateway_timeout', 'deadline_exceeded'], true) && $attempt < 2) { $this->wait($attempt, null, $tick, $onDelta, t('Gemini failed ({code}).', ['code'=>$code])); continue; }
                    throw $code !== null ? self::codeError($code) : new HttpError(502, t('Gemini could not finish the answer. Try again.'));
                }
            }
            return ['model'=>is_string($final['model'] ?? null) ? $final['model'] : $model, 'steps'=>self::asObjects($list), 'status'=>$state,
                'usage'=>is_array($final['usage'] ?? null) ? $final['usage'] : [], 'invalid'=>$invalid, 'code'=>$code];
        }
    }

    /** A function call whose arguments finished streaming: parsed, or kept raw (as _invalid) when they are not an object. */
    private function closeCall(array &$steps, array &$partial, int $i): void
    {
        $raw = trim($partial[$i]); unset($partial[$i]);
        if (!isset($steps[$i])) return;
        if ($raw === '') { if (!is_array($steps[$i]['arguments'] ?? null)) $steps[$i]['arguments'] = []; return; }
        $args = json_decode($raw, true);
        if (is_array($args) && ($args === [] || !array_is_list($args))) { $steps[$i]['arguments'] = $args; return; }
        $steps[$i]['arguments'] = []; $steps[$i]['_invalid'] = $raw;
    }

    /**
     * Steps as objects, as they go back to the API: objects stay objects (a call's empty arguments are {}, not []).
     * @return list<object>
     */
    public static function asObjects(array $steps): array
    {
        $out = json_decode(json_encode($steps, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE), false, 512, JSON_THROW_ON_ERROR);
        foreach ($out as $s) if (is_object($s) && ($s->type ?? '') === 'function_call' && !is_object($s->arguments ?? null)) $s->arguments = new \stdClass();
        return $out;
    }

    /**
     * One request without a stream (a picture, the planning of a prompt, the test in Configurações), answered as one JSON
     * object. $monitor is called about once per second while it waits and returns true to cancel. Fields this model
     * doesn't take are dropped and the request sent again, once each.
     */
    public static function call(string $key, array $body, int $timeout, ?\Closure $transport = null, ?\Closure $monitor = null): array
    {
        $tick = $monitor ?? static fn() => false; $body = self::withoutDropped($body);
        for ($attempt = 0; ; $attempt++) {
            $result = ($transport ?? Stream::post(...))(self::URL, self::headers($key), $body, static function () {}, $tick, $timeout);
            $status = (int)$result['status']; $errno = (int)$result['errno'];
            if ($errno !== 0) {
                error_log('Atelier: Gemini cURL '.$errno.($result['sent'] ? ' after sending' : ' before sending'));
                if (!$result['sent']) {
                    if ($attempt < 1) { usleep(1500000); continue; }
                    throw new HttpError(502, t('Could not connect to Google (network error {errno} on the server). The request was not sent; it is safe to try again.', ['errno'=>$errno]));
                }
                if ($errno === 28) throw new HttpError(504, t('Gemini did not answer within {minutes} min. The request may have been processed and charged; check before trying again.', ['minutes'=>intdiv($timeout, 60)]));
                throw new HttpError(502, t('The connection to Google was interrupted (error {errno}). The request may have been processed and charged; check before trying again.', ['errno'=>$errno]));
            }
            if ($status >= 400) {
                $field = self::rejectedField((string)$result['body']);
                if ($status === 400 && $field !== null && $attempt < 4 && ($trimmed = self::without($body, $field)) !== null) {
                    error_log('Atelier: Gemini refused the field '.$field.'; sent again without it');
                    self::$dropped[$field] = true; $body = $trimmed; continue;
                }
                $error = self::failure($status, (string)$result['body']);
                // Busy for a moment: once more after a pause.
                if ($error->kind === 'retry' && $attempt < 2 && $status !== 429) {
                    $until = microtime(true) + [3, 8][$attempt];
                    while (microtime(true) < $until) { if ($tick() === true) throw new Cancelled(); usleep(250000); }
                    continue;
                }
                throw $error;
            }
            $json = json_decode((string)$result['body'], true);
            if (is_array($json) && array_is_list($json) && is_array($json[0] ?? null)) $json = $json[0];
            if (!is_array($json)) throw new HttpError(502, t('Invalid response from Gemini.'));
            return $json;
        }
    }

    /**
     * A field the API said it doesn't know or doesn't take with this model, among the optional ones the app sends (the
     * keys of the error message are read, never the rest of it, which can quote the request).
     */
    private const OPTIONAL = ['store', 'thinking_summaries', 'thinking_level', 'safety_settings', 'system_instruction', 'image_size', 'mime_type', 'max_output_tokens'];
    private static function rejectedField(string $body): ?string
    {
        $message = self::errorMessage($body);
        if ($message === '' || !preg_match('/unknown|not supported|unsupported|invalid|cannot find field|not allowed|unrecognized/i', $message)) return null;
        foreach (self::OPTIONAL as $field) if (preg_match('/(?<![A-Za-z_])'.preg_quote($field, '/').'(?![A-Za-z_])/i', $message)) return $field;
        return null;
    }

    /** The request without an optional field, wherever it is (top level, generation_config, response_format), or null. */
    private static function without(array $body, string $field): ?array
    {
        $found = false;
        if (array_key_exists($field, $body) && $field !== 'system_instruction') { unset($body[$field]); $found = true; }
        foreach (['generation_config', 'response_format'] as $group) if (is_array($body[$group] ?? null) && array_key_exists($field, $body[$group])) {
            unset($body[$group][$field]); $found = true;
            if ($body[$group] === []) unset($body[$group]);
        }
        // The instructions, if this API wants them elsewhere: as the first thing the person says.
        if ($field === 'system_instruction' && is_string($body['system_instruction'] ?? null)) {
            $text = $body['system_instruction']; unset($body['system_instruction']);
            if (is_array($body['input'] ?? null)) array_unshift($body['input'], ['type'=>'user_input', 'content'=>[['type'=>'text', 'text'=>$text]]]);
            $found = true;
        }
        return $found ? $body : null;
    }

    private static function errorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json) && array_is_list($json)) $json = $json[0] ?? [];
        $m = $json['error']['message'] ?? $json['message'] ?? '';
        return is_string($m) ? $m : '';
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

    public static function reason(HttpError $e): string { return Claude::reason($e); }

    /**
     * An error answer as a message for the person. Google answers in two shapes: the Interactions API's own
     * ({"error":{"code":"rate_limit_exceeded"}}) and Google's usual one ({"error":{"code":429,"status":"RESOURCE_EXHAUSTED",
     * "details":[{"reason":"…"}]}}), sometimes in a list. Only codes, statuses, reasons and the names of fields are read
     * and shown: the message itself can quote the request.
     */
    public static function failure(int $status, string $body): HttpError
    {
        $json = json_decode($body, true);
        if (is_array($json) && array_is_list($json)) $json = $json[0] ?? [];
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = is_string($error['code'] ?? null) ? self::identifier(strtolower(preg_replace('/^.*\//', '', $error['code']))) : null;
        $state = self::identifier($error['status'] ?? null);
        $reason = null;
        // The quotas a limit hit (QuotaFailure): per minute is waited out, per day or of the free tier is a limit of the account.
        $quota = [];
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $d) {
            if (!is_array($d)) continue;
            if ($reason === null && ($r = self::identifier($d['reason'] ?? null)) !== null) $reason = $r;
            foreach (is_array($d['violations'] ?? null) ? $d['violations'] : [] as $v) if (is_array($v)) foreach (['quotaId', 'quotaMetric'] as $k) if (($q = self::identifier($v[$k] ?? null)) !== null) $quota[] = $q;
        }
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        // The names of fields in a message about the request (Unknown name "x", at 'generation_config.y'): no content of it.
        $fields = [];
        if (preg_match_all('/(?:Unknown name|field|parameter)\s+["\'“]?([A-Za-z_][A-Za-z0-9_.\[\]]{0,60})["\'”]?|at\s+\'([A-Za-z_][A-Za-z0-9_.\[\]]{0,80})\'/i', $message, $m, PREG_SET_ORDER))
            foreach ($m as $f) { $name = $f[1] !== '' ? $f[1] : ($f[2] ?? ''); if ($name !== '' && !in_array(strtolower($name), ['the', 'a', 'is', 'of'], true)) $fields[$name] = true; }
        $fields = array_slice(array_keys($fields), 0, 3);
        error_log('Atelier: Gemini HTTP '.$status.($code ? ' code='.$code : '').($state ? ' status='.$state : '').($reason ? ' reason='.$reason : '').($quota ? ' quota='.implode(',', $quota) : '').($fields ? ' fields='.implode(',', $fields) : ''));
        $limits = implode(' ', $quota);
        $perMinute = (bool)preg_match('/per_?minute|perminute/i', $limits);
        $accountLimit = (bool)preg_match('/per_?day|perday|free_?tier|freetier/i', $limits)
            // Read, never shown: Google's text for a key with no credit or past its spending limit.
            || (bool)preg_match('/billing|prepa(y|id)|credit|spend(ing)? (cap|limit)|check your plan/i', $message);
        $all = strtolower(implode(' ', array_filter([$code, $state, $reason])));
        if ($reason === 'API_KEY_INVALID' || $status === 401 || $code === 'authentication' || str_contains($all, 'api_key')) return new HttpError(502, self::keyMessage());
        if ($status === 402 || $code === 'payment_required' || preg_match('/billing|prepay|credit/i', $all) || $code === 'quota_exceeded'
            || ($status === 429 && !$perMinute && ($accountLimit || (preg_match('/quota/i', $all) && !preg_match('/per_?minute|rate/i', $all)))))
            return new HttpError(402, self::noCreditMessage(), 'gemini-credit');
        if ($status === 429) return new HttpError(429, t('Google limited the number of requests per minute for this Gemini key. Wait a little and try again.'), 'retry');
        if ($code !== null && in_array($code, self::BLOCKED, true)) return self::codeError($code);
        if ($status === 403 || $code === 'permission_denied') return new HttpError(502, t('This Gemini key has no access to this model or feature (check that billing is active in the key\'s project, at aistudio.google.com).'));
        if ($status === 404 || $code === 'not_found' || $code === 'model_not_found') return new HttpError(502, t('The chosen model is not available for this Gemini key. Choose another.'));
        if ($status === 400 && preg_match('/too long|too many tokens|context|token limit|input token/i', $message)) return new HttpError(413, t('The conversation grew too large for the model. Start a new conversation: the writer still knows the story (chapters, bible and planning).'), 'too-long');
        if ($status === 400 && $code === 'missing_thought_signature') return new HttpError(502, t('[GEMINI-SIGNATURE] Gemini refused the conversation because the reasoning signature of an earlier answer was missing. Start a new conversation and tell the developer.'));
        if ($status === 503 || $status === 500 || $status === 504 || in_array($code, ['service_unavailable', 'api_error', 'deadline_exceeded'], true) || $state === 'UNAVAILABLE')
            return new HttpError(502, t('Google could not complete the request right now (HTTP {status}). Wait a little and try again.', ['status'=>$status]), 'retry');
        $ref = implode(', ', array_filter([$code, $state !== null && $state !== 'INVALID_ARGUMENT' ? $state : null, $reason]));
        $vars = ['reference'=>$ref !== '' ? $ref : 'HTTP 400', 'fields'=>implode('”, “', $fields)];
        if ($status === 400) return new HttpError(502, $fields
            ? t('Gemini refused the request ({reference}), in the field “{fields}”. It was not a content block. If it keeps happening, send this text to the developer.', $vars)
            : t('Gemini refused the request ({reference}). It was not a content block. If it keeps happening, send this text to the developer.', $vars));
        return new HttpError(502, t('Google refused the request (HTTP {status}{detail}).', ['status'=>$status, 'detail'=>($ref !== '' ? ', '.$ref : '')]));
    }

    /** A generation the model ended with an error code (in an answer, or as the code of an HTTP error). */
    public static function codeError(string $code): HttpError
    {
        if (in_array($code, ['image_safety', 'image_prohibited_content', 'image_other'], true))
            return new HttpError(502, t('Google\'s image filter blocked the image Nano Banana would make ({code}). That filter belongs to the image model and has no setting in the API (the adjustable ones are already off). Rephrase the part that may have been misread, or use a GPT Image model.', ['code'=>$code]));
        if (in_array($code, ['recitation', 'image_recitation'], true))
            return new HttpError(502, t('Google blocked the answer for reproducing a protected work almost identical to the original ({code}). Ask for something more original, without copying existing characters, logos or text.', ['code'=>$code]));
        if ($code === 'no_image') return new HttpError(502, t('Gemini answered without making the image (no_image). Rewrite the request making it clear that it is to generate an image, or try again.'));
        if ($code === 'safety')
            return new HttpError(502, t('Google\'s safety filters blocked this request (safety), even with the adjustable ones off. Rephrase the part that may have been misread, or use another model.'));
        if (in_array($code, ['prohibited_content', 'spii', 'blocklist', 'content_blocked', 'jailbreak', 'language'], true))
            return new HttpError(502, t('Google blocked this request by a rule that always applies and has no setting in the API ({code}). Rephrase the part that may have been misread, or use another model.', ['code'=>$code]));
        if (in_array($code, self::BLOCKED, true))
            return new HttpError(502, t('Gemini\'s filters blocked this request ({code}). Rephrase the part that may have been misread, or use another model.', ['code'=>$code]));
        if (in_array($code, ['payment_required', 'quota_exceeded'], true)) return new HttpError(402, self::noCreditMessage(), 'gemini-credit');
        if ($code === 'missing_thought_signature') return new HttpError(502, t('[GEMINI-SIGNATURE] Gemini refused the conversation because the reasoning signature of an earlier answer was missing. Start a new conversation and tell the developer.'));
        return new HttpError(502, t('Gemini could not complete the request ({code}). Try again.', ['code'=>$code]));
    }

    /** Provider identifiers are safe to log and show; anything else is dropped. */
    private static function identifier(mixed $value): ?string
    { return is_string($value) && preg_match('/\A[A-Za-z0-9_.\-\/]{1,80}\z/D', $value) ? $value : null; }

    /** The prices of a model at a moment. */
    public static function prices(array $model, ?int $at = null): array
    {
        $at ??= time();
        foreach ($model['prices'] as $p) if ($p['until'] === null || $at < (int)strtotime($p['until'])) return $p;
        return end($model['prices']);
    }

    /**
     * A request's usage in tokens: input without the cache, read from the cache, output and the thinking (paid as
     * output). Google reports the thinking apart from the output; if a total says it was already counted in the output,
     * it isn't added twice.
     * @return array{input:int,read:int,output:int,thinking:int}
     */
    public static function tokens(array $usage): array
    {
        $input = (int)($usage['total_input_tokens'] ?? $usage['input_tokens'] ?? 0);
        $cached = min($input, (int)($usage['total_cached_tokens'] ?? $usage['cached_tokens'] ?? 0));
        $output = (int)($usage['total_output_tokens'] ?? $usage['output_tokens'] ?? 0);
        $thinking = (int)($usage['total_thought_tokens'] ?? $usage['total_reasoning_tokens'] ?? 0);
        $tool = (int)($usage['total_tool_use_tokens'] ?? 0);
        // The thinking inside the output already: the total has no room for it on top.
        if (isset($usage['total_tokens']) && $thinking > 0 && (int)$usage['total_tokens'] < $input + $output + $thinking + $tool) $output = max(0, $output - $thinking);
        return ['input'=>$input - $cached + $tool, 'read'=>$cached, 'output'=>$output, 'thinking'=>$thinking];
    }

    /** What a writer's request cost, US$, at the prices of the moment it was made. */
    public static function cost(string $model, array $usage, ?int $at = null): float
    {
        $p = self::prices(Catalog::writer('gemini', $model) ?? self::MODELS['gemini-4-neon'], $at); $t = self::tokens($usage);
        return ($t['input'] * $p['input'] + $t['read'] * $p['cached'] + ($t['output'] + $t['thinking']) * $p['output']) / 1e6;
    }

    /**
     * Checks a key without spending anything: the list of models it can use, and which of the app's are among them.
     * @return array{ok:bool,models:list<string>,missing:list<string>}
     */
    public static function check(string $key, ?\Closure $transport = null): array
    {
        $found = [];
        $page = '';
        for ($n = 0; $n < 6; $n++) {
            $url = self::MODELS_URL.'?pageSize=1000'.($page !== '' ? '&pageToken='.rawurlencode($page) : '');
            $result = $transport !== null ? $transport($url) : self::get($url, $key);
            if ((int)$result['errno'] !== 0) throw new HttpError(502, t('Could not connect to Google (network error {errno} on the server).', ['errno'=>$result['errno']]));
            if ((int)$result['status'] >= 400) throw self::failure((int)$result['status'], (string)$result['body']);
            $json = json_decode((string)$result['body'], true);
            foreach (is_array($json['models'] ?? null) ? $json['models'] : [] as $m) if (is_string($m['name'] ?? null)) $found[] = preg_replace('#^models/#', '', $m['name']);
            $page = is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : '';
            if ($page === '') break;
        }
        $ours = array_merge(array_keys(self::MODELS), array_keys(GeminiImages::MODELS), [GeminiImages::PLANNER]);
        return ['ok'=>true, 'models'=>array_values(array_intersect($ours, $found)), 'missing'=>array_values(array_diff(array_unique($ours), $found)),
            'new'=>array_values(array_filter(array_diff($found, $ours), [self::class, 'isNewWriter']))];
    }

    /**
     * A model the key lists that could be a writer the app doesn't offer yet: Gemini 3.8 or later, not one made for
     * speech, live audio, embeddings or pictures. Its price has to be checked before the app offers it, so the test
     * only names it.
     */
    public static function isNewWriter(string $id): bool
    {
        if (!preg_match('/\Agemini-(\d+)(?:\.(\d+))?-[a-z]/', $id, $m) || preg_match('/tts|live|audio|embed|image|banana|robotics|computer|veo|imagen|aqa/i', $id)) return false;
        return (int)$m[1] * 100 + (int)($m[2] ?? 0) >= 308;
    }

    /**
     * The test in Configurações: the key and its models (free), then a tiny conversation with the cheapest writer that
     * calls a tool and reads its result, as the writer does (a fraction of a cent). Each step says what it found, so a
     * problem with the key, the account or the shape of the API shows where it is.
     * @return array{ok:bool,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function selfTest(string $key, ?\Closure $list = null, ?\Closure $transport = null): array
    {
        $steps = [];
        $add = static function (string $name, bool $ok, string $detail) use (&$steps): void { $steps[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail]; };
        try {
            $check = self::check($key, $list);
            $missing = ['models'=>implode(', ', $check['missing'])];
            $add(t('Key and models'), true, ($check['missing']
                ? (count($check['missing']) > 2 ? t('Key accepted. This key does not list {models}: check that billing is active in the key\'s project.', $missing)
                    : t('Key accepted. This key does not list {models}: those will not work with it.', $missing))
                : t('Key accepted, with every model the app uses.'))
                .($check['new'] ? ' '.t('Gemini models on this key that the app does not offer yet: {models} (ask for them to be added; each one comes in with its price checked).', ['models'=>implode(', ', array_slice($check['new'], 0, 8))]) : ''));
        } catch (HttpError $e) { $add(t('Key and models'), false, $e->getMessage()); return ['ok'=>false, 'steps'=>$steps]; }
        $client = new self($key, 60, $transport);
        $tool = ['type'=>'function', 'name'=>'ping', 'description'=>'Answers pong. Call it once with the word given.',
            'parameters'=>['type'=>'object', 'properties'=>['word'=>['type'=>'string']], 'required'=>['word']]];
        $model = 'gemini-3.8-flash'; $cost = 0.0;
        $input = [['type'=>'user_input', 'content'=>[['type'=>'text', 'text'=>'Call the tool ping with the word "atelier". After its result, answer with only the word it returned.']]]];
        $body = ['model'=>$model, 'store'=>false, 'system_instruction'=>'You are a connection test. Be brief.', 'input'=>$input, 'tools'=>[$tool], 'safety_settings'=>self::safety(),
            'generation_config'=>['thinking_level'=>'low', 'max_output_tokens'=>2000]];
        $tick = static fn() => false; $quiet = static function () {};
        try {
            $first = $client->send($body, $quiet, $tick); $cost += self::cost($model, $first['usage']);
            $call = null;
            foreach ($first['steps'] as $step) if (($step->type ?? '') === 'function_call') { $call = $step; break; }
            if ($call === null) { $add(t('Writing with tools'), false, t('Gemini answered, but without calling the test tool (status {status}). Tell the developer.', ['status'=>$first['status']])); return ['ok'=>false, 'steps'=>$steps]; }
            $body['input'] = array_merge($input, $first['steps'], [(object)['type'=>'function_result', 'call_id'=>(string)($call->id ?? ''), 'name'=>'ping', 'result'=>[['type'=>'text', 'text'=>'pong']]]]);
            $second = $client->send($body, $quiet, $tick); $cost += self::cost($model, $second['usage']);
            $text = '';
            foreach ($second['steps'] as $step) if (($step->type ?? '') === 'model_output') foreach ((array)($step->content ?? []) as $c) if (($c->type ?? '') === 'text') $text .= (string)($c->text ?? '');
            $ok = stripos($text, 'pong') !== false;
            $add(t('Writing with tools'), $ok, ($ok ? t('Gemini called the tool, read the result and answered.') : t('Gemini called the tool, but the answer after the result did not come as expected. Tell the developer.'))
                .' '.t('Test cost: about US$ {cost}.', ['cost'=>I18n::number($cost, 4)]));
        } catch (HttpError $e) { $add(t('Writing with tools'), false, $e->getMessage()); }
        return ['ok'=>!array_filter($steps, fn($s) => !$s['ok']), 'steps'=>$steps];
    }

    /** @return array{status:int,body:string,errno:int} */
    private static function get(string $url, string $key): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER=>self::headers($key), CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>30,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $errno = curl_errno($curl); curl_close($curl);
        return ['status'=>$status, 'body'=>is_string($body) ? $body : '', 'errno'=>$body === false ? max(1, $errno) : $errno];
    }
}
