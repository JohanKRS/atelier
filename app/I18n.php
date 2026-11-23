<?php
declare(strict_types=1);
namespace Studio;

/**
 * The languages of the interface. The code says every sentence in English; assets/i18n/<language>.json maps each English
 * sentence to its translation, for the page (assets/i18n.js) and the server alike. A sentence that has no translation is
 * shown in English. A short word that needs two translations (Medium for a quality and for an effort) carries a context:
 * t('Medium', ['ctx' => 'effort']) looks up "effort|Medium".
 *
 * The language is the browser's choice: the cookie the language menu sets, otherwise the first language of
 * Accept-Language that the app has, otherwise English. It is not a secret and needs no account, so the sign-in screen
 * has it too. Command line processes (bin/worker.php) get it from ATELIER_LANG, which Spawn passes on.
 */
final class I18n
{
    /** Each language: its tag (as <html lang> takes it) and its name, written in that language. */
    public const LANGUAGES = ['en' => 'English', 'pt-BR' => 'Português (Brasil)'];
    public const COOKIE = 'atelier_lang';

    private static string $language = 'en';
    /** @var ?array<string,string> */
    private static ?array $strings = null;

    public static function detect(): string
    {
        if (PHP_SAPI === 'cli') {
            $chosen = getenv('ATELIER_LANG');
            return is_string($chosen) && isset(self::LANGUAGES[$chosen]) ? $chosen : 'en';
        }
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($cookie) && isset(self::LANGUAGES[$cookie])) return $cookie;
        return self::fromHeader((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    }

    /** The first language of an Accept-Language header that the app has ("pt" and "pt-PT" get pt-BR), or English. */
    public static function fromHeader(string $header): string
    {
        $wanted = [];
        foreach (array_slice(explode(',', $header), 0, 20) as $i => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = strtolower($pieces[0]);
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $param) if (preg_match('/\Aq=([01](?:\.\d{0,3})?)\z/', $param, $m)) $quality = (float)$m[1];
            if ($tag !== '' && $quality > 0) $wanted[] = [$quality, -$i, $tag];
        }
        rsort($wanted);
        foreach ($wanted as [, , $tag]) {
            if ($tag === 'pt' || str_starts_with($tag, 'pt-')) return 'pt-BR';
            if ($tag === 'en' || str_starts_with($tag, 'en-')) return 'en';
        }
        return 'en';
    }

    public static function use(string $language): void
    {
        $language = isset(self::LANGUAGES[$language]) ? $language : 'en';
        if ($language !== self::$language) self::$strings = null;
        self::$language = $language;
    }

    public static function language(): string { return self::$language; }

    /** What $make returns with the interface in another language: English for a text kept to be shown later in any. */
    public static function in(string $language, callable $make): mixed
    {
        $was = self::$language; self::use($language);
        try { return $make(); } finally { self::use($was); }
    }

    /** The file with the translations of a language (none for English, the language of the code). */
    public static function file(string $language): ?string
    { return $language === 'en' || !isset(self::LANGUAGES[$language]) ? null : dirname(__DIR__).'/assets/i18n/'.$language.'.json'; }

    /** @return array<string,string> */
    public static function strings(): array
    {
        if (self::$strings !== null) return self::$strings;
        $file = self::file(self::$language);
        $json = $file === null ? false : @file_get_contents($file);
        try { $data = is_string($json) ? json_decode($json, true, 4, JSON_THROW_ON_ERROR) : []; } catch (\JsonException) { $data = []; }
        return self::$strings = is_array($data) ? array_filter($data, 'is_string') : [];
    }

    /**
     * The sentence in the current language. {name} marks are filled from $vars; 'ctx' in $vars is the context of a short
     * word, never a mark.
     */
    public static function t(string $text, array $vars = []): string
    {
        $context = isset($vars['ctx']) ? (string)$vars['ctx'] : '';
        unset($vars['ctx']);
        if (self::$language !== 'en') $text = self::strings()[$context === '' ? $text : $context.'|'.$text] ?? $text;
        if ($vars === []) return $text;
        $marks = [];
        foreach ($vars as $name => $value) $marks['{'.$name.'}'] = (string)$value;
        return strtr($text, $marks);
    }

    /** A number as the language writes it: 1,234.5 in English, 1.234,5 in Portuguese. */
    public static function number(float|int $value, int $decimals = 0): string
    { return self::$language === 'pt-BR' ? number_format((float)$value, $decimals, ',', '.') : number_format((float)$value, $decimals, '.', ','); }

    /** A date as the language writes it, with or without the time. */
    public static function date(int $time, bool $withTime = false): string
    {
        $format = self::$language === 'pt-BR' ? ($withTime ? 'd/m/Y H:i' : 'd/m/Y') : ($withTime ? 'M j, Y H:i' : 'M j, Y');
        return date($format, $time);
    }
}

/** The sentence in the interface's language (I18n::t). */
function t(string $text, array $vars = []): string { return I18n::t($text, $vars); }

/** The sentence in the interface's language, escaped for HTML. */
function e(string $text, array $vars = []): string { return htmlspecialchars(I18n::t($text, $vars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
