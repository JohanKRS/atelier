<?php
declare(strict_types=1);
// The translations: every sentence the code passes to t() or e() (PHP and JavaScript) has a Portuguese entry with the
// same {marks}, and every entry is still a sentence of the code.
// Run: php tests/i18n.php

$root = dirname(__DIR__);
$failures = 0;
function check(bool $ok, string $what): void { global $failures; echo ($ok ? 'PASS ' : 'FAIL ').$what."\n"; if (!$ok) $failures++; }

$strings = json_decode((string)file_get_contents("$root/assets/i18n/pt-BR.json"), true, 4, JSON_THROW_ON_ERROR);
check(is_array($strings) && $strings !== [] && !array_filter($strings, fn($v) => !is_string($v) || trim($v) === ''), 'pt-BR.json is a map of sentences to sentences');

/** @var array<string,string> key => where it is used */
$used = [];
/** Every string literal of the code, for entries looked up from a variable (t($label)). */
$literals = [];

$php = array_merge(glob("$root/app/*.php"), glob("$root/bin/*.php"), ["$root/index.php", "$root/mcp.php"]);
foreach ($php as $file) {
    $tokens = array_values(array_filter(token_get_all((string)file_get_contents($file)), fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $value = static function (array $t): string {
        $s = $t[1];
        return $s[0] === "'" ? strtr(substr($s, 1, -1), ["\\'" => "'", '\\\\' => '\\']) : stripcslashes(substr($s, 1, -1));
    };
    foreach ($tokens as $i => $tk) {
        if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) $literals[$value($tk)] = true;
        if (!is_array($tk) || $tk[0] !== T_STRING || !in_array($tk[1], ['t', 'e'], true) || ($tokens[$i + 1] ?? null) !== '(') continue;
        $before = $tokens[$i - 1] ?? null;
        if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) continue;
        $arg = $tokens[$i + 2] ?? null;
        if (!is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $next = $tokens[$i + 3] ?? null;
        if ($next !== ')' && $next !== ',') continue; // a concatenation: not one sentence
        $key = $value($arg);
        // ['ctx' => 'effort'] right after the sentence
        if ($next === ',' && ($tokens[$i + 4] ?? null) === '[' && is_array($tokens[$i + 5] ?? null) && $tokens[$i + 5][1] === "'ctx'" && is_array($tokens[$i + 7] ?? null))
            $key = $value($tokens[$i + 7]).'|'.$key;
        $used[$key] ??= basename($file).':'.$tk[2];
    }
}

$unquote = static function (string $s): string {
    $q = $s[0]; $body = substr($s, 1, -1);
    return preg_replace_callback('/\\\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/s', function ($m) {
        $c = $m[1];
        if ($c[0] === 'u') return mb_chr(hexdec(trim(substr($c, 1), '{}')), 'UTF-8');
        if ($c[0] === 'x' && strlen($c) === 3) return chr(hexdec(substr($c, 1)));
        return ['n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0"][$c] ?? $c;
    }, $body);
};
$quoted = "'(?:[^'\\\\\\n]|\\\\.)*'|\"(?:[^\"\\\\\\n]|\\\\.)*\"";
foreach (glob("$root/assets/*.js") as $file) {
    $src = (string)file_get_contents($file);
    preg_match_all("/$quoted/", $src, $all);
    foreach ($all[0] as $lit) $literals[$unquote($lit)] = true;
    preg_match_all("/(?<![\\w.\$])t\\(\\s*($quoted)\\s*(?:\\)|,\\s*(\\{[^{}]*\\}))?/", $src, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($calls as $c) {
        $key = $unquote($c[1][0]);
        if (isset($c[2]) && preg_match("/\\bctx:\\s*'([\\w-]+)'/", $c[2][0], $ctx)) $key = $ctx[1].'|'.$key;
        $used[$key] ??= basename($file).':'.(substr_count($src, "\n", 0, $c[0][1]) + 1);
    }
}
check(count($used) > 500, 'found the sentences of the code ('.count($used).')');

$missing = array_diff_key($used, $strings);
foreach (array_slice($missing, 0, 30, true) as $key => $where) echo "  no translation: $where \"$key\"\n";
check($missing === [], 'every sentence passed to t() or e() has a Portuguese entry');

$stale = array_filter(array_keys($strings), fn($k) => !isset($used[$k]) && !isset($literals[preg_replace('/\A[\w-]+\|/', '', $k)]));
foreach (array_slice($stale, 0, 30) as $key) echo "  not in the code: \"$key\"\n";
check($stale === [], 'every Portuguese entry is still a sentence of the code');

$marks = static function (string $s): array { preg_match_all('/\{(\w+)\}/', $s, $m); $m = array_unique($m[1]); sort($m); return $m; };
$wrong = array_filter($strings, fn($pt, $en) => $marks($pt) !== $marks(preg_replace('/\A[\w-]+\|/', '', $en)), ARRAY_FILTER_USE_BOTH);
foreach (array_slice($wrong, 0, 30, true) as $en => $pt) echo "  other marks: \"$en\" -> \"$pt\"\n";
check($wrong === [], 'each translation has the same {marks} as its sentence');

$tags = static function (string $s): array { preg_match_all('/<\/?([a-z]+)\b/i', $s, $m); $m = $m[1]; sort($m); return $m; };
$wrongTags = array_filter($strings, fn($pt, $en) => $tags($pt) !== $tags($en), ARRAY_FILTER_USE_BOTH);
foreach (array_slice($wrongTags, 0, 30, true) as $en => $pt) echo "  other tags: \"$en\" -> \"$pt\"\n";
check($wrongTags === [], 'each translation has the same HTML tags as its sentence');

// The language: the browser's first one that the app has, English otherwise; the sentences, marks and contexts.
require "$root/app/I18n.php";
use Studio\I18n;
check(I18n::fromHeader('pt-BR,pt;q=0.9,en;q=0.8') === 'pt-BR' && I18n::fromHeader('pt-PT') === 'pt-BR' && I18n::fromHeader('en-GB,pt;q=0.5') === 'en'
    && I18n::fromHeader('de-DE,pt;q=0.4') === 'pt-BR' && I18n::fromHeader('fr, de') === 'en' && I18n::fromHeader('') === 'en' && I18n::fromHeader('en;q=0.2, pt;q=0.9') === 'pt-BR'
    && I18n::fromHeader('pt;q=0') === 'en', 'Accept-Language picks the first language the app has, by weight, and English when there is none');
I18n::use('en');
check(\Studio\t('Invalid size.') === 'Invalid size.' && \Studio\t('Upscaled to {width} × {height}', ['width'=>2048, 'height'=>1536]) === 'Upscaled to 2048 × 1536'
    && \Studio\t('{n} left', ['n'=>3, 'm'=>4]) === '3 left' && \Studio\e('<b>{x}</b>', ['x'=>'&']) === '&lt;b&gt;&amp;&lt;/b&gt;', 'English is the code itself, with its marks filled');
I18n::use('pt-BR');
check(\Studio\t('Invalid size.') === 'Tamanho inválido.' && \Studio\t('Upscaled to {width} × {height}', ['width'=>2048, 'height'=>1536]) === 'Ampliada para 2048 × 1536'
    && \Studio\t('A sentence the dictionary does not have.') === 'A sentence the dictionary does not have.', 'Portuguese comes from pt-BR.json, and a sentence it lacks stays in English');
$contexts = array_filter(array_keys($strings), fn($k) => preg_match('/\A[\w-]+\|/', $k));
[$context, $word] = explode('|', (string)reset($contexts), 2);
check($contexts !== [] && \Studio\t($word, ['ctx'=>$context]) === $strings["$context|$word"] && \Studio\t($word, ['ctx'=>'no-such-context']) === $word, 'a short word with a context is looked up with it');
check(I18n::number(1234.5, 2) === '1.234,50' && I18n::date(86400 * 365 + 43200) === '01/01/1971', 'numbers and dates as Portuguese writes them');
I18n::use('en');
check(I18n::number(1234.5, 2) === '1,234.50' && I18n::date(86400 * 365 + 43200) === 'Jan 1, 1971' && I18n::file('en') === null, 'and as English does');
I18n::use('xx');
check(I18n::language() === 'en', 'an unknown language is English');

exit($failures ? 1 : 0);
