<?php
declare(strict_types=1);
namespace Studio;

/**
 * A visual novel project: a story (Stories, kind "vn") whose bible is the design document and whose chapters hold the
 * base of each chapter, with what a visual novel adds to it:
 *
 * - The settings (the game's name, the shape of backgrounds and CGs, how sprites are framed, the first scene), the
 *   variables (flags, affinity points, text), the routes, the music and sound cues and the gameplay notes. One sealed
 *   blob in the story's row (vn).
 * - The scenes, one row each (vn_scenes): a label (its name in the script, like a Ren'Py label), a title, the chapter
 *   and route it belongs to, a summary, gameplay notes, its script and its exit (a jump, a choice, a branch on the
 *   variables, an ending, or nothing yet). Exits name other scenes by label, so a scene can point to one not written yet.
 * - The art (vn_art): sprites of each character by outfit and expression, on a transparent background; backgrounds of
 *   each place with variants (day, night, rain…); CGs, the illustrations of special moments.
 *
 * A scene's script is plain text, one step per line, close to Ren'Py's (see SCRIPT_HELP):
 *     @bg Café Central, noite, fade
 *     @show Ana, feliz, esquerda
 *     Ana: Oi! Você veio.
 *     Ana (brava): Mas atrasado.
 *     > O café estava quase vazio.
 *     @set afinidade_ana += 1
 *     @if afinidade_ana >= 2: Ana: Senti sua falta.
 * The app parses it (parse()) for the tree's checks, the playable preview and the Ren'Py export.
 */
final class Vn
{
    public const ASPECTS = ['16:9', '4:3', '3:2'];
    /** How sprites are framed: the whole body, down to the knees, down to the waist. */
    public const FRAMINGS = ['full', 'knees', 'waist'];
    public const POSITIONS = ['left', 'centerleft', 'center', 'centerright', 'right'];
    public const TRANSITIONS = ['none', 'fade', 'dissolve', 'flash', 'slow'];
    public const EFFECTS = ['shake', 'flash'];
    public const ENDING_KINDS = ['good', 'normal', 'bad', 'true', 'secret'];
    public const VAR_TYPES = ['flag', 'number', 'text'];
    public const AUDIO_KINDS = ['music', 'sound'];
    public const EXIT_TYPES = ['none', 'jump', 'choice', 'branch', 'end'];
    public const ART_KINDS = ['sprite', 'background', 'cg'];
    public const DEFAULTS = ['name'=>'', 'aspect'=>'16:9', 'framing'=>'knees', 'start'=>''];
    public const MAX_SCENES = 2000;
    public const MAX_SCRIPT = 60000;     // characters in one scene's script
    public const MAX_LINES = 1500;       // steps in one scene
    public const MAX_VARIABLES = 200;
    public const MAX_ROUTES = 30;
    public const MAX_AUDIO = 300;
    public const MAX_OPTIONS = 9;
    public const MAX_SPRITES = 800;
    public const MAX_BACKGROUNDS = 400;
    public const MAX_CGS = 400;
    /** Labels Ren'Py gives a meaning of its own: a scene can't take them. */
    private const RESERVED_LABELS = ['start', 'quit', 'after_load', 'splashscreen', 'before_main_menu', 'main_menu', 'after_warp', 'hide_windows', 'main_game_transition'];
    /** Names a variable can't take: the words of the conditions, and Python's and Ren'Py's own. */
    private const RESERVED_NAMES = ['and', 'or', 'not', 'e', 'ou', 'nao', 'true', 'false', 'none', 'verdadeiro', 'falso', 'sim', 'if', 'else', 'elif', 'while', 'for', 'in', 'is',
        'def', 'class', 'return', 'import', 'from', 'as', 'with', 'pass', 'break', 'continue', 'lambda', 'global', 'nonlocal', 'del', 'try', 'except', 'finally', 'raise', 'yield',
        'assert', 'async', 'await', 'config', 'persistent', 'store', 'renpy', 'gui', 'preferences', 'style', 'build', 'director', 'achievement', 'define', 'default', 'label',
        'menu', 'jump', 'call', 'scene', 'show', 'hide', 'play', 'stop', 'queue', 'pause', 'image', 'screen', 'transform', 'init', 'python', 'narrator', 'extend', 'centered', 'vcentered'];
    /** Words of the script, Portuguese and English, for the positions, transitions and effects. */
    private const POSITION_WORDS = ['left'=>'left', 'esquerda'=>'left', 'esq'=>'left', 'centerleft'=>'centerleft', 'centro-esquerda'=>'centerleft', 'centroesquerda'=>'centerleft',
        'center'=>'center', 'centro'=>'center', 'meio'=>'center', 'centerright'=>'centerright', 'centro-direita'=>'centerright', 'centrodireita'=>'centerright',
        'right'=>'right', 'direita'=>'right', 'dir'=>'right'];
    private const TRANSITION_WORDS = ['none'=>'none', 'nenhuma'=>'none', 'corte'=>'none', 'fade'=>'fade', 'escurecer'=>'fade', 'dissolve'=>'dissolve', 'dissolver'=>'dissolve',
        'flash'=>'flash', 'clarao'=>'flash', 'clarão'=>'flash', 'slow'=>'slow', 'lento'=>'slow', 'lenta'=>'slow'];
    private const EFFECT_WORDS = ['shake'=>'shake', 'tremer'=>'shake', 'tremor'=>'shake', 'flash'=>'flash', 'clarao'=>'flash', 'clarão'=>'flash'];

    /** What the writer and the editor's help say about the script. */
    public const SCRIPT_HELP = <<<'TEXT'
One step per line; blank lines are ignored.
- Dialogue: `Name: text`, with the speaker's name as in the character sheets. `Name (expression): text` also changes their sprite to that expression first (attributes like "uniform happy" for an outfit and an expression). A speaker whose name the player doesn't know yet can be `???`.
- Narration: any other line, or a line starting with `> ` (use it when the text itself starts like "Word: …").
- `@bg Place[, variant][, transition]`: the background, replacing the scene (characters leave). The place is a place sheet's name (or any name for a place without a sheet); variant is like day, night, rain, sunset.
- `@show Name[, expression][, position][, transition]`: shows a character's sprite, or changes it. Positions: left, centerleft, center, centerright, right (esquerda, centro-esquerda, centro, centro-direita, direita).
- `@hide Name[, transition]`.
- `@cg name[, transition]`: a full-screen CG (an illustration of a special moment), until the next @bg.
- `@music track[, fade seconds]` and `@music stop[, fade seconds]`; `@sound name`. Tracks and sounds are named in the audio list.
- `@set variable = value`, `@set variable += number`, `@set variable -= number`. Values: numbers, true/false, "text", or another variable.
- `@if condition: step`: the step only when the condition holds (one step; it can be dialogue, narration or a command).
- `@pause seconds`, `@effect shake` or `@effect flash`.
- `# text`: a note for the team (direction, gameplay); the player doesn't see it.
Transitions: none, fade, dissolve, flash, slow. Conditions use the variables with ==, !=, <, <=, >, >=, and, or, not, parentheses, true, false, numbers and "text"; a variable alone means it is set (a flag that is true, a number that isn't 0).
TEXT;

    public function __construct(private Vault $vault, private Stories $stories) {}

    private function owner(): string { return $this->vault->owner(); }
    private static function newId(): string { return bin2hex(random_bytes(16)); }

    private static function seal(mixed $value, string $key, string $context): string
    { return Crypto::seal(json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $key, $context); }

    private static function open(mixed $blob, string $key, string $context): mixed
    { return $blob === null ? null : json_decode(Crypto::open((string)$blob, $key, $context), true, 512, JSON_THROW_ON_ERROR); }

    /** A visual novel project of the account (404 for another story, 422 for a story that isn't one). */
    public function project(string $storyId, string $key): array
    {
        $row = $this->stories->rowOf($storyId);
        if (!$this->stories->isVn($row, $key)) throw new HttpError(422, t('This story is not a visual novel project. (This tool is for visual novel projects.)'));
        return $row;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Names: labels, variables, file names
    // -----------------------------------------------------------------------------------------------------------------

    /** A name in plain ASCII lowercase with underscores, as Ren'Py and file names take it: "Café Central" → "cafe_central". */
    public static function slug(string $text, int $max = 40): string
    {
        $text = strtr(mb_strtolower(trim($text)), ['á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e', 'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
            'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u', 'ç'=>'c', 'ñ'=>'n', 'ý'=>'y', 'ÿ'=>'y', 'œ'=>'oe', 'æ'=>'ae', 'ß'=>'ss']);
        $text = trim(preg_replace('/[^a-z0-9]+/', '_', $text) ?? '', '_');
        $text = substr($text, 0, $max);
        $text = rtrim($text, '_');
        if ($text === '') return 'x';
        return ctype_digit($text[0]) ? 'x'.$text : $text;
    }

    /** A scene's label as given (a title works too: it becomes its slug); 422 when it can't be one. */
    public static function label(mixed $value, string $what = ''): string
    {
        if ($what === '') $what = t('The scene\'s label');
        if (!is_string($value) || trim($value) === '') throw new HttpError(422, t('{what} is empty.', ['what'=>$what]));
        $label = preg_match('/\A[a-z_][a-z0-9_]{0,63}\z/D', trim($value)) ? trim($value) : self::slug($value, 60);
        if (in_array($label, self::RESERVED_LABELS, true)) throw new HttpError(422, t('{what} cannot be “{label}”: Ren\'Py uses that name. Choose another.', ['what'=>$what, 'label'=>$label]));
        return $label;
    }

    public static function variableName(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,47}\z/D', trim($value)))
            throw new HttpError(422, t('Invalid variable name: use letters without accents, numbers and _, starting with a letter (e.g. affinity_ana, saw_the_letter).'));
        $name = trim($value);
        if (in_array(strtolower($name), self::RESERVED_NAMES, true)) throw new HttpError(422, t('The variable cannot be called “{name}”: it is a reserved word.', ['name'=>$name]));
        return $name;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Conditions: a small expression language, the same in the checks, the preview and the export
    // -----------------------------------------------------------------------------------------------------------------

    /** @return list<array{0:string,1:mixed}> the tokens of a condition */
    private static function tokens(string $text): array
    {
        $out = []; $i = 0; $n = strlen($text);
        while ($i < $n) {
            $ch = $text[$i];
            if (ctype_space($ch)) { $i++; continue; }
            if ($ch === '"' || $ch === "'") {
                $end = strpos($text, $ch, $i + 1);
                if ($end === false) throw new HttpError(422, t('Quoted text not closed in the condition.'));
                $out[] = ['str', substr($text, $i + 1, $end - $i - 1)]; $i = $end + 1; continue;
            }
            if (preg_match('/\G-?\d+(?:[.,]\d+)?/A', $text, $m, 0, $i) && ($ch !== '-' || !$out || in_array($out[count($out) - 1][0], ['op', '(', 'and', 'or', 'not'], true))) {
                $v = str_replace(',', '.', $m[0]); $out[] = ['num', str_contains($v, '.') ? (float)$v : (int)$v]; $i += strlen($m[0]); continue;
            }
            if (preg_match('/\G(==|!=|<=|>=|&&|\|\||[<>=!()])/A', $text, $m, 0, $i)) {
                $op = $m[1]; $i += strlen($op);
                if ($op === '(' || $op === ')') $out[] = [$op, null];
                elseif ($op === '&&') $out[] = ['and', null];
                elseif ($op === '||') $out[] = ['or', null];
                elseif ($op === '!') $out[] = ['not', null];
                else $out[] = ['op', $op === '=' ? '==' : $op];
                continue;
            }
            if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/A', $text, $m, 0, $i)) {
                $word = $m[0]; $i += strlen($word); $lower = mb_strtolower($word);
                $out[] = match ($lower) {
                    'and', 'e'=>['and', null], 'or', 'ou'=>['or', null], 'not', 'não', 'nao'=>['not', null],
                    'true', 'verdadeiro', 'sim'=>['bool', true], 'false', 'falso'=>['bool', false],
                    default=>preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $word) ? ['var', $word] : throw new HttpError(422, t('Invalid name in the condition: “{word}” (variables have no accents).', ['word'=>$word])),
                };
                continue;
            }
            throw new HttpError(422, t('Unexpected character in the condition: “{ch}”.', ['ch'=>$ch]));
        }
        return $out;
    }

    /**
     * A condition as a tree: ['var', name], ['num', n], ['str', s], ['bool', b], ['not', x], ['and', a, b], ['or', a, b],
     * ['cmp', op, a, b]. 422 with what is wrong.
     */
    public static function condition(string $text): array
    {
        $text = trim($text);
        if ($text === '') throw new HttpError(422, t('The condition is empty.'));
        if (mb_strlen($text) > 500) throw new HttpError(422, t('The condition is over 500 characters.'));
        $t = self::tokens($text); $p = 0;
        $or = null; $and = null; $not = null; $cmp = null; $atom = null;
        $atom = function () use (&$t, &$p, &$or): array {
            $tok = $t[$p] ?? null;
            if ($tok === null) throw new HttpError(422, t('The condition ends too early.'));
            $p++;
            if ($tok[0] === '(') { $e = $or(); if (($t[$p][0] ?? null) !== ')') throw new HttpError(422, t('A parenthesis is not closed in the condition.')); $p++; return $e; }
            if (in_array($tok[0], ['var', 'num', 'str', 'bool'], true)) return [$tok[0], $tok[1]];
            throw new HttpError(422, t('The condition has an operator out of place.'));
        };
        $cmp = function () use (&$t, &$p, $atom): array {
            $a = $atom();
            if (($t[$p][0] ?? null) === 'op') { $op = $t[$p][1]; $p++; return ['cmp', $op, $a, $atom()]; }
            return $a;
        };
        $not = function () use (&$t, &$p, &$not, $cmp): array {
            if (($t[$p][0] ?? null) === 'not') { $p++; return ['not', $not()]; }
            return $cmp();
        };
        $and = function () use (&$t, &$p, $not): array {
            $a = $not();
            while (($t[$p][0] ?? null) === 'and') { $p++; $a = ['and', $a, $not()]; }
            return $a;
        };
        $or = function () use (&$t, &$p, $and): array {
            $a = $and();
            while (($t[$p][0] ?? null) === 'or') { $p++; $a = ['or', $a, $and()]; }
            return $a;
        };
        $tree = $or();
        if ($p < count($t)) throw new HttpError(422, t('Something is left at the end of the condition (missing an “and” or “or”?).'));
        return $tree;
    }

    /** The variables a condition reads. */
    public static function conditionVars(array $tree): array
    {
        return match ($tree[0]) {
            'var'=>[$tree[1]], 'not'=>self::conditionVars($tree[1]), 'and', 'or'=>[...self::conditionVars($tree[1]), ...self::conditionVars($tree[2])],
            'cmp'=>[...self::conditionVars($tree[2]), ...self::conditionVars($tree[3])], default=>[],
        };
    }

    /** A condition written back as Python (Ren'Py): and, or, not, True, False. */
    public static function python(array $tree, int $parent = 0): string
    {
        $wrap = fn(string $s, int $level) => $level < $parent ? "($s)" : $s;
        return match ($tree[0]) {
            'var'=>$tree[1], 'num'=>(string)$tree[1], 'bool'=>$tree[1] ? 'True' : 'False', 'str'=>self::pyString((string)$tree[1]),
            'not'=>$wrap('not '.self::python($tree[1], 3), 3),
            'and'=>$wrap(self::python($tree[1], 2).' and '.self::python($tree[2], 2), 2),
            'or'=>$wrap(self::python($tree[1], 1).' or '.self::python($tree[2], 1), 1),
            'cmp'=>$wrap(self::python($tree[2], 4).' '.$tree[1].' '.self::python($tree[3], 4), 4),
        };
    }

    /** A Python string literal. */
    public static function pyString(string $s): string
    { return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s).'"'; }

    public static function truthy(mixed $v): bool { return is_string($v) ? $v !== '' : (bool)$v; }

    /** What a condition is with these values (a variable not set counts as false/0). */
    public static function evaluate(array $tree, array $vars): mixed
    {
        switch ($tree[0]) {
            case 'var': return $vars[$tree[1]] ?? false;
            case 'num': case 'str': case 'bool': return $tree[1];
            case 'not': return !self::truthy(self::evaluate($tree[1], $vars));
            case 'and': return self::truthy(self::evaluate($tree[1], $vars)) && self::truthy(self::evaluate($tree[2], $vars));
            case 'or': return self::truthy(self::evaluate($tree[1], $vars)) || self::truthy(self::evaluate($tree[2], $vars));
            case 'cmp':
                $a = self::evaluate($tree[2], $vars); $b = self::evaluate($tree[3], $vars);
                if (is_bool($a) || is_bool($b)) { $a = is_bool($a) ? (int)$a : $a; $b = is_bool($b) ? (int)$b : $b; }
                $numeric = (is_int($a) || is_float($a)) && (is_int($b) || is_float($b));
                if (!$numeric) { $a = (string)$a; $b = (string)$b; }
                return match ($tree[1]) { '=='=>$a == $b, '!='=>$a != $b, '<'=>$a < $b, '<='=>$a <= $b, '>'=>$a > $b, '>='=>$a >= $b, default=>false };
        }
        return false;
    }

    /**
     * A change of a variable: "name = value", "name += n", "name -= n". The value is a literal or another variable.
     * @return array{var:string,op:string,value:array}
     */
    public static function assignment(string $text): array
    {
        if (!preg_match('/\A\s*([A-Za-z_][A-Za-z0-9_]*)\s*(\+=|-=|=)\s*(.+?)\s*\z/sD', $text, $m))
            throw new HttpError(422, t('Use “variable = value”, “variable += number” or “variable -= number”.'));
        $var = self::variableName($m[1]);
        $value = self::condition($m[3]);
        if (!in_array($value[0], ['num', 'str', 'bool', 'var'], true)) throw new HttpError(422, t('The value of @set is a number, true/false, a quoted text or another variable.'));
        if ($m[2] !== '=' && !in_array($value[0], ['num', 'var'], true)) throw new HttpError(422, t('{op} adds or subtracts a number.', ['op'=>$m[2]]));
        return ['var'=>$var, 'op'=>$m[2], 'value'=>$value];
    }

    public static function apply(array $set, array $vars): array
    {
        $value = self::evaluate($set['value'], $vars);
        if ($set['op'] === '=') { $vars[$set['var']] = $value; return $vars; }
        $now = $vars[$set['var']] ?? 0; $now = is_numeric($now) ? $now + 0 : 0; $value = is_numeric($value) ? $value + 0 : 0;
        $vars[$set['var']] = $set['op'] === '+=' ? $now + $value : $now - $value;
        return $vars;
    }

    public static function assignmentText(array $set): string
    { return $set['var'].' '.$set['op'].' '.self::source($set['value']); }

    /** A condition (or a value) written as the script writes it. */
    public static function source(array $tree, int $parent = 0): string
    {
        $wrap = fn(string $s, int $level) => $level < $parent ? "($s)" : $s;
        return match ($tree[0]) {
            'var'=>$tree[1], 'num'=>(string)$tree[1], 'bool'=>$tree[1] ? 'true' : 'false', 'str'=>'"'.str_replace('"', "'", (string)$tree[1]).'"',
            'not'=>$wrap('not '.self::source($tree[1], 3), 3),
            'and'=>$wrap(self::source($tree[1], 2).' and '.self::source($tree[2], 2), 2),
            'or'=>$wrap(self::source($tree[1], 1).' or '.self::source($tree[2], 1), 1),
            'cmp'=>$wrap(self::source($tree[2], 4).' '.$tree[1].' '.self::source($tree[3], 4), 4),
        };
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The script
    // -----------------------------------------------------------------------------------------------------------------

    /** The words for a position, a transition or an effect, as the script's own. */
    private static function word(string $text, array $words): ?string { return $words[mb_strtolower(trim($text))] ?? null; }

    /** The colon that ends a condition, outside quotes. */
    private static function conditionEnd(string $text): ?int
    {
        $quote = null;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $ch = $text[$i];
            if ($quote !== null) { if ($ch === $quote) $quote = null; continue; }
            if ($ch === '"' || $ch === "'") $quote = $ch;
            elseif ($ch === ':') return $i;
        }
        return null;
    }

    /**
     * A scene's script as steps. $names: the characters' names, to tell "Ana Clara feliz" (a name and an expression)
     * apart when a command was written without commas.
     * @return array{steps:list<array>,errors:list<array{line:int,message:string}>}
     */
    public static function parse(string $script, array $names = []): array
    {
        $steps = []; $errors = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $script));
        if (count($lines) > self::MAX_LINES * 2) return ['steps'=>[], 'errors'=>[['line'=>1, 'message'=>t('The script is over {max} lines: split the scene into others.', ['max'=>(self::MAX_LINES * 2)])]]];
        $known = [];
        foreach ($names as $name) $known[mb_strtolower(trim((string)$name))] = trim((string)$name);
        foreach ($lines as $n => $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            try {
                $step = self::parseLine($line, $known);
                if ($step !== null) $steps[] = ['line'=>$n + 1] + $step;
            } catch (HttpError $e) { $errors[] = ['line'=>$n + 1, 'message'=>$e->getMessage()]; }
            if (count($steps) > self::MAX_LINES) { $errors[] = ['line'=>$n + 1, 'message'=>t('The scene is over {max} steps: split it into others.', ['max'=>self::MAX_LINES])]; break; }
        }
        return ['steps'=>$steps, 'errors'=>$errors];
    }

    private static function parseLine(string $line, array $known, bool $nested = false): ?array
    {
        if (mb_strlen($line) > 4000) throw new HttpError(422, t('The line is over 4,000 characters.'));
        if (str_starts_with($line, '#')) return ['t'=>'note', 'text'=>trim(substr($line, 1))];
        if (str_starts_with($line, '>')) {
            $text = trim(substr($line, 1));
            if ($text === '') throw new HttpError(422, t('Empty narration.'));
            return ['t'=>'say', 'who'=>null, 'attrs'=>'', 'text'=>$text];
        }
        if (str_starts_with($line, '@')) {
            if (!preg_match('/\A@([A-Za-zÀ-ÿ]+)\s*(.*)\z/su', $line, $m)) throw new HttpError(422, t('Command with no name after the @.'));
            $command = mb_strtolower($m[1]); $rest = trim($m[2]);
            if ($command === 'if' || $command === 'se') {
                if ($nested) throw new HttpError(422, t('@if inside @if: use “and” in the condition.'));
                $at = self::conditionEnd($rest);
                if ($at === null) throw new HttpError(422, t('@if needs “:” after the condition (e.g. @if affinity >= 2: Ana: Hi).'));
                $tree = self::condition(substr($rest, 0, $at));
                $then = trim(substr($rest, $at + 1));
                if ($then === '') throw new HttpError(422, t('The step after “:” in the @if is missing.'));
                $step = self::parseLine($then, $known, true);
                if ($step === null || $step['t'] === 'note') throw new HttpError(422, t('After the @if comes a line, a narration or a command.'));
                return $step + ['if'=>self::source($tree), 'cond'=>$tree];
            }
            $args = $rest === '' ? [] : array_map('trim', explode(',', $rest));
            switch ($command) {
                case 'bg': case 'fundo': case 'cenario': case 'cenário': {
                    if (!$args || $args[0] === '') throw new HttpError(422, t('@bg needs the place (e.g. @bg Central Café, night).'));
                    $step = ['t'=>'bg', 'place'=>mb_substr($args[0], 0, 80), 'variant'=>'', 'with'=>''];
                    foreach (array_slice($args, 1) as $a) {
                        if ($a === '') continue;
                        if (($w = self::word($a, self::TRANSITION_WORDS)) !== null) $step['with'] = $w;
                        elseif ($step['variant'] === '') $step['variant'] = mb_substr($a, 0, 40);
                        else throw new HttpError(422, t('@bg has a place, a variant and a transition; “{rest}” is left over.', ['rest'=>$a]));
                    }
                    return $step;
                }
                case 'show': case 'mostrar': case 'mostra': {
                    if (!$args || $args[0] === '') throw new HttpError(422, t('@show needs the character (e.g. @show Ana, happy, left).'));
                    [$who, $attrs] = self::splitName($args[0], $known);
                    $step = ['t'=>'show', 'who'=>$who, 'attrs'=>$attrs, 'at'=>'', 'with'=>''];
                    $words = [];
                    foreach (array_slice($args, 1) as $a) {
                        if ($a === '') continue;
                        if (($w = self::word($a, self::POSITION_WORDS)) !== null) $step['at'] = $w;
                        elseif (($w = self::word($a, self::TRANSITION_WORDS)) !== null) $step['with'] = $w;
                        else $words[] = $a;
                    }
                    if ($words) $step['attrs'] = trim($step['attrs'].' '.implode(' ', $words));
                    // A position or a transition written without a comma, at the end of the attributes.
                    $parts = preg_split('/\s+/u', $step['attrs'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    while ($parts && ((self::word(end($parts), self::POSITION_WORDS) !== null && $step['at'] === '') || (self::word(end($parts), self::TRANSITION_WORDS) !== null && $step['with'] === ''))) {
                        $last = array_pop($parts);
                        if (($w = self::word($last, self::POSITION_WORDS)) !== null && $step['at'] === '') $step['at'] = $w; else $step['with'] = (string)self::word($last, self::TRANSITION_WORDS);
                    }
                    $step['attrs'] = self::attrs(implode(' ', $parts));
                    return $step;
                }
                case 'hide': case 'esconder': case 'esconde': {
                    if (!$args || $args[0] === '') throw new HttpError(422, t('@hide needs the character.'));
                    $step = ['t'=>'hide', 'who'=>mb_substr($args[0], 0, 80), 'with'=>''];
                    if (isset($args[1]) && $args[1] !== '') {
                        $w = self::word($args[1], self::TRANSITION_WORDS);
                        if ($w === null) throw new HttpError(422, t('Unknown transition: “{name}” (use none, fade, dissolve, flash or slow).', ['name'=>$args[1]]));
                        $step['with'] = $w;
                    }
                    return $step;
                }
                case 'cg': {
                    if (!$args || $args[0] === '') throw new HttpError(422, t('@cg needs the CG\'s name.'));
                    $step = ['t'=>'cg', 'name'=>self::slug($args[0]), 'with'=>''];
                    if (isset($args[1]) && $args[1] !== '') {
                        $w = self::word($args[1], self::TRANSITION_WORDS);
                        if ($w === null) throw new HttpError(422, t('Unknown transition: “{name}”.', ['name'=>$args[1]]));
                        $step['with'] = $w;
                    }
                    return $step;
                }
                case 'music': case 'musica': case 'música': case 'trilha': {
                    if (!$args || $args[0] === '') throw new HttpError(422, t('@music needs the track (or stop).'));
                    $stop = in_array(mb_strtolower($args[0]), ['stop', 'parar', 'para', 'off', 'silencio', 'silêncio'], true);
                    $fade = 0.0;
                    if (isset($args[1]) && $args[1] !== '') {
                        if (!preg_match('/\A(?:fade\s*)?(\d+(?:[.,]\d+)?)\s*s?\z/i', $args[1], $f)) throw new HttpError(422, t('After the track comes the fade in seconds (e.g. @music theme, fade 2).'));
                        $fade = min(30.0, (float)str_replace(',', '.', $f[1]));
                    }
                    return ['t'=>'music', 'track'=>$stop ? null : self::slug($args[0]), 'fade'=>$fade];
                }
                case 'sound': case 'som': case 'sfx': {
                    if (!$args || $args[0] === '') throw new HttpError(422, t('@sound needs the sound\'s name.'));
                    return ['t'=>'sound', 'track'=>self::slug($args[0])];
                }
                case 'set': case 'definir': {
                    if ($rest === '') throw new HttpError(422, t('@set needs “variable = value”.'));
                    $set = self::assignment($rest);
                    return ['t'=>'set', 'var'=>$set['var'], 'op'=>$set['op'], 'value'=>$set['value'], 'text'=>self::assignmentText($set)];
                }
                case 'pause': case 'pausa': case 'wait': case 'espera': {
                    $seconds = $rest === '' ? 1.0 : (preg_match('/\A(\d+(?:[.,]\d+)?)\s*s?\z/i', $rest, $f) ? (float)str_replace(',', '.', $f[1]) : throw new HttpError(422, t('@pause takes the seconds (e.g. @pause 1.5).')));
                    return ['t'=>'pause', 'seconds'=>min(60.0, $seconds)];
                }
                case 'effect': case 'efeito': {
                    $w = self::word($rest, self::EFFECT_WORDS);
                    if ($w === null) throw new HttpError(422, t('Unknown effect: use shake or flash.'));
                    return ['t'=>'effect', 'name'=>$w];
                }
            }
            throw new HttpError(422, t('Unknown command: @{command}. The commands are @bg, @show, @hide, @cg, @music, @sound, @set, @if, @pause and @effect.', ['command'=>$command]));
        }
        // Dialogue: "Name: text" or "Name (expression): text", with a short name.
        if (preg_match('/\A([^:#@>"\(\)\[\]{}]{1,40}?|\?\?\?)\s*(?:\(([^()]{1,60})\))?\s*:\s+(\S.*)\z/su', $line, $m)) {
            $who = trim($m[1]);
            $words = preg_split('/\s+/u', $who, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($who !== '' && count($words) <= 4 && !preg_match('/\d{1,2}h|\d:\d/u', $who))
                return ['t'=>'say', 'who'=>$who, 'attrs'=>self::attrs($m[2] ?? ''), 'text'=>trim($m[3])];
        }
        return ['t'=>'say', 'who'=>null, 'attrs'=>'', 'text'=>$line];
    }

    /** "Ana Clara feliz" → ["Ana Clara", "feliz"] when Ana Clara is a known character; else the whole text is the name. */
    private static function splitName(string $text, array $known): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($known === [] || isset($known[mb_strtolower($text)])) return [mb_substr($text, 0, 80), ''];
        $words = explode(' ', $text);
        for ($n = count($words) - 1; $n >= 1; $n--) {
            $name = implode(' ', array_slice($words, 0, $n));
            if (isset($known[mb_strtolower($name)])) return [$known[mb_strtolower($name)], implode(' ', array_slice($words, $n))];
        }
        return [mb_substr($text, 0, 80), ''];
    }

    /** Sprite attributes (an expression, maybe with an outfit), as they are matched: lowercase words. */
    public static function attrs(string $text): string
    { return mb_substr(trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? ''), 0, 60); }

    /** The words read by the player in a scene (dialogue and narration). */
    public static function scriptWords(array $steps): int
    {
        $n = 0;
        foreach ($steps as $s) if ($s['t'] === 'say') $n += preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $s['text']);
        return $n;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Settings, variables, routes, audio and the gameplay notes
    // -----------------------------------------------------------------------------------------------------------------

    /** The project's settings and lists, complete. */
    public function settings(string $storyId, string $key): array
    {
        $row = $this->project($storyId, $key);
        $s = self::open($row['vn'] ?? null, $key, 'story-vn:'.$storyId);
        $s = (is_array($s) ? $s : []) + ['rev'=>0, 'by'=>null, 'at'=>null, 'settings'=>[], 'variables'=>[], 'routes'=>[], 'audio'=>[], 'gameplay'=>[]];
        $s['settings'] = (is_array($s['settings']) ? $s['settings'] : []) + self::DEFAULTS;
        $s['gameplay'] = (is_array($s['gameplay']) ? $s['gameplay'] : []) + ['text'=>'', 'rev'=>0, 'by'=>null, 'at'=>null];
        return $s;
    }

    private function saveSettings(string $storyId, array $s, string $key): void
    {
        $this->vault->db->prepare('UPDATE stories SET vn=CAST(? AS BLOB) WHERE id=? AND user_id=?')->execute([self::seal($s, $key, "story-vn:$storyId"), $storyId, $this->owner()]);
        $this->stories->touch($storyId);
    }

    /**
     * Changes the settings (name, aspect, framing, start) and the lists: variables, routes and audio cues, each with
     * set (add or change, by name) and remove (names). A route or variable renamed (new_name) is renamed everywhere.
     */
    public function setSettings(string $storyId, array $change, string $by, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $change, $by, $key) {
            $s = $this->settings($storyId, $key);
            $set = &$s['settings'];
            if (isset($change['name'])) $set['name'] = Stories::cleanLine($change['name'], 120, t('The game\'s name'));
            if (isset($change['aspect'])) { if (!in_array($change['aspect'], self::ASPECTS, true)) throw new HttpError(422, t('Invalid format: use {values}.', ['values'=>implode(', ', self::ASPECTS)])); $set['aspect'] = $change['aspect']; }
            if (isset($change['framing'])) { if (!in_array($change['framing'], self::FRAMINGS, true)) throw new HttpError(422, t('Invalid sprite framing: use full, knees or waist.')); $set['framing'] = $change['framing']; }
            if (array_key_exists('start', $change) && $change['start'] !== null) $set['start'] = $change['start'] === '' ? '' : self::label($change['start'], t('The starting scene'));
            unset($set);
            $renamed = ['variables'=>[], 'routes'=>[]];
            foreach (['variables', 'routes', 'audio'] as $list) {
                $c = $change[$list] ?? null;
                if ($c === null) continue;
                if (!is_array($c)) throw new HttpError(422, "$list: use {set: [...], remove: [...]}.");
                foreach (is_array($c['remove'] ?? null) ? $c['remove'] : [] as $name) {
                    $at = self::findNamed($s[$list], (string)$name);
                    if ($at === null) throw new HttpError(422, t('There is no “{name}” in {list}.', ['name'=>$name, 'list'=>self::listLabel($list)]));
                    array_splice($s[$list], $at, 1);
                }
                foreach (is_array($c['set'] ?? null) ? $c['set'] : [] as $item) {
                    if (!is_array($item)) throw new HttpError(422, t('Invalid item in {list}.', ['list'=>self::listLabel($list)]));
                    [$s[$list], $from, $to] = self::setItem($list, $s[$list], $item);
                    if ($from !== null && $from !== $to && isset($renamed[$list])) $renamed[$list][$from] = $to;
                }
            }
            $s['rev'] = (int)$s['rev'] + 1; $s['by'] = $by; $s['at'] = time();
            $this->saveSettings($storyId, $s, $key);
            if ($renamed['variables']) $this->renameVariables($storyId, $renamed['variables'], $key);
            return $this->settings($storyId, $key);
        });
    }

    private static function listLabel(string $list): string { return t(['variables'=>'variables', 'routes'=>'routes', 'audio'=>'music and sounds'][$list], ['ctx'=>'list']); }

    /** The place of an item by its name (or id), case aside. */
    private static function findNamed(array $items, string $which): ?int
    {
        $which = trim($which); $low = mb_strtolower($which);
        foreach ($items as $n => $it) if (($it['id'] ?? null) === $which || mb_strtolower((string)$it['name']) === $low) return $n;
        return null;
    }

    /** @return array{0:array,1:?string,2:string} the list, the old name of the item changed (null for a new one) and its name */
    private static function setItem(string $list, array $items, array $in): array
    {
        $line = fn(mixed $v, int $max, string $what) => $v === null ? '' : Stories::cleanLine($v, $max, $what);
        $name = (string)($in['name'] ?? '');
        $at = self::findNamed($items, $name);
        $new = isset($in['new_name']) && $in['new_name'] !== '' ? (string)$in['new_name'] : null;
        if ($list === 'variables') {
            $item = $at !== null ? $items[$at] : ['name'=>self::variableName($name), 'type'=>'flag', 'initial'=>false, 'note'=>''];
            if ($at === null && count($items) >= self::MAX_VARIABLES) throw new HttpError(422, t('Up to {max} variables.', ['max'=>self::MAX_VARIABLES]));
            if ($new !== null) $item['name'] = self::variableName($new);
            if (isset($in['type'])) { if (!in_array($in['type'], self::VAR_TYPES, true)) throw new HttpError(422, t('The variable\'s type is flag, number or text.')); $item['type'] = $in['type']; }
            $initial = array_key_exists('initial', $in) ? $in['initial'] : ($item['type'] === 'flag' ? (bool)$item['initial'] : $item['initial']);
            $item['initial'] = match ($item['type']) {
                'flag'=>is_bool($initial) ? $initial : in_array(mb_strtolower((string)$initial), ['true', '1', 'sim', 'verdadeiro'], true),
                'number'=>is_numeric($initial) ? $initial + 0 : 0,
                default=>mb_substr(is_scalar($initial) ? (string)$initial : '', 0, 200),
            };
            if (isset($in['note'])) $item['note'] = $line($in['note'], 300, t('The note'));
        } elseif ($list === 'routes') {
            $item = $at !== null ? $items[$at] : ['id'=>self::newId(), 'name'=>'', 'color'=>self::routeColor(count($items)), 'note'=>''];
            if ($at === null) {
                if (count($items) >= self::MAX_ROUTES) throw new HttpError(422, t('Up to {max} routes.', ['max'=>self::MAX_ROUTES]));
                $item['name'] = mb_substr($line($name, 60, t('The route\'s name')), 0, 60);
                if ($item['name'] === '') throw new HttpError(422, t('The route needs a name.'));
            }
            if ($new !== null) $item['name'] = mb_substr($line($new, 60, t('The route\'s name')), 0, 60);
            if (isset($in['color'])) { if (!preg_match('/\A#[0-9a-fA-F]{6}\z/D', (string)$in['color'])) throw new HttpError(422, t('The route\'s color is #rrggbb.')); $item['color'] = strtolower($in['color']); }
            if (isset($in['note'])) $item['note'] = $line($in['note'], 300, t('The note'));
        } else {
            $item = $at !== null ? $items[$at] : ['name'=>self::slug($name), 'kind'=>'music', 'note'=>''];
            if ($at === null && count($items) >= self::MAX_AUDIO) throw new HttpError(422, t('Up to {max} music tracks and sounds.', ['max'=>self::MAX_AUDIO]));
            if ($at === null && trim($name) === '') throw new HttpError(422, t('The track needs a name.'));
            if ($new !== null) $item['name'] = self::slug($new);
            if (isset($in['kind'])) { if (!in_array($in['kind'], self::AUDIO_KINDS, true)) throw new HttpError(422, t('A track is music or sound.')); $item['kind'] = $in['kind']; }
            if (isset($in['note'])) $item['note'] = $line($in['note'], 300, t('The description'));
        }
        $other = self::findNamed($items, (string)$item['name']);
        if ($other !== null && $other !== $at) throw new HttpError(422, t('There is already “{name}” in {list}.', ['name'=>$items[$other]['name'], 'list'=>self::listLabel($list)]));
        $old = $at !== null ? (string)$items[$at]['name'] : null;
        if ($at !== null) $items[$at] = $item; else $items[] = $item;
        return [$items, $list === 'routes' ? ($at !== null ? $old : null) : $old, (string)$item['name']];
    }

    private static function routeColor(int $n): string
    { return ['#e0766e', '#6aa6e8', '#8ccf7e', '#e8c26a', '#b48ee6', '#5fc9c0', '#e88fc0', '#c9a27a'][$n % 8]; }

    /** A variable renamed: its name changes in every script and exit of the project. */
    private function renameVariables(string $storyId, array $map, string $key): void
    {
        foreach ($this->sceneRows($storyId, $key) as $sc) {
            $script = $sc['script']; $exit = $sc['exit'];
            foreach ($map as $from => $to) {
                $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($from, '/').'(?![A-Za-z0-9_])/';
                // Only in the lines that use variables: commands and conditions, not dialogue.
                $script = implode("\n", array_map(fn($l) => str_starts_with(ltrim($l), '@') ? (preg_replace($pattern, $to, $l) ?? $l) : $l, explode("\n", $script)));
                $exit = json_decode(preg_replace($pattern, $to, json_encode($exit, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?? '', true) ?: $exit;
            }
            if ($script !== $sc['script'] || $exit !== $sc['exit']) $this->saveScene(['script'=>$script, 'exit'=>$exit] + $sc, $key, false);
        }
    }

    /** Changes the gameplay notes, as the bible changes: whole text, appended text or exact replacements. */
    public function writeGameplay(string $storyId, array $change, string $by, string $key, ?int $expect = null): array
    {
        return $this->stories->transaction(function () use ($storyId, $change, $by, $key, $expect) {
            $s = $this->settings($storyId, $key); $doc = $s['gameplay'];
            if ($expect !== null && $expect !== (int)$doc['rev']) throw new HttpError(409, t('The gameplay notes changed while you were editing (the writer may have updated them). Reload to see the new version.'));
            $text = $doc['text'];
            if (isset($change['text'])) $text = Stories::cleanText($change['text'], Stories::MAX_TEXT, t('The gameplay notes'));
            if (!empty($change['edits'])) $text = Stories::applyEdits($text, $change['edits'], t('The gameplay notes'));
            if (isset($change['append']) && $change['append'] !== '') $text = rtrim($text)."\n\n".trim(Stories::cleanText($change['append'], Stories::MAX_TEXT, t('The gameplay notes')));
            $text = trim($text);
            if (mb_strlen($text) > Stories::MAX_TEXT) throw new HttpError(422, t('The gameplay notes are over {max} characters.', ['max'=>I18n::number(Stories::MAX_TEXT)]));
            // Its own revision: the settings' one counts only the settings and the lists.
            $s['gameplay'] = ['text'=>$text, 'rev'=>(int)$doc['rev'] + 1, 'by'=>$by, 'at'=>time()];
            $this->saveSettings($storyId, $s, $key);
            return $s['gameplay'];
        });
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Scenes
    // -----------------------------------------------------------------------------------------------------------------

    /** @return list<array> the scenes in order, with their script and exit, each with its number */
    private function sceneRows(string $storyId, string $key): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM vn_scenes WHERE story_id=? AND user_id=? ORDER BY position, created'); $q->execute([$storyId, $this->owner()]);
        $rows = $q->fetchAll(); $q->closeCursor();
        $out = [];
        foreach ($rows as $n => $r) {
            $b = self::open($r['body'], $key, 'vn-scene:'.$r['id']);
            $b = (is_array($b) ? $b : []) + ['label'=>'', 'title'=>'', 'chapter'=>null, 'route'=>null, 'summary'=>'', 'notes'=>'', 'script'=>'', 'exit'=>['type'=>'none'], 'pos'=>null, 'by'=>null];
            $out[] = ['id'=>$r['id'], 'number'=>$n + 1, 'rev'=>(int)$r['rev'], 'position'=>(float)$r['position'], 'updated'=>(int)$r['updated']] + $b;
        }
        return $out;
    }

    public function scenes(string $storyId, string $key): array { $this->project($storyId, $key); return $this->sceneRows($storyId, $key); }

    /** A scene by its id or its label. */
    public function scene(string $storyId, string $which, string $key): array
    {
        $which = trim($which);
        foreach ($this->scenes($storyId, $key) as $sc) if ($sc['id'] === $which || $sc['label'] === $which) return $sc;
        throw new HttpError(404, t('There is no scene “{name}” in this project.', ['name'=>$which]));
    }

    private function saveScene(array $sc, string $key, bool $content): void
    {
        $body = array_intersect_key($sc, array_flip(['label', 'title', 'chapter', 'route', 'summary', 'notes', 'script', 'exit', 'pos', 'by']));
        $this->vault->db->prepare('UPDATE vn_scenes SET body=CAST(? AS BLOB), rev=?, updated=? WHERE id=? AND user_id=?')
            ->execute([self::seal($body, $key, 'vn-scene:'.$sc['id']), $sc['rev'] + ($content ? 1 : 0), time(), $sc['id'], $this->owner()]);
    }

    /** The names of the characters with a sheet, for the script's commands written without commas. */
    private function characterNames(string $storyId, string $key): array
    {
        $out = [];
        foreach ($this->stories->cast($storyId, $key)['characters'] as $c) if ($c['kind'] === 'character') $out[] = $c['name'];
        return $out;
    }

    /**
     * A scene's fields as they are kept, from what the writer or the person gave: label, title, chapter (an id or
     * null), route (a route's name or id, or null), summary, notes, script (checked: a line that can't be read is
     * refused, with its number) and exit.
     */
    public function sceneFields(string $storyId, array $in, string $key, ?array $base = null): array
    {
        $sc = $base ?? ['label'=>'', 'title'=>'', 'chapter'=>null, 'route'=>null, 'summary'=>'', 'notes'=>'', 'script'=>'', 'exit'=>['type'=>'none'], 'pos'=>null];
        if (isset($in['title'])) $sc['title'] = Stories::cleanLine($in['title'], 200, t('The scene\'s title'));
        if (isset($in['label']) && $in['label'] !== '') $sc['label'] = self::label($in['label']);
        if ($sc['label'] === '') $sc['label'] = self::label($sc['title'] !== '' ? $sc['title'] : t('scene', ['ctx'=>'label']));
        if (array_key_exists('chapter', $in)) {
            $sc['chapter'] = null;
            if ($in['chapter'] !== null && $in['chapter'] !== '') { $c = (string)$in['chapter']; Vault::validId($c); $this->stories->chapter($storyId, $c, $key); $sc['chapter'] = $c; }
        }
        if (array_key_exists('route', $in)) {
            $sc['route'] = null;
            if ($in['route'] !== null && $in['route'] !== '') {
                $routes = $this->settings($storyId, $key)['routes'];
                $at = self::findNamed($routes, (string)$in['route']);
                if ($at === null) throw new HttpError(422, t('There is no route “{name}”. Create the route first (in Variables and game, or vn_settings).', ['name'=>$in['route']]));
                $sc['route'] = $routes[$at]['id'];
            }
        }
        if (isset($in['summary'])) $sc['summary'] = trim(Stories::cleanText($in['summary'], 3000, t('The scene\'s summary')));
        if (isset($in['notes'])) $sc['notes'] = trim(Stories::cleanText($in['notes'], 6000, t('The scene\'s gameplay notes')));
        if (isset($in['script'])) {
            $script = rtrim(Stories::cleanText($in['script'], self::MAX_SCRIPT, t('The scene\'s script')));
            $parsed = self::parse($script, $this->characterNames($storyId, $key));
            if ($parsed['errors']) throw new HttpError(422, (count($parsed['errors']) === 1 ? t('The script has 1 line that could not be read.') : t('The script has {n} lines that could not be read.', ['n'=>count($parsed['errors'])])).' '
                .implode(' ', array_map(fn($e) => t('Line {n}: {error}', ['n'=>$e['line'], 'error'=>$e['message']]), array_slice($parsed['errors'], 0, 8))));
            $sc['script'] = $script;
        }
        if (isset($in['exit'])) $sc['exit'] = self::exit($in['exit']);
        if (array_key_exists('pos', $in)) $sc['pos'] = self::pos($in['pos']);
        return $sc;
    }

    private static function pos(mixed $pos): ?array
    {
        if (!is_array($pos) || !is_numeric($pos['x'] ?? null) || !is_numeric($pos['y'] ?? null)) return null;
        return ['x'=>(int)max(-100000, min(100000, $pos['x'])), 'y'=>(int)max(-100000, min(100000, $pos['y']))];
    }

    /** An exit as it is kept: the conditions and changes checked, and the scenes it leads to by label. */
    public static function exit(mixed $in): array
    {
        if (!is_array($in)) throw new HttpError(422, t('The scene\'s exit must be an object with type.'));
        $type = $in['type'] ?? 'none';
        if (!in_array($type, self::EXIT_TYPES, true)) throw new HttpError(422, t('The exit\'s type is none, jump, choice, branch or end.'));
        $to = fn(mixed $v, string $what) => self::label($v, $what);
        $condition = function (mixed $v): string { if ($v === null || trim((string)$v) === '') return ''; return self::source(self::condition((string)$v)); };
        switch ($type) {
            case 'none': return ['type'=>'none'];
            case 'jump': return ['type'=>'jump', 'to'=>$to($in['to'] ?? null, t('The destination scene'))];
            case 'end': {
                $kind = $in['kind'] ?? 'normal';
                if (!in_array($kind, self::ENDING_KINDS, true)) throw new HttpError(422, t('The ending\'s kind is good, normal, bad, true or secret.'));
                $title = Stories::cleanLine($in['title'] ?? '', 120, t('The ending\'s name'));
                return ['type'=>'end', 'title'=>$title !== '' ? $title : 'Fim', 'kind'=>$kind];
            }
            case 'choice': {
                $options = $in['options'] ?? null;
                if (!is_array($options) || !array_is_list($options) || !$options) throw new HttpError(422, t('A choice needs options.'));
                if (count($options) > self::MAX_OPTIONS) throw new HttpError(422, t('A choice can have up to {max} options.', ['max'=>self::MAX_OPTIONS]));
                $out = [];
                foreach ($options as $n => $o) {
                    if (!is_array($o)) throw new HttpError(422, t('Option {n}: must be an object.', ['n'=>($n + 1)]));
                    $text = Stories::cleanLine($o['text'] ?? '', 300, t('The option\'s text'));
                    if ($text === '') throw new HttpError(422, t('Option {n}: the text the player reads is missing.', ['n'=>($n + 1)]));
                    $sets = [];
                    foreach (is_array($o['set'] ?? null) ? $o['set'] : (is_string($o['set'] ?? null) && trim($o['set']) !== '' ? explode(';', $o['set']) : []) as $s) {
                        if (trim((string)$s) === '') continue;
                        try { $sets[] = self::assignmentText(self::assignment((string)$s)); }
                        catch (HttpError $e) { throw new HttpError(422, t('Option {n}: {error}', ['n'=>($n + 1), 'error'=>$e->getMessage()])); }
                    }
                    try { $if = $condition($o['if'] ?? null); } catch (HttpError $e) { throw new HttpError(422, t('Option {n}: {error}', ['n'=>($n + 1), 'error'=>$e->getMessage()])); }
                    $out[] = ['text'=>$text, 'to'=>$to($o['to'] ?? null, t('Option {n}: the destination scene', ['n'=>($n + 1)])), 'if'=>$if, 'set'=>$sets,
                        'note'=>Stories::cleanLine($o['note'] ?? '', 500, t('The option\'s note'))];
                }
                return ['type'=>'choice', 'prompt'=>Stories::cleanLine($in['prompt'] ?? '', 300, t('The choice\'s question')), 'options'=>$out];
            }
            case 'branch': {
                $cases = $in['cases'] ?? null;
                if (!is_array($cases) || !array_is_list($cases) || !$cases) throw new HttpError(422, t('A branch needs cases, each with if and to.'));
                if (count($cases) > self::MAX_OPTIONS) throw new HttpError(422, t('A branch can have up to {max} cases.', ['max'=>self::MAX_OPTIONS]));
                $out = [];
                foreach ($cases as $n => $c) {
                    if (!is_array($c)) throw new HttpError(422, t('Case {n}: must be an object.', ['n'=>($n + 1)]));
                    try { $if = $condition($c['if'] ?? null); } catch (HttpError $e) { throw new HttpError(422, 'Caso '.($n + 1).': '.$e->getMessage()); }
                    if ($if === '') throw new HttpError(422, t('Case {n}: the condition (if) is missing.', ['n'=>($n + 1)]));
                    $out[] = ['if'=>$if, 'to'=>$to($c['to'] ?? null, t('Case {n}: the destination scene', ['n'=>($n + 1)]))];
                }
                $else = isset($in['else']) && trim((string)$in['else']) !== '' ? $to($in['else'], t('The else scene')) : '';
                return ['type'=>'branch', 'cases'=>$out, 'else'=>$else];
            }
        }
        return ['type'=>'none'];
    }

    /** A new scene at $index (0 = first; null = last). Its label must be unused. */
    public function addScene(string $storyId, array $in, ?int $index, string $by, string $key): array
    {
        $this->project($storyId, $key);
        $fields = $this->sceneFields($storyId, $in, $key);
        $id = self::newId();
        $this->stories->transaction(function () use ($storyId, $fields, $index, $by, $key, $id, $in) {
            $rows = $this->sceneRows($storyId, $key);
            if (count($rows) >= self::MAX_SCENES) throw new HttpError(422, t('A project can have up to {max} scenes.', ['max'=>self::MAX_SCENES]));
            // A label made from the title gets a number when taken; one given must be free.
            $labels = array_column($rows, 'label');
            if (in_array($fields['label'], $labels, true)) {
                if (isset($in['label']) && $in['label'] !== '') throw new HttpError(422, t('There is already a scene with the label “{label}”. Choose another.', ['label'=>$fields['label']]));
                for ($n = 2; in_array($fields['label'].'_'.$n, $labels, true); $n++);
                $fields['label'] .= '_'.$n;
            }
            $index = $index === null ? count($rows) : max(0, min(count($rows), $index));
            $before = $index > 0 ? $rows[$index - 1]['position'] : null; $after = $rows[$index]['position'] ?? null;
            $position = $before === null && $after === null ? 1.0 : ($before === null ? $after - 1 : ($after === null ? $before + 1 : ($before + $after) / 2));
            $now = time();
            $this->vault->db->prepare('INSERT INTO vn_scenes(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),1,?,?)')
                ->execute([$id, $storyId, $this->owner(), $position, self::seal($fields + ['by'=>$by], $key, "vn-scene:$id"), $now, $now]);
            // The first scene is where the game starts, until another one is chosen.
            $s = $this->settings($storyId, $key);
            if ($s['settings']['start'] === '' || !in_array($s['settings']['start'], $labels, true)) {
                if ($s['settings']['start'] === '' || !$rows) { $s['settings']['start'] = $fields['label']; $this->saveSettingsQuiet($storyId, $s, $key); }
            }
            $this->stories->touch($storyId);
        });
        return $this->scene($storyId, $id, $key);
    }

    /** The settings saved without counting as a change of the person or the writer (the first scene becoming the start). */
    private function saveSettingsQuiet(string $storyId, array $s, string $key): void
    { $this->vault->db->prepare('UPDATE stories SET vn=CAST(? AS BLOB) WHERE id=? AND user_id=?')->execute([self::seal($s, $key, "story-vn:$storyId"), $storyId, $this->owner()]); }

    /**
     * Changes a scene (the fields given). script_edits: exact replacements in the script; append_script: lines added at
     * its end. A new label is changed in every exit that led to the old one, and in the start. $expect: the revision
     * the person started from (409 when it changed meanwhile).
     */
    public function updateScene(string $storyId, string $which, array $in, string $by, string $key, ?int $expect = null): array
    {
        $id = $this->stories->transaction(function () use ($storyId, $which, $in, $by, $key, $expect) {
            $sc = $this->scene($storyId, $which, $key);
            if ($expect !== null && $expect !== $sc['rev']) throw new HttpError(409, t('This scene changed while you were editing (the writer may have changed it). Reload to see the new version; your text is still in the box.'));
            if (!empty($in['script_edits'])) $in['script'] = Stories::applyEdits($in['script'] ?? $sc['script'], $in['script_edits'], t('The scene\'s script'));
            if (isset($in['append_script']) && trim((string)$in['append_script']) !== '') $in['script'] = rtrim($in['script'] ?? $sc['script'])."\n".trim(Stories::cleanText($in['append_script'], self::MAX_SCRIPT, t('The scene\'s script')));
            $fields = $this->sceneFields($storyId, $in, $key, array_intersect_key($sc, array_flip(['label', 'title', 'chapter', 'route', 'summary', 'notes', 'script', 'exit', 'pos'])));
            $old = $sc['label'];
            if ($fields['label'] !== $old) {
                foreach ($this->sceneRows($storyId, $key) as $other) if ($other['id'] !== $sc['id'] && $other['label'] === $fields['label']) throw new HttpError(422, t('There is already a scene with the label “{label}”.', ['label'=>$fields['label']]));
            }
            // Only the place on the tree: not a change of the scene's content.
            $content = array_diff_key($in, ['pos'=>1]) !== [];
            $this->saveScene(array_merge($sc, $fields, $content ? ['by'=>$by] : []), $key, $content);
            if ($fields['label'] !== $old) $this->relabel($storyId, $old, $fields['label'], $key);
            $this->stories->touch($storyId);
            return $sc['id'];
        });
        return $this->scene($storyId, $id, $key);
    }

    /** Every exit that led to $from (and the start) leads to $to. */
    private function relabel(string $storyId, string $from, string $to, string $key): void
    {
        $swap = fn(string $l) => $l === $from ? $to : $l;
        foreach ($this->sceneRows($storyId, $key) as $sc) {
            $e = $sc['exit'];
            if (isset($e['to'])) $e['to'] = $swap($e['to']);
            if (isset($e['else']) && $e['else'] !== '') $e['else'] = $swap($e['else']);
            foreach (['options', 'cases'] as $list) if (isset($e[$list])) foreach ($e[$list] as &$o) $o['to'] = $swap($o['to']);
            unset($o);
            if ($e !== $sc['exit']) $this->saveScene(['exit'=>$e] + $sc, $key, false);
        }
        $s = $this->settings($storyId, $key);
        if ($s['settings']['start'] === $from) { $s['settings']['start'] = $to; $this->saveSettingsQuiet($storyId, $s, $key); }
    }

    /** Places on the tree, many at once (the person dragged nodes or arranged them): not a change of content. */
    public function setPositions(string $storyId, mixed $positions, string $key): void
    {
        if (!is_array($positions)) throw new HttpError(422, t('Invalid positions.'));
        $this->stories->transaction(function () use ($storyId, $positions, $key) {
            foreach ($this->scenes($storyId, $key) as $sc) {
                if (!array_key_exists($sc['id'], $positions)) continue;
                $pos = $positions[$sc['id']] === null ? null : self::pos($positions[$sc['id']]);
                if ($pos !== $sc['pos']) $this->saveScene(['pos'=>$pos] + $sc, $key, false);
            }
        });
    }

    public function deleteScene(string $storyId, string $which, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $which, $key) {
            $sc = $this->scene($storyId, $which, $key);
            $this->vault->db->prepare('DELETE FROM vn_scenes WHERE id=? AND story_id=? AND user_id=?')->execute([$sc['id'], $storyId, $this->owner()]);
            $this->stories->touch($storyId);
            return $sc;
        });
    }

    /** Moves a scene to $index (0 = first) among the others. */
    public function moveScene(string $storyId, string $which, int $index, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $which, $index, $key) {
            $sc = $this->scene($storyId, $which, $key);
            $rest = array_values(array_filter($this->sceneRows($storyId, $key), fn($r) => $r['id'] !== $sc['id']));
            $index = max(0, min(count($rest), $index));
            $before = $index > 0 ? $rest[$index - 1]['position'] : null; $after = $rest[$index]['position'] ?? null;
            $position = $before === null && $after === null ? 1.0 : ($before === null ? $after - 1 : ($after === null ? $before + 1 : ($before + $after) / 2));
            $this->vault->db->prepare('UPDATE vn_scenes SET position=? WHERE id=? AND user_id=?')->execute([$position, $sc['id'], $this->owner()]);
            $this->stories->touch($storyId);
        });
    }

    /** The scenes an exit leads to, in order, without repeats. */
    public static function targets(array $exit): array
    {
        $out = [];
        if (isset($exit['to'])) $out[] = $exit['to'];
        foreach (['options', 'cases'] as $list) foreach ($exit[$list] ?? [] as $o) $out[] = $o['to'];
        if (($exit['else'] ?? '') !== '') $out[] = $exit['else'];
        return array_values(array_unique($out));
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The art: sprites, backgrounds and CGs
    // -----------------------------------------------------------------------------------------------------------------

    public function art(string $storyId, string $key): array
    {
        $row = $this->project($storyId, $key);
        $a = self::open($row['vn_art'] ?? null, $key, 'story-vn-art:'.$storyId);
        return (is_array($a) ? $a : []) + ['rev'=>0, 'by'=>null, 'at'=>null, 'sprites'=>[], 'backgrounds'=>[], 'cgs'=>[]];
    }

    private function saveArt(string $storyId, array $a, string $key, ?string $by): void
    {
        if ($by !== null) { $a['rev'] = (int)$a['rev'] + 1; $a['by'] = $by; $a['at'] = time(); }
        $this->vault->db->prepare('UPDATE stories SET vn_art=CAST(? AS BLOB) WHERE id=? AND user_id=?')->execute([self::seal($a, $key, "story-vn-art:$storyId"), $storyId, $this->owner()]);
        $this->stories->touch($storyId);
    }

    /** The list of the art each kind is kept in. */
    public const ART_LISTS = ['sprite'=>'sprites', 'background'=>'backgrounds', 'cg'=>'cgs'];
    private const LISTS = self::ART_LISTS;

    /** The character sheet with this name (or id), or 422. */
    public function character(string $storyId, string $which, string $key): array
    {
        $cast = $this->stories->cast($storyId, $key)['characters'];
        $n = Stories::findCharacter($cast, $which);
        if ($n === null || $cast[$n]['kind'] !== 'character') throw new HttpError(422, t('There is no character sheet of “{name}”. Create the sheet first (Sheets and style, or character_sheet / update_character).', ['name'=>$which]));
        return $cast[$n];
    }

    /** The place sheet with this name (or id), or null. */
    public function place(string $storyId, string $which, string $key): ?array
    {
        $cast = $this->stories->cast($storyId, $key)['characters'];
        $n = Stories::findCharacter($cast, $which);
        return $n !== null && $cast[$n]['kind'] === 'place' ? $cast[$n] : null;
    }

    /**
     * An entry of the art, found by what names it: a sprite by character, outfit and expression; a background by place
     * and variant; a CG by its name. Returns [list, place in it] (place null when there is none).
     */
    public function findArt(array $art, string $kind, array $in, string $storyId, string $key): array
    {
        $list = self::LISTS[$kind] ?? throw new HttpError(422, t('The art is sprite, background or cg.'));
        if (isset($in['id']) && is_string($in['id']) && $in['id'] !== '') {
            foreach ($art[$list] as $n => $a) if ($a['id'] === $in['id']) return [$list, $n];
            throw new HttpError(404, t('That art is not in the project.'));
        }
        foreach ($art[$list] as $n => $a) {
            if ($kind === 'sprite' && $a['character'] === $in['character'] && $a['outfit'] === $in['outfit'] && $a['expression'] === $in['expression']) return [$list, $n];
            if ($kind === 'background' && self::placeKey($a) === self::placeKey($in) && $a['variant'] === $in['variant']) return [$list, $n];
            if ($kind === 'cg' && $a['name'] === $in['name']) return [$list, $n];
        }
        return [$list, null];
    }

    private static function placeKey(array $a): string { return is_string($a['place'] ?? null) && $a['place'] !== '' ? $a['place'] : 'name:'.self::slug((string)($a['name'] ?? '')); }

    /**
     * What an art entry is, from a tool's or the page's input: a sprite {character, outfit, expression}, a background
     * {place, variant} (a place without a sheet keeps its name), a CG {name, title}.
     */
    public function artKey(string $storyId, string $kind, array $in, string $key): array
    {
        if ($kind === 'sprite') {
            $c = $this->character($storyId, (string)($in['character'] ?? ''), $key);
            $expression = self::attrs((string)($in['expression'] ?? ''));
            if ($expression === '') throw new HttpError(422, t('Say the sprite\'s expression (e.g. neutral, happy, angry, sad).'));
            if (str_contains($expression, ' ')) throw new HttpError(422, t('The expression is one word (e.g. happy, surprised); the clothes go in outfit.'));
            $outfit = self::attrs((string)($in['outfit'] ?? ''));
            if (str_contains($outfit, ' ')) throw new HttpError(422, t('The outfit is one word (e.g. uniform, pajamas).'));
            return ['character'=>$c['id'], 'outfit'=>$outfit, 'expression'=>$expression, 'who'=>$c['name']];
        }
        if ($kind === 'background') {
            $name = Stories::cleanLine($in['place'] ?? '', 80, t('The place'));
            if ($name === '') throw new HttpError(422, t('Say the background\'s place.'));
            $place = $this->place($storyId, $name, $key);
            return ['place'=>$place['id'] ?? null, 'name'=>$place['name'] ?? $name, 'variant'=>self::attrs((string)($in['variant'] ?? ''))];
        }
        if ($kind === 'cg') {
            $title = Stories::cleanLine($in['title'] ?? $in['name'] ?? '', 120, t('The CG\'s title'));
            $name = self::slug((string)(isset($in['name']) && $in['name'] !== '' ? $in['name'] : $title));
            if ($title === '' && $name === 'x') throw new HttpError(422, t('Give the CG a name.'));
            return ['name'=>$name, 'title'=>$title !== '' ? $title : $name];
        }
        throw new HttpError(422, t('The art is sprite, background or cg.'));
    }

    /**
     * Adds an entry to the art or changes it: its picture (a library image) or the request making it (job), the
     * prompt it was made from, a note. One with the same name is replaced (its picture becomes the new one).
     */
    public function putArt(string $storyId, string $kind, array $entry, string $by, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $kind, $entry, $by, $key) {
            $art = $this->art($storyId, $key);
            [$list, $n] = $this->findArt($art, $kind, $entry, $storyId, $key);
            $max = ['sprites'=>self::MAX_SPRITES, 'backgrounds'=>self::MAX_BACKGROUNDS, 'cgs'=>self::MAX_CGS][$list];
            if ($n === null && count($art[$list]) >= $max) throw new HttpError(422, t(['sprites'=>'Up to {max} sprites per project.', 'backgrounds'=>'Up to {max} backgrounds per project.', 'cgs'=>'Up to {max} CGs per project.'][$list], ['max'=>$max]));
            if (isset($entry['image']) && $entry['image'] !== null) { Vault::validId((string)$entry['image']); $this->vault->still((string)$entry['image'], $key); }
            if (isset($entry['job']) && $entry['job'] !== null) Vault::validId((string)$entry['job']);
            $item = ($n !== null ? $art[$list][$n] : ['id'=>self::newId(), 'image'=>null, 'job'=>null, 'prompt'=>'', 'note'=>'']);
            foreach (['character', 'outfit', 'expression', 'place', 'name', 'variant', 'title'] as $f) if (array_key_exists($f, $entry)) $item[$f] = $entry[$f];
            if (array_key_exists('image', $entry)) $item['image'] = $entry['image'];
            if (array_key_exists('job', $entry)) $item['job'] = $entry['job'];
            if (isset($entry['prompt'])) $item['prompt'] = mb_substr((string)$entry['prompt'], 0, 4000);
            if (isset($entry['note'])) $item['note'] = Stories::cleanLine($entry['note'], 500, t('The note'));
            $item['by'] = $by; $item['at'] = time();
            unset($item['who']);
            if ($n !== null) $art[$list][$n] = $item; else $art[$list][] = $item;
            $this->saveArt($storyId, $art, $key, $by);
            return $item;
        });
    }

    /** Removes an entry of the art (its picture stays in the gallery). */
    public function removeArt(string $storyId, string $kind, string $id, string $by, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $kind, $id, $by, $key) {
            $art = $this->art($storyId, $key);
            [$list, $n] = $this->findArt($art, $kind, ['id'=>$id], $storyId, $key);
            $item = $art[$list][$n];
            array_splice($art[$list], $n, 1);
            $this->saveArt($storyId, $art, $key, $by);
            return $item;
        });
    }

    /** @return array<string,array{kind:string,id:string}> the requests making art: request id => which entry */
    public function jobs(string $storyId, string $key): array
    {
        $out = [];
        try { $art = $this->art($storyId, $key); } catch (HttpError) { return []; }
        foreach (self::LISTS as $kind => $list) foreach ($art[$list] as $a) if (is_string($a['job'] ?? null)) $out[$a['job']] = ['kind'=>$kind, 'id'=>$a['id']];
        return $out;
    }

    /** A request that ended with an image: it becomes the entry's picture. */
    public function finishJob(string $storyId, string $jobId, string $imageId, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $jobId, $imageId, $key) {
            $art = $this->art($storyId, $key); $changed = false;
            foreach (self::LISTS as $list) foreach ($art[$list] as &$a) if (($a['job'] ?? null) === $jobId) { $a['image'] = $imageId; $a['job'] = null; $changed = true; }
            unset($a);
            if ($changed) $this->saveArt($storyId, $art, $key, null);
        });
    }

    /** A request that failed, made again ($to) or set aside ($to null: the entry keeps the picture it had, or goes when it had none). */
    public function retryJob(string $storyId, string $from, ?string $to, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $from, $to, $key) {
            $art = $this->art($storyId, $key); $found = false;
            foreach (self::LISTS as $list) foreach ($art[$list] as $n => $a) if (($a['job'] ?? null) === $from) {
                $found = true;
                if ($to !== null) $art[$list][$n]['job'] = $to;
                elseif ($a['image'] !== null) $art[$list][$n]['job'] = null;
                else unset($art[$list][$n]);
            }
            if (!$found) throw new HttpError(404, t('That art is no longer in the project.'));
            foreach (self::LISTS as $list) $art[$list] = array_values($art[$list]);
            $this->saveArt($storyId, $art, $key, null);
        });
    }

    /** A new version of a picture (an edit, a larger one) takes the old one's place in the art. */
    public function replaceImage(string $storyId, string $from, string $to, string $key): bool
    {
        return $this->stories->transaction(function () use ($storyId, $from, $to, $key) {
            try { $art = $this->art($storyId, $key); } catch (HttpError) { return false; }
            $changed = false;
            foreach (self::LISTS as $list) foreach ($art[$list] as &$a) if (($a['image'] ?? null) === $from) { $a['image'] = $to; $changed = true; }
            unset($a);
            if ($changed) $this->saveArt($storyId, $art, $key, null);
            return $changed;
        });
    }

    /** Where a picture is in the art, in words for the writer ("the sprite of Ana (happy)"). */
    public function artWith(string $storyId, string $imageId, string $key): array
    {
        try { $art = $this->art($storyId, $key); } catch (HttpError) { return []; }
        $names = [];
        foreach ($this->stories->cast($storyId, $key)['characters'] as $c) $names[$c['id']] = $c['name'];
        $out = [];
        foreach ($art['sprites'] as $a) if ($a['image'] === $imageId) $out[] = 'the sprite of '.($names[$a['character']] ?? '?').' ('.trim($a['outfit'].' '.$a['expression']).')';
        foreach ($art['backgrounds'] as $a) if ($a['image'] === $imageId) $out[] = 'the background '.$a['name'].($a['variant'] !== '' ? ' ('.$a['variant'].')' : '');
        foreach ($art['cgs'] as $a) if ($a['image'] === $imageId) $out[] = 'the CG '.$a['name'];
        return $out;
    }

    /** The sprite that shows a character with these attributes ("feliz", "uniforme feliz"), or null. */
    public static function spriteFor(array $sprites, string $characterId, string $attrs, string $outfit = ''): ?array
    {
        $mine = array_values(array_filter($sprites, fn($s) => $s['character'] === $characterId));
        if (!$mine) return null;
        $words = preg_split('/\s+/u', self::attrs($attrs), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$words) {
            foreach ($mine as $s) if ($s['outfit'] === $outfit) return $s;
            return $mine[0];
        }
        $want = $words; sort($want);
        foreach ($mine as $s) { $has = array_values(array_filter([$s['outfit'], $s['expression']], fn($w) => $w !== '')); sort($has); if ($has === $want) return $s; }
        if (count($words) === 1) {
            foreach ($mine as $s) if ($s['expression'] === $words[0] && $s['outfit'] === $outfit) return $s;
            foreach ($mine as $s) if ($s['expression'] === $words[0]) return $s;
            foreach ($mine as $s) if ($s['outfit'] === $words[0]) return $s;
        }
        return null;
    }

    /** The background of a place in a variant ('' takes the first one of the place), or null. */
    public static function backgroundFor(array $backgrounds, ?string $placeId, string $name, string $variant): ?array
    {
        $variant = self::attrs($variant);
        $mine = array_values(array_filter($backgrounds, fn($b) => ($placeId !== null && $b['place'] === $placeId) || self::slug((string)$b['name']) === self::slug($name)));
        if (!$mine) return null;
        foreach ($mine as $b) if ($b['variant'] === $variant) return $b;
        return $variant === '' ? $mine[0] : null;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The person's documents, brought into the project
    // -----------------------------------------------------------------------------------------------------------------

    public const IMPORT_TARGETS = ['notes', 'plan', 'gameplay', 'chapter', 'character', 'place'];
    public const MAX_IMPORT = 60;

    /**
     * Documents the person already has (a design document, chapter bases, character documents), each put where it
     * belongs, in one go: the design document (notes), the plan or the gameplay notes get it at their end under its
     * title; a chapter base becomes a chapter; a character's or place's document becomes their sheet (with its first
     * lines as the note) and goes whole into the design document under their name, where the writer and the person read it.
     * @return array{notes:int,plan:int,gameplay:int,chapters:int,characters:int,places:int}
     */
    public function importDocuments(string $storyId, mixed $items, string $by, string $key): array
    {
        $this->project($storyId, $key);
        if (!is_array($items) || !array_is_list($items) || !$items) throw new HttpError(422, t('Nothing to import.'));
        if (count($items) > self::MAX_IMPORT) throw new HttpError(422, t('Up to {max} documents at a time.', ['max'=>self::MAX_IMPORT]));
        $docs = [];
        foreach ($items as $n => $it) {
            if (!is_array($it) || !in_array($it['to'] ?? null, self::IMPORT_TARGETS, true)) throw new HttpError(422, t('Document {n}: choose where it goes.', ['n'=>($n + 1)]));
            $title = Stories::cleanLine($it['title'] ?? '', 200, t('The title of document {n}', ['n'=>($n + 1)]));
            $text = trim(Stories::cleanText($it['text'] ?? '', Stories::MAX_TEXT, t('Document {n}', ['ctx'=>'subject', 'n'=>($n + 1)])));
            if ($text === '' && !in_array($it['to'], ['character', 'place'], true)) continue;
            if (in_array($it['to'], ['chapter', 'character', 'place'], true) && $title === '') throw new HttpError(422, t(['chapter'=>'Document {n}: give the chapter\'s title.', 'place'=>'Document {n}: give the place\'s name.', 'character'=>'Document {n}: give the character\'s name.'][$it['to']], ['n'=>$n + 1]));
            $docs[] = ['to'=>$it['to'], 'title'=>$title, 'text'=>$text];
        }
        $count = ['notes'=>0, 'plan'=>0, 'gameplay'=>0, 'chapters'=>0, 'characters'=>0, 'places'=>0];
        $this->stories->transaction(function () use ($storyId, $docs, $by, $key, &$count) {
            $append = ['notes'=>[], 'plan'=>[], 'gameplay'=>[]];
            foreach ($docs as $d) {
                $section = ($d['title'] !== '' ? '## '.$d['title']."\n\n" : '').$d['text'];
                if (isset($append[$d['to']])) { $append[$d['to']][] = $section; $count[$d['to']]++; continue; }
                if ($d['to'] === 'chapter') { $this->stories->addChapter($storyId, $d['title'], $d['text'], '', null, $by, $key); $count['chapters']++; continue; }
                // A character's or a place's document: the sheet, and the whole text in the design document.
                $first = trim((string)preg_split('/\n\s*\n/', $d['text'])[0]);
                $first = preg_replace('/^#+\s*/m', '', $first) ?? $first;
                $this->stories->updateCast($storyId, ['character'=>$d['title'], 'kind'=>$d['to'] === 'place' ? 'place' : 'character', 'note'=>mb_substr($first, 0, 300)], $by, $key);
                if ($d['text'] !== '') $append['notes'][] = '## '.$d['title']."\n\n".$d['text'];
                $count[$d['to'] === 'place' ? 'places' : 'characters']++;
            }
            if ($append['notes']) $this->stories->writeDocument($storyId, 'notes', ['append'=>implode("\n\n", $append['notes'])], $by, $key);
            if ($append['plan']) $this->stories->writeDocument($storyId, 'plan', ['append'=>implode("\n\n", $append['plan'])], $by, $key);
            if ($append['gameplay']) $this->writeGameplay($storyId, ['append'=>implode("\n\n", $append['gameplay'])], $by, $key);
        });
        return $count;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The checks: what the tree shows wrong, and a run through every path
    // -----------------------------------------------------------------------------------------------------------------

    /** Everything the checks, the preview and the export need, read once. */
    private function context(string $storyId, string $key): array
    {
        $settings = $this->settings($storyId, $key);
        $scenes = $this->sceneRows($storyId, $key);
        $cast = $this->stories->cast($storyId, $key)['characters'];
        $art = $this->art($storyId, $key);
        $names = array_map(fn($c) => $c['name'], array_filter($cast, fn($c) => $c['kind'] === 'character'));
        foreach ($scenes as &$sc) {
            $parsed = self::parse($sc['script'], $names);
            $sc['steps'] = $parsed['steps']; $sc['errors'] = $parsed['errors'];
        }
        unset($sc);
        return ['settings'=>$settings, 'scenes'=>$scenes, 'cast'=>$cast, 'art'=>$art];
    }

    private static function castByName(array $cast, string $name, ?string $kind = null): ?array
    {
        $n = Stories::findCharacter($cast, $name);
        return $n !== null && ($kind === null || $cast[$n]['kind'] === $kind) ? $cast[$n] : null;
    }

    /**
     * What the tree and the writer are told: the problems (errors and warnings, each with its scene), the endings and
     * whether each can be reached, the art and audio the script uses that don't exist yet, the routes, and the totals.
     * The structure is checked first (where each exit leads), then every path is played with the variables, up to
     * $limit states, to find the endings and scenes no combination of choices reaches and the choices that leave the
     * player stuck.
     */
    public function analyze(string $storyId, string $key, int $limit = 20000, ?array $ctx = null): array
    {
        $ctx ??= $this->context($storyId, $key);
        ['settings'=>$s, 'scenes'=>$scenes, 'cast'=>$cast, 'art'=>$art] = $ctx;
        $problems = [];
        $say = function (string $level, ?string $scene, string $code, string $text) use (&$problems): void { $problems[] = ['level'=>$level, 'scene'=>$scene, 'code'=>$code, 'text'=>$text]; };
        $byLabel = [];
        foreach ($scenes as $sc) $byLabel[$sc['label']] = $sc;
        $vars = [];
        foreach ($s['variables'] as $v) $vars[$v['name']] = $v;
        $audio = [];
        foreach ($s['audio'] as $a) $audio[$a['name']] = $a;
        $start = $s['settings']['start'];
        if (!$scenes) return ['start'=>$start, 'problems'=>[], 'endings'=>[], 'reachable'=>[], 'played'=>null, 'missing'=>['sprites'=>[], 'backgrounds'=>[], 'cgs'=>[], 'audio'=>[]],
            'routes'=>[], 'stats'=>['scenes'=>0, 'words'=>0, 'choices'=>0, 'endings'=>0, 'lines'=>0]];
        if ($start === '') $say('error', null, 'no-start', t('No starting scene chosen.'));
        elseif (!isset($byLabel[$start])) $say('error', null, 'start-missing', t('The starting scene “{label}” does not exist.', ['label'=>$start]));

        // The structure: where each exit leads, and what can be reached from the start.
        $used = []; $missing = ['sprites'=>[], 'backgrounds'=>[], 'cgs'=>[], 'audio'=>[]];
        $note = function (string $group, string $id, array $what, string $label) use (&$missing): void {
            $missing[$group][$id] ??= $what + ['scenes'=>[]];
            if (!in_array($label, $missing[$group][$id]['scenes'], true)) $missing[$group][$id]['scenes'][] = $label;
        };
        $useVar = function (string $name, string $label, string $where) use (&$used, &$vars, $say): void {
            $used[$name] = true;
            if (!isset($vars[$name])) $say('warn', $label, 'undeclared', t('Uses the variable “{name}”, which was not created ({where}). In the game it starts as false/0.', ['name'=>$name, 'where'=>$where]));
        };
        $words = 0; $lines = 0; $choices = 0; $endings = [];
        foreach ($scenes as $sc) {
            $label = $sc['label'];
            foreach ($sc['errors'] as $e) $say('error', $label, 'script', t('Line {n} of the script: {error}', ['n'=>$e['line'], 'error'=>$e['message']]));
            $words += self::scriptWords($sc['steps']); $lines += count(array_filter($sc['steps'], fn($x) => $x['t'] === 'say'));
            if (!$sc['steps']) $say('warn', $label, 'empty', t('The scene has no script yet.'));
            $shown = []; // who is on screen, with their outfit, as the steps go
            foreach ($sc['steps'] as $st) {
                if (isset($st['cond'])) foreach (self::conditionVars($st['cond']) as $v) $useVar($v, $label, 'linha '.$st['line']);
                switch ($st['t']) {
                    case 'set': {
                        $useVar($st['var'], $label, 'linha '.$st['line']);
                        if ($st['value'][0] === 'var') $useVar($st['value'][1], $label, 'linha '.$st['line']);
                        $type = $vars[$st['var']]['type'] ?? null;
                        if ($type !== null && $type !== 'number' && $st['op'] !== '=') $say('warn', $label, 'type', t('Line {n}: {op} on a variable that is not a number (“{name}”).', ['n'=>$st['line'], 'op'=>$st['op'], 'name'=>$st['var']]));
                        break;
                    }
                    case 'show': case 'say': {
                        if ($st['t'] === 'say' && ($st['who'] === null || $st['who'] === '???')) break;
                        $c = self::castByName($cast, (string)$st['who'], 'character');
                        if ($c === null) {
                            if ($st['t'] === 'show') $say('warn', $label, 'no-sheet', t('Line {n}: “{who}” has no character sheet, so has no sprite.', ['n'=>$st['line'], 'who'=>$st['who']]));
                            break;
                        }
                        if ($st['t'] === 'say' && $st['attrs'] === '') break;
                        if ($st['t'] === 'show') $shown[$c['id']] = true;
                        if (self::spriteFor($art['sprites'], $c['id'], $st['attrs']) === null)
                            $note('sprites', $c['id'].'|'.$st['attrs'], ['character'=>$c['name'], 'attrs'=>$st['attrs'] !== '' ? $st['attrs'] : '(base)'], $label);
                        break;
                    }
                    case 'bg': {
                        $place = self::castByName($cast, $st['place'], 'place');
                        if (self::backgroundFor($art['backgrounds'], $place['id'] ?? null, $place['name'] ?? $st['place'], $st['variant']) === null)
                            $note('backgrounds', self::slug($place['name'] ?? $st['place']).'|'.$st['variant'], ['place'=>$place['name'] ?? $st['place'], 'variant'=>$st['variant'], 'sheet'=>$place !== null], $label);
                        break;
                    }
                    case 'cg': {
                        $found = false; foreach ($art['cgs'] as $cg) if ($cg['name'] === $st['name']) $found = true;
                        if (!$found) $note('cgs', $st['name'], ['name'=>$st['name']], $label);
                        break;
                    }
                    case 'music': case 'sound': {
                        if ($st['track'] !== null && !isset($audio[$st['track']])) $note('audio', $st['track'], ['name'=>$st['track'], 'kind'=>$st['t'] === 'music' ? 'music' : 'sound'], $label);
                        break;
                    }
                }
            }
            $e = $sc['exit'];
            if ($e['type'] === 'none') $say('error', $label, 'dead-end', t('No exit: the scene ends with no choice, jump or ending.'));
            if ($e['type'] === 'end') $endings[] = ['scene'=>$label, 'title'=>$e['title'], 'kind'=>$e['kind'], 'route'=>$sc['route']];
            if ($e['type'] === 'choice') {
                $choices++;
                if (count($e['options']) === 1) $say('warn', $label, 'one-option', t('The choice has only one option.'));
                foreach ($e['options'] as $n => $o) {
                    if ($o['if'] !== '') foreach (self::conditionVars(self::condition($o['if'])) as $v) $useVar($v, $label, t('option {n}', ['n'=>($n + 1)]));
                    foreach ($o['set'] as $set) { $a = self::assignment($set); $useVar($a['var'], $label, t('option {n}', ['n'=>($n + 1)])); }
                }
                if ($e['options'] && !array_filter($e['options'], fn($o) => $o['if'] === '')) $say('warn', $label, 'all-conditional', t('Every option of the choice has a condition: if none holds, the player has no option.'));
            }
            if ($e['type'] === 'branch') {
                foreach ($e['cases'] as $n => $c) foreach (self::conditionVars(self::condition($c['if'])) as $v) $useVar($v, $label, 'caso '.($n + 1));
                if ($e['else'] === '') $say('warn', $label, 'no-else', t('The branch has no “else”: if no condition holds, the game stops here.'));
            }
            foreach (self::targets($e) as $to) if (!isset($byLabel[$to])) $say('error', $label, 'missing-target', t('Leads to the scene “{label}”, which does not exist yet.', ['label'=>$to]));
        }
        foreach ($vars as $name => $v) if (!isset($used[$name])) $say('info', null, 'unused', t('The variable “{name}” is not used in any scene.', ['name'=>$name]));

        $reach = [];
        if (isset($byLabel[$start])) {
            $queue = [$start]; $reach[$start] = true;
            while ($queue) { $l = array_shift($queue); foreach (self::targets($byLabel[$l]['exit']) as $to) if (isset($byLabel[$to]) && !isset($reach[$to])) { $reach[$to] = true; $queue[] = $to; } }
            foreach ($scenes as $sc) if (!isset($reach[$sc['label']])) $say('warn', $sc['label'], 'unreachable', t('No path from the starting scene gets here.'));
        }

        // Every path, with the variables: what can really be reached, and where the player gets stuck.
        $played = isset($byLabel[$start]) ? self::play($byLabel, $start, $vars, $limit) : null;
        if ($played !== null) {
            foreach ($played['stuck'] as $label => $why) $say('error', $label, 'stuck', $why);
            if (!$played['truncated']) foreach ($scenes as $sc) if (isset($reach[$sc['label']]) && !isset($played['scenes'][$sc['label']]))
                $say('warn', $sc['label'], $sc['exit']['type'] === 'end' ? 'ending-blocked' : 'blocked', $sc['exit']['type'] === 'end' ? t('Ending “{title}”: there is a path to here, but no combination of choices gets here with the current conditions.', ['title'=>$sc['exit']['title']]) : t('There is a path to here, but no combination of choices gets here with the current conditions.'));
        }
        foreach ($endings as &$en) {
            $en['reachable'] = isset($reach[$en['scene']]) && ($played === null || $played['truncated'] || isset($played['scenes'][$en['scene']]));
            if (!isset($reach[$en['scene']]) && isset($byLabel[$start])) $say('warn', $en['scene'], 'ending-unreachable', t('The ending “{title}” is not reached by any path.', ['title'=>$en['title']]));
        }
        unset($en);
        if (!$endings) $say('warn', null, 'no-ending', t('The project has no ending yet.'));

        // The routes: their scenes, words and endings.
        $routes = [];
        foreach ($s['routes'] as $r) $routes[$r['id']] = ['id'=>$r['id'], 'name'=>$r['name'], 'color'=>$r['color'], 'scenes'=>0, 'words'=>0, 'endings'=>[]];
        foreach ($scenes as $sc) if ($sc['route'] !== null && isset($routes[$sc['route']])) {
            $routes[$sc['route']]['scenes']++; $routes[$sc['route']]['words'] += self::scriptWords($sc['steps']);
            if ($sc['exit']['type'] === 'end') $routes[$sc['route']]['endings'][] = $sc['exit']['title'];
        }
        $order = ['error'=>0, 'warn'=>1, 'info'=>2];
        usort($problems, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
        foreach ($missing as &$group) $group = array_values($group);
        unset($group);
        return ['start'=>$start, 'problems'=>$problems, 'endings'=>$endings, 'reachable'=>array_keys($reach),
            'played'=>$played === null ? null : ['states'=>$played['states'], 'truncated'=>$played['truncated'], 'endings'=>count(array_filter($endings, fn($e) => isset($played['scenes'][$e['scene']])))],
            'missing'=>$missing, 'routes'=>array_values($routes),
            'stats'=>['scenes'=>count($scenes), 'words'=>$words, 'choices'=>$choices, 'endings'=>count($endings), 'lines'=>$lines]];
    }

    /**
     * Plays every path from the start, each state a scene with the variables as they are, up to $limit states: the
     * scenes reached, and the scenes where the player is left with nowhere to go (with the values that cause it).
     */
    private static function play(array $byLabel, string $start, array $vars, int $limit): array
    {
        $initial = [];
        foreach ($vars as $name => $v) $initial[$name] = $v['initial'];
        ksort($initial);
        $seen = []; $scenes = []; $stuck = []; $queue = [[$start, $initial]]; $states = 0; $truncated = false;
        $compiled = [];
        $cond = function (string $text) use (&$compiled): array { return $compiled[$text] ??= self::condition($text); };
        while ($queue) {
            [$label, $values] = array_shift($queue);
            if (!isset($byLabel[$label])) continue;
            $id = $label.'|'.json_encode($values);
            if (isset($seen[$id])) continue;
            if (++$states > $limit) { $truncated = true; break; }
            $seen[$id] = true; $scenes[$label] = true;
            $sc = $byLabel[$label];
            foreach ($sc['steps'] as $st) {
                if ($st['t'] !== 'set') continue;
                if (isset($st['cond']) && !self::truthy(self::evaluate($st['cond'], $values))) continue;
                $values = self::clamp(self::apply($st, $values));
            }
            $e = $sc['exit'];
            $next = [];
            if ($e['type'] === 'jump') $next[] = [$e['to'], $values];
            elseif ($e['type'] === 'choice') {
                foreach ($e['options'] as $o) {
                    if ($o['if'] !== '' && !self::truthy(self::evaluate($cond($o['if']), $values))) continue;
                    $v = $values;
                    foreach ($o['set'] as $set) $v = self::clamp(self::apply(self::assignment($set), $v));
                    $next[] = [$o['to'], $v];
                }
                if (!$next && !isset($stuck[$label])) $stuck[$label] = t('With {values}, no option of the choice is available: the player is stuck.', ['values'=>self::valuesText($values)]);
            } elseif ($e['type'] === 'branch') {
                $to = null;
                foreach ($e['cases'] as $c) if (self::truthy(self::evaluate($cond($c['if']), $values))) { $to = $c['to']; break; }
                $to ??= $e['else'] !== '' ? $e['else'] : null;
                if ($to !== null) $next[] = [$to, $values];
                elseif (!isset($stuck[$label])) $stuck[$label] = t('With {values}, no condition of the branch holds and there is no “else”: the game stops here.', ['values'=>self::valuesText($values)]);
            }
            foreach ($next as [$to, $v]) { ksort($v); $queue[] = [$to, $v]; }
        }
        return ['scenes'=>$scenes, 'stuck'=>$stuck, 'states'=>min($states, $limit), 'truncated'=>$truncated];
    }

    /** Numbers kept in a range, so a loop that counts doesn't make endless states. */
    private static function clamp(array $values): array
    {
        foreach ($values as $k => $v) if (is_int($v) || is_float($v)) $values[$k] = max(-999, min(999, $v));
        return $values;
    }

    private static function valuesText(array $values): string
    {
        if (!$values) return t('the variables as they are');
        $parts = [];
        foreach (array_slice($values, 0, 6, true) as $k => $v) $parts[] = $k.' = '.(is_bool($v) ? ($v ? 'true' : 'false') : (is_string($v) ? '"'.$v.'"' : $v));
        return implode(', ', $parts).(count($values) > 6 ? '…' : '');
    }

    // -----------------------------------------------------------------------------------------------------------------
    // What the page gets
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * The project for the page: settings, lists and gameplay notes, the scenes (with their scripts), the art and the
     * checks. With $runtime, each scene also comes as the preview plays it (steps and exit with their conditions as trees).
     */
    public function view(string $storyId, string $key, bool $runtime = false): array
    {
        $ctx = $this->context($storyId, $key);
        $analysis = $this->analyze($storyId, $key, 20000, $ctx);
        $scenes = array_map(function ($sc) use ($runtime) {
            $out = array_intersect_key($sc, array_flip(['id', 'number', 'label', 'title', 'chapter', 'route', 'summary', 'notes', 'script', 'exit', 'pos', 'rev', 'by', 'updated']));
            $out['words'] = self::scriptWords($sc['steps']); $out['lines'] = count(array_filter($sc['steps'], fn($x) => $x['t'] === 'say'));
            if ($runtime) $out['steps'] = $sc['steps'];
            if ($runtime) $out['run'] = self::runtimeExit($sc['exit']);
            return $out;
        }, $ctx['scenes']);
        $s = $ctx['settings'];
        return ['settings'=>$s['settings'], 'variables'=>$s['variables'], 'routes'=>$s['routes'], 'audio'=>$s['audio'], 'gameplay'=>$s['gameplay'], 'rev'=>$s['rev'], 'by'=>$s['by'],
            'scenes'=>$scenes, 'art'=>$ctx['art'], 'analysis'=>$analysis];
    }

    /** An exit with its conditions and changes as trees, for the preview. */
    private static function runtimeExit(array $e): array
    {
        $c = fn(string $s) => $s === '' ? null : self::condition($s);
        if ($e['type'] === 'choice') $e['options'] = array_map(fn($o) => $o + ['cond'=>$c($o['if']), 'sets'=>array_map(fn($s) => self::assignment($s), $o['set'])], $e['options']);
        if ($e['type'] === 'branch') $e['cases'] = array_map(fn($x) => $x + ['cond'=>$c($x['if'])], $e['cases']);
        return $e;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // What the writer reads
    // -----------------------------------------------------------------------------------------------------------------

    /** The exit in a line. */
    public static function exitLine(array $e): string
    {
        return match ($e['type']) {
            'jump'=>'→ '.$e['to'],
            'end'=>'ENDING ('.$e['kind'].') "'.$e['title'].'"',
            'choice'=>'choice'.($e['prompt'] !== '' ? ' "'.$e['prompt'].'"' : '').': '.implode(' | ', array_map(fn($o) => '"'.$o['text'].'"'.($o['if'] !== '' ? ' [if '.$o['if'].']' : '').($o['set'] ? ' {'.implode('; ', $o['set']).'}' : '').' → '.$o['to'], $e['options'])),
            'branch'=>'branch: '.implode(' | ', array_map(fn($c) => 'if '.$c['if'].' → '.$c['to'], $e['cases'])).($e['else'] !== '' ? ' | else → '.$e['else'] : ' | (no else)'),
            default=>'(no exit yet)',
        };
    }

    /** The project at a glance, for the writer: settings, variables, routes, audio, scenes, art and the checks. */
    public function outline(string $storyId, string $key, array $failed = []): string
    {
        $ctx = $this->context($storyId, $key);
        $a = $this->analyze($storyId, $key, 20000, $ctx);
        ['settings'=>$s, 'scenes'=>$scenes, 'cast'=>$cast, 'art'=>$art] = $ctx;
        $chapters = [];
        foreach ($this->stories->chapters($storyId, $key) as $n => $c) $chapters[$c['id']] = $n + 1;
        $routes = [];
        foreach ($s['routes'] as $r) $routes[$r['id']] = $r['name'];
        $out = self::settingsLines($s);
        $out .= 'Gameplay notes: revision '.$s['gameplay']['rev'].', '.Stories::words($s['gameplay']['text'])." words (read_gameplay).\n";
        $out .= "\nScenes (".count($scenes).'; '.$a['stats']['words'].' words of dialogue and narration, '.$a['stats']['choices'].' choices, '.$a['stats']['endings']." endings):\n";
        foreach ($scenes as $sc) $out .= '- '.$sc['label'].' "'.$sc['title'].'"'.($sc['chapter'] !== null && isset($chapters[$sc['chapter']]) ? ' [chapter '.$chapters[$sc['chapter']].']' : '')
            .($sc['route'] !== null && isset($routes[$sc['route']]) ? ' [route '.$routes[$sc['route']].']' : '').', '.self::scriptWords($sc['steps']).' words — '.self::exitLine($sc['exit'])
            .($sc['summary'] !== '' ? "\n   ".$sc['summary'] : '')."\n";
        if (!$scenes) $out .= "(none yet)\n";
        $out .= "\n".$this->artLines($art, $cast, $failed);
        $missing = $a['missing'];
        $count = count($missing['sprites']) + count($missing['backgrounds']) + count($missing['cgs']);
        if ($count) $out .= 'Art the scripts use that doesn\'t exist yet: '.implode('; ', array_filter([
            $missing['sprites'] ? 'sprites '.implode(', ', array_map(fn($m) => $m['character'].' ('.$m['attrs'].')', array_slice($missing['sprites'], 0, 40))) : null,
            $missing['backgrounds'] ? 'backgrounds '.implode(', ', array_map(fn($m) => $m['place'].($m['variant'] !== '' ? ' ('.$m['variant'].')' : ''), array_slice($missing['backgrounds'], 0, 40))) : null,
            $missing['cgs'] ? 'CGs '.implode(', ', array_map(fn($m) => $m['name'], array_slice($missing['cgs'], 0, 40))) : null])).".\n";
        if ($missing['audio']) $out .= 'Music and sounds the scripts use that aren\'t in the audio list: '.implode(', ', array_map(fn($m) => $m['name'], $missing['audio'])).".\n";
        $out .= self::problemLines($a);
        return $out;
    }

    /** The settings and the lists (variables, routes, audio), for the writer. */
    public static function settingsLines(array $s): string
    {
        $set = $s['settings'];
        $out = 'Visual novel settings: game name "'.($set['name'] !== '' ? $set['name'] : '(the story title)').'"; backgrounds and CGs '.$set['aspect'].'; sprites framed '
            .['full'=>'full body', 'knees'=>'down to the knees', 'waist'=>'down to the waist'][$set['framing']].'; first scene: '.($set['start'] !== '' ? $set['start'] : '(none)').".\n";
        $out .= 'Variables ('.count($s['variables']).'): '.($s['variables'] ? implode('; ', array_map(fn($v) => $v['name'].' ('.$v['type'].', starts '.(is_bool($v['initial']) ? ($v['initial'] ? 'true' : 'false') : json_encode($v['initial'], JSON_UNESCAPED_UNICODE)).')'.($v['note'] !== '' ? ' — '.$v['note'] : ''), $s['variables'])) : '(none)')."\n";
        $out .= 'Routes ('.count($s['routes']).'): '.($s['routes'] ? implode('; ', array_map(fn($r) => $r['name'].($r['note'] !== '' ? ' — '.$r['note'] : ''), $s['routes'])) : '(none)')."\n";
        $out .= 'Music and sounds ('.count($s['audio']).'): '.($s['audio'] ? implode('; ', array_map(fn($x) => $x['name'].' ('.$x['kind'].')'.($x['note'] !== '' ? ' — '.$x['note'] : ''), $s['audio'])) : '(none)')."\n";
        return $out;
    }

    /** The checks' problems, for the writer. */
    public static function problemLines(array $a, int $max = 40): string
    {
        $list = array_values(array_filter($a['problems'], fn($p) => $p['level'] !== 'info'));
        if (!$list) return 'Checks: no problems found'.($a['played'] !== null && $a['played']['truncated'] ? ' (the run through every path stopped at '.$a['played']['states'].' states)' : '').".\n";
        $out = 'Checks ('.count($list).' problem'.(count($list) === 1 ? '' : 's').", in Portuguese as the person sees them):\n";
        foreach (array_slice($list, 0, $max) as $p) $out .= '- ['.$p['level'].'] '.($p['scene'] !== null ? $p['scene'].': ' : '').$p['text']."\n";
        if (count($list) > $max) $out .= '- … and '.(count($list) - $max)." more (check_vn shows them all).\n";
        return $out;
    }

    /** The art, for the writer: each sprite, background and CG with its picture or request. */
    public function artLines(array $art, array $cast, array $failed = []): string
    {
        $names = [];
        foreach ($cast as $c) $names[$c['id']] = $c['name'];
        $pic = fn(array $a) => is_string($a['image'] ?? null) ? 'image '.$a['image'].(is_string($a['job'] ?? null) ? ', new version being made' : '')
            : (is_string($a['job'] ?? null) ? 'request '.$a['job'].(isset($failed[$a['job']]) ? ' (failed)' : ' (being made)') : 'no picture');
        $out = 'Sprites ('.count($art['sprites']).'):';
        if (!$art['sprites']) $out .= ' (none yet)';
        $by = [];
        foreach ($art['sprites'] as $a) $by[$names[$a['character']] ?? '?'][] = trim($a['outfit'].' '.$a['expression']).': '.$pic($a);
        foreach ($by as $who => $list) $out .= "\n- ".$who.': '.implode('; ', $list);
        $out .= "\nBackgrounds (".count($art['backgrounds']).'):'.($art['backgrounds'] ? '' : ' (none yet)');
        foreach ($art['backgrounds'] as $a) $out .= "\n- ".$a['name'].($a['variant'] !== '' ? ' ('.$a['variant'].')' : '').': '.$pic($a);
        $out .= "\nCGs (".count($art['cgs']).'):'.($art['cgs'] ? '' : ' (none yet)');
        foreach ($art['cgs'] as $a) $out .= "\n- ".$a['name'].' "'.$a['title'].'": '.$pic($a);
        return $out."\n";
    }

    /** One scene in full, for the writer. */
    public function sceneText(string $storyId, array $sc, string $key): string
    {
        $chapter = null;
        if ($sc['chapter'] !== null) foreach ($this->stories->chapters($storyId, $key) as $n => $c) if ($c['id'] === $sc['chapter']) $chapter = $n + 1;
        $route = null;
        if ($sc['route'] !== null) foreach ($this->settings($storyId, $key)['routes'] as $r) if ($r['id'] === $sc['route']) $route = $r['name'];
        return 'Scene '.$sc['label'].' "'.$sc['title'].'" (number '.$sc['number'].' in the list, revision '.$sc['rev'].')'.($chapter !== null ? ', chapter '.$chapter : '').($route !== null ? ', route '.$route : '')."\n"
            .'Summary: '.($sc['summary'] !== '' ? $sc['summary'] : '(none)')."\n".($sc['notes'] !== '' ? 'Gameplay notes: '.$sc['notes']."\n" : '')
            ."Script:\n".($sc['script'] !== '' ? $sc['script'] : '(empty)')."\nExit: ".self::exitLine($sc['exit'])."\n";
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The export to Ren'Py
    // -----------------------------------------------------------------------------------------------------------------

    private const RENPY_POSITIONS = ['left'=>'left', 'centerleft'=>'atelier_centerleft', 'center'=>'center', 'centerright'=>'atelier_centerright', 'right'=>'right'];
    private const RENPY_TRANSITIONS = ['none'=>null, 'fade'=>'fade', 'dissolve'=>'dissolve', 'flash'=>'atelier_flash', 'slow'=>'atelier_slow'];

    /** Text as a Ren'Py string: quotes and backslashes escaped, [ and { doubled (they start interpolation and text tags). */
    public static function renpyText(string $s): string
    { return '"'.str_replace(['\\', '"', '[', '{', "\n"], ['\\\\', '\\"', '[[', '{{', '\\n'], $s).'"'; }

    private static function with(string $w): string
    { $t = self::RENPY_TRANSITIONS[$w] ?? null; return $t !== null ? ' with '.$t : ''; }

    /**
     * The project as a Ren'Py game folder: the script (one label per scene, with its menu, branches and endings), the
     * characters, the images, the variables, an audio list, and a readable script and the whole project as JSON for the
     * team. images: the path of each picture in the folder => its library image id (the page downloads them).
     * @return array{files:array<string,string>,images:array<string,string>}
     */
    public function renpy(string $storyId, string $key): array
    {
        $ctx = $this->context($storyId, $key);
        ['settings'=>$s, 'scenes'=>$scenes, 'cast'=>$cast, 'art'=>$art] = $ctx;
        $story = $this->stories->listed($storyId, $key);
        $title = $s['settings']['name'] !== '' ? $s['settings']['name'] : ($story['title'] !== '' ? $story['title'] : 'Visual novel');
        $stamp = gmdate('Y-m-d H:i').' UTC';
        $head = '# '.t('Made by Atelier on {date}: {title}', ['date'=>$stamp, 'title'=>str_replace("\n", ' ', $title)])."\n# ".t('Copy the game/ folder into a new Ren\'Py 8 project (replacing its script.rpy).')."\n\n";
        $tags = []; $characters = [];
        foreach ($cast as $c) if ($c['kind'] === 'character') {
            $tag = self::slug($c['name'], 30);
            for ($base = $tag, $n = 2; in_array($tag, $tags, true) || in_array($tag, self::RESERVED_NAMES, true); $n++) $tag = $base.'_'.$n;
            $tags[] = $tag; $characters[$c['id']] = ['tag'=>$tag, 'name'=>$c['name']];
        }
        $tagOf = function (string $name) use ($cast, $characters): ?string { $c = self::castByName($cast, $name, 'character'); return $c !== null ? ($characters[$c['id']]['tag'] ?? null) : null; };
        $images = []; $imageLines = [];
        $ext = function (string $id) use ($key): string { try { return ['image/jpeg'=>'jpg', 'image/webp'=>'webp'][$this->vault->still($id, $key)['mime']] ?? 'png'; } catch (HttpError) { return 'png'; } };
        foreach ($art['sprites'] as $a) {
            if (!is_string($a['image']) || !isset($characters[$a['character']])) continue;
            $tag = $characters[$a['character']]['tag'];
            $attrs = array_values(array_filter([$a['outfit'], $a['expression']], fn($w) => $w !== ''));
            $name = trim($tag.' '.implode(' ', array_map(fn($w) => self::slug($w, 30), $attrs)));
            $path = 'images/sprites/'.$tag.'/'.str_replace(' ', '_', $name).'.'.$ext($a['image']);
            $images['game/'.$path] = $a['image'];
            $imageLines[] = 'image '.$name.' = Transform("'.$path.'", fit="contain", ysize=int(config.screen_height * '.($s['settings']['framing'] === 'waist' ? '0.85' : '0.95').'))';
        }
        $bgName = function (array $b): string { return trim('bg '.self::slug((string)$b['name'], 30).($b['variant'] !== '' ? ' '.self::slug($b['variant'], 20) : '')); };
        foreach ($art['backgrounds'] as $b) {
            if (!is_string($b['image'])) continue;
            $name = $bgName($b); $path = 'images/bg/'.str_replace(' ', '_', substr($name, 3)).'.'.$ext($b['image']);
            $images['game/'.$path] = $b['image'];
            $imageLines[] = 'image '.$name.' = Transform("'.$path.'", fit="cover", xysize=(config.screen_width, config.screen_height))';
        }
        foreach ($art['cgs'] as $c) {
            if (!is_string($c['image'])) continue;
            $path = 'images/cg/'.$c['name'].'.'.$ext($c['image']);
            $images['game/'.$path] = $c['image'];
            $imageLines[] = 'image cg '.$c['name'].' = Transform("'.$path.'", fit="cover", xysize=(config.screen_width, config.screen_height))';
        }
        // Characters
        $colors = ['#e8a0b4', '#9ec5f0', '#a8dca0', '#f0d08c', '#c8a8f0', '#8cd8d0', '#f0b48c', '#d0d0d0'];
        $chars = $head.'# '.t('Characters: the name that shows in the dialogue box and the tag of the sprites\' images.')."\n";
        $i = 0;
        foreach ($characters as $c) $chars .= 'define '.$c['tag'].' = Character('.self::renpyText($c['name']).', image='.self::pyString($c['tag']).', color="'.$colors[$i++ % count($colors)].'")'."\n";
        $chars .= "\n# ".t('Positions and transitions used in the script.')."\ntransform atelier_centerleft:\n    xalign 0.25\n    yalign 1.0\ntransform atelier_centerright:\n    xalign 0.75\n    yalign 1.0\n"
            ."define atelier_flash = Fade(0.1, 0.0, 0.5, color=\"#fff\")\ndefine atelier_slow = Dissolve(1.5)\n";
        // Variables: the declared ones, and any a script uses without declaring (as false, with a note).
        $vars = $head.'# '.t('The game\'s variables (default: the value each one starts with).')."\n";
        $declared = [];
        foreach ($s['variables'] as $v) {
            $declared[$v['name']] = true;
            $value = $v['type'] === 'flag' ? ($v['initial'] ? 'True' : 'False') : ($v['type'] === 'number' ? (string)$v['initial'] : self::pyString((string)$v['initial']));
            $vars .= ($v['note'] !== '' ? '# '.str_replace("\n", ' ', $v['note'])."\n" : '').'default '.$v['name'].' = '.$value."\n";
        }
        $a = $this->analyze($storyId, $key, 2000, $ctx);
        foreach ($a['problems'] as $p) if ($p['code'] === 'undeclared' && preg_match('/“([A-Za-z_][A-Za-z0-9_]*)”/u', $p['text'], $m) && !isset($declared[$m[1]])) {
            $declared[$m[1]] = true; $vars .= '# '.t('Used in the script without being created in Atelier.')."\ndefault ".$m[1]." = False\n";
        }
        // The script
        $audio = [];
        foreach ($s['audio'] as $x) $audio[$x['name']] = $x;
        $byLabel = [];
        foreach ($scenes as $sc) $byLabel[$sc['label']] = true;
        $script = $head.'label start:'."\n";
        $script .= $s['settings']['start'] !== '' && isset($byLabel[$s['settings']['start']]) ? '    jump '.$s['settings']['start']."\n" : '    '.self::renpyText(t('(The project has no starting scene yet.)'))."\n    return\n";
        $jump = fn(string $to, string $pad) => isset($byLabel[$to]) ? $pad.'jump '.$to."\n" : $pad.'# '.t('The scene "{label}" does not exist in Atelier yet.', ['label'=>$to])."\n".$pad."return\n";
        foreach ($scenes as $sc) {
            $script .= "\nlabel ".$sc['label'].":\n    # ".str_replace("\n", ' ', $sc['title'] !== '' ? $sc['title'] : $sc['label'])."\n";
            foreach (preg_split('/\n/', $sc['notes']) ?: [] as $l) if (trim($l) !== '') $script .= '    # '.t('Gameplay: {note}', ['note'=>trim($l)])."\n";
            $count = 0;
            foreach ($sc['steps'] as $st) {
                $pad = '    ';
                if (isset($st['cond'])) { $script .= '    if '.self::python($st['cond']).":\n"; $pad = '        '; }
                $line = match ($st['t']) {
                    'note'=>'# '.str_replace("\n", ' ', $st['text']),
                    'say'=>self::renpySay($st, $tagOf),
                    'bg'=>(function () use ($st, $cast, $art, $bgName) {
                        $place = self::castByName($cast, $st['place'], 'place');
                        $b = self::backgroundFor($art['backgrounds'], $place['id'] ?? null, $place['name'] ?? $st['place'], $st['variant']);
                        $name = $b !== null ? $bgName($b) : $bgName(['name'=>$place['name'] ?? $st['place'], 'variant'=>$st['variant']]);
                        return 'scene '.$name.self::with($st['with']);
                    })(),
                    'show'=>($t = $tagOf($st['who'])) !== null ? 'show '.trim($t.' '.implode(' ', array_map(fn($w) => self::slug($w, 30), preg_split('/\s+/u', $st['attrs'], -1, PREG_SPLIT_NO_EMPTY) ?: [])))
                        .($st['at'] !== '' ? ' at '.self::RENPY_POSITIONS[$st['at']] : '').self::with($st['with']) : '# '.t('(no sheet)').' show '.$st['who'],
                    'hide'=>($t = $tagOf($st['who'])) !== null ? 'hide '.$t.self::with($st['with']) : '# '.t('(no sheet)').' hide '.$st['who'],
                    'cg'=>'scene cg '.$st['name'].self::with($st['with']),
                    'music'=>$st['track'] === null ? 'stop music'.($st['fade'] > 0 ? ' fadeout '.$st['fade'] : '') : 'play music "audio/'.$st['track'].'.ogg"'.($st['fade'] > 0 ? ' fadein '.$st['fade'] : ''),
                    'sound'=>'play sound "audio/'.$st['track'].'.ogg"',
                    'set'=>'$ '.$st['var'].' '.$st['op'].' '.self::python($st['value']),
                    'pause'=>'pause '.$st['seconds'],
                    'effect'=>$st['name'] === 'shake' ? 'with vpunch' : 'with atelier_flash',
                    default=>'pass',
                };
                $script .= $pad.$line."\n"; $count++;
            }
            $e = $sc['exit'];
            switch ($e['type']) {
                case 'jump': $script .= $jump($e['to'], '    '); break;
                case 'end': $script .= '    centered "{size=+12}'.substr(self::renpyText($e['title']), 1, -1).'{/size}"'."\n    return\n"; break;
                case 'choice': {
                    $script .= "    menu:\n".($e['prompt'] !== '' ? '        '.self::renpyText($e['prompt'])."\n" : '');
                    foreach ($e['options'] as $o) {
                        $script .= '        '.self::renpyText($o['text']).($o['if'] !== '' ? ' if '.self::python(self::condition($o['if'])) : '').":\n";
                        if ($o['note'] !== '') $script .= '            # '.str_replace("\n", ' ', $o['note'])."\n";
                        foreach ($o['set'] as $set) { $x = self::assignment($set); $script .= '            $ '.$x['var'].' '.$x['op'].' '.self::python($x['value'])."\n"; }
                        $script .= $jump($o['to'], '            ');
                    }
                    break;
                }
                case 'branch': {
                    foreach ($e['cases'] as $n => $c) { $script .= '    '.($n === 0 ? 'if ' : 'elif ').self::python(self::condition($c['if'])).":\n".$jump($c['to'], '        '); }
                    $script .= "    else:\n".($e['else'] !== '' ? $jump($e['else'], '        ') : '        # '.t('No condition held and there is no "else".')."\n        return\n");
                    break;
                }
                default: if (!$count) $script .= "    pass\n"; $script .= '    # '.t('This scene has no exit in Atelier yet.')."\n    return\n";
            }
        }
        $audioList = t('Music and sounds the script uses. Put each file in game/audio/ with this name (.ogg; .mp3 and .opus work too, changing the extension in script.rpy).')."\n\n";
        $names = [];
        foreach ($s['audio'] as $x) { $names[$x['name']] = true; $audioList .= $x['name'].'.ogg ('.($x['kind'] === 'music' ? t('music') : t('sound')).')'.($x['note'] !== '' ? ': '.$x['note'] : '')."\n"; }
        foreach ($a['missing']['audio'] as $m) if (!isset($names[$m['name']])) $audioList .= $m['name'].'.ogg ('.($m['kind'] === 'music' ? t('music, used in the script and not in the list yet') : t('sound, used in the script and not in the list yet')).')'."\n";
        $files = ['game/script.rpy'=>$script, 'game/atelier_personagens.rpy'=>$chars, 'game/atelier_imagens.rpy'=>$head.'# '.t('Images: sprites with a transparent background, backgrounds and CGs.')."\n".implode("\n", $imageLines)."\n",
            'game/atelier_variaveis.rpy'=>$vars, 'game/audio/LEIA-ME.txt'=>$audioList, 'atelier/roteiro.md'=>$this->markdown($storyId, $ctx, $title, $key),
            'atelier/projeto.json'=>json_encode($this->exportData($storyId, $key), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),
            'LEIA-ME.txt'=>$title."\n\n".t('Made by Atelier on {date}.', ['date'=>$stamp])."\n\n"
                .t("1. Create a new project in Ren'Py 8 (Ren'Py Launcher › Create New Project).\n2. Copy this zip's game/ folder into the project's folder, accepting to replace script.rpy.\n3. Put the music and the sounds in game/audio/, with the names in game/audio/LEIA-ME.txt.\n4. Open the project in Ren'Py and click Launch Project.\n\nThe atelier/ folder has the whole script to read (roteiro.md) and the project as JSON (projeto.json), for other tools.")."\n"
                .($a['problems'] ? "\n".t('Atelier found {errors} error(s) and {warnings} warning(s) in the project; check the choice tree before publishing.', ['errors'=>count(array_filter($a['problems'], fn($p) => $p['level'] === 'error')), 'warnings'=>count(array_filter($a['problems'], fn($p) => $p['level'] === 'warn'))])."\n" : '')];
        return ['files'=>$files, 'images'=>$images];
    }

    private static function renpySay(array $st, \Closure $tagOf): string
    {
        if ($st['who'] === null) return self::renpyText($st['text']);
        $tag = $tagOf($st['who']);
        if ($tag === null) return self::renpyText($st['who']).' '.self::renpyText($st['text']);
        $attrs = preg_split('/\s+/u', $st['attrs'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return trim($tag.' '.implode(' ', array_map(fn($w) => self::slug($w, 30), $attrs))).' '.self::renpyText($st['text']);
    }

    /** The whole script to read, as Markdown: each scene with its script and where it leads. */
    private function markdown(string $storyId, array $ctx, string $title, string $key): string
    {
        $routes = [];
        foreach ($ctx['settings']['routes'] as $r) $routes[$r['id']] = $r['name'];
        $chapters = [];
        foreach ($this->stories->chapters($storyId, $key) as $n => $c) $chapters[$c['id']] = $c['title'] !== '' ? t('Chapter {n}: {title}', ['n'=>$n + 1, 'title'=>$c['title']]) : t('Chapter {n}', ['n'=>$n + 1]);
        $out = '# '.$title."\n\n";
        foreach ($ctx['scenes'] as $sc) {
            $out .= '## '.($sc['title'] !== '' ? $sc['title'] : $sc['label']).' (`'.$sc['label'].'`)'."\n\n";
            $meta = array_filter([$sc['chapter'] !== null ? ($chapters[$sc['chapter']] ?? null) : null, $sc['route'] !== null && isset($routes[$sc['route']]) ? t('Route', ['ctx'=>'script']).': '.$routes[$sc['route']] : null]);
            if ($meta) $out .= '*'.implode(' · ', $meta)."*\n\n";
            if ($sc['summary'] !== '') $out .= '> '.$sc['summary']."\n\n";
            foreach ($sc['steps'] as $st) {
                $if = isset($st['if']) ? '*['.t('if', ['ctx'=>'script']).' '.$st['if'].']* ' : '';
                $out .= match ($st['t']) {
                    'say'=>$st['who'] === null ? $if.$st['text']."\n\n" : $if.'**'.$st['who'].'**'.($st['attrs'] !== '' ? ' *('.$st['attrs'].')*' : '').': '.$st['text']."\n\n",
                    'note'=>'> 📝 '.$st['text']."\n\n",
                    'bg'=>$if.'`['.t('Background', ['ctx'=>'script']).': '.$st['place'].($st['variant'] !== '' ? ', '.$st['variant'] : '').']`'."\n\n",
                    'show'=>$if.'`['.t('Show', ['ctx'=>'script']).' '.$st['who'].($st['attrs'] !== '' ? ' ('.$st['attrs'].')' : '').($st['at'] !== '' ? ', '.$st['at'] : '').']`'."\n\n",
                    'hide'=>$if.'`['.t('Exit', ['ctx'=>'script']).' '.$st['who'].']`'."\n\n",
                    'cg'=>$if.'`[CG: '.$st['name'].']`'."\n\n",
                    'music'=>$if.'`['.t('Music', ['ctx'=>'script']).': '.($st['track'] ?? t('stop', ['ctx'=>'script'])).']`'."\n\n",
                    'sound'=>$if.'`['.t('Sound', ['ctx'=>'script']).': '.$st['track'].']`'."\n\n",
                    'set'=>$if.'`['.$st['text'].']`'."\n\n",
                    'pause'=>$if.'`['.t('Pause', ['ctx'=>'script']).' '.$st['seconds'].' s]`'."\n\n",
                    'effect'=>$if.'`['.t('Effect', ['ctx'=>'script']).': '.$st['name'].']`'."\n\n",
                    default=>'',
                };
            }
            $e = $sc['exit'];
            $out .= match ($e['type']) {
                'jump'=>'→ `'.$e['to'].'`'."\n\n",
                'end'=>'**'.t('THE END', ['ctx'=>'script']).': '.$e['title'].'** ('.$e['kind'].")\n\n",
                'choice'=>($e['prompt'] !== '' ? '**'.$e['prompt']."**\n\n" : '').implode('', array_map(fn($o) => '- '.$o['text'].($o['if'] !== '' ? ' *['.t('if', ['ctx'=>'script']).' '.$o['if'].']*' : '').($o['set'] ? ' `'.implode('; ', $o['set']).'`' : '').' → `'.$o['to'].'`'."\n", $e['options']))."\n",
                'branch'=>implode('', array_map(fn($c) => '- '.t('if', ['ctx'=>'script']).' '.$c['if'].' → `'.$c['to'].'`'."\n", $e['cases'])).($e['else'] !== '' ? '- '.t('else', ['ctx'=>'script']).' → `'.$e['else'].'`'."\n" : '')."\n",
                default=>'*('.t('no exit yet', ['ctx'=>'script']).")*\n\n",
            };
        }
        return $out;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // In the story's file (Stories::export / import / copy)
    // -----------------------------------------------------------------------------------------------------------------

    /** What the story's file takes of the project: settings and lists, the scenes (a chapter by its number) and the art (pictures only, no requests). */
    public function exportData(string $storyId, string $key): array
    {
        $s = $this->settings($storyId, $key); $art = $this->art($storyId, $key);
        $numbers = [];
        foreach ($this->stories->chapters($storyId, $key) as $n => $c) $numbers[$c['id']] = $n + 1;
        foreach (self::LISTS as $list) $art[$list] = array_values(array_map(fn($a) => array_diff_key($a, ['job'=>1]), array_filter($art[$list], fn($a) => is_string($a['image'] ?? null))));
        return ['settings'=>$s['settings'], 'variables'=>$s['variables'], 'routes'=>$s['routes'], 'audio'=>$s['audio'], 'gameplay'=>array_intersect_key($s['gameplay'], array_flip(['text', 'rev', 'by'])),
            'scenes'=>array_map(fn($sc) => ['chapter'=>$sc['chapter'] !== null ? ($numbers[$sc['chapter']] ?? null) : null]
                + array_intersect_key($sc, array_flip(['id', 'label', 'title', 'route', 'summary', 'notes', 'script', 'exit', 'pos', 'rev', 'by'])), $this->sceneRows($storyId, $key)),
            'art'=>array_intersect_key($art, array_flip(['sprites', 'backgrounds', 'cgs']))];
    }

    /**
     * The project's part of a file, checked as if typed in the app, ready to be inserted for a new story: the settings
     * blob, the art blob and the scenes. $picture keeps a picture of the account (null otherwise); $chapter turns the
     * file's chapter (its number) into the new chapter's id.
     * @return array{vn:array,art:array,scenes:list<array>}
     */
    public static function importData(array $in, \Closure $picture, \Closure $chapter, array $castIds): array
    {
        $list = fn(mixed $v) => is_array($v) && array_is_list($v) ? $v : [];
        $part = fn(mixed $v) => is_array($v) && !array_is_list($v) ? $v : [];
        $by = fn(mixed $v) => in_array($v, ['agent', 'person', 'system', 'mcp'], true) ? $v : 'person';
        $settings = self::DEFAULTS;
        $set = $part($in['settings'] ?? null);
        if (isset($set['name'])) $settings['name'] = Stories::cleanLine($set['name'], 120, t('The game\'s name'));
        if (in_array($set['aspect'] ?? null, self::ASPECTS, true)) $settings['aspect'] = $set['aspect'];
        if (in_array($set['framing'] ?? null, self::FRAMINGS, true)) $settings['framing'] = $set['framing'];
        try { if (is_string($set['start'] ?? null) && $set['start'] !== '') $settings['start'] = self::label($set['start']); } catch (HttpError) {}
        $vn = ['rev'=>1, 'by'=>'person', 'at'=>time(), 'settings'=>$settings, 'variables'=>[], 'routes'=>[], 'audio'=>[], 'gameplay'=>['text'=>'', 'rev'=>0, 'by'=>null, 'at'=>null]];
        foreach (['variables', 'routes', 'audio'] as $name) foreach ($list($in[$name] ?? null) as $item) {
            if (!is_array($item)) continue;
            try { [$vn[$name]] = self::setItem($name, $vn[$name], $item); } catch (HttpError) { continue; }
        }
        // Routes keep their ids, which the scenes name.
        $routeIds = [];
        foreach ($list($in['routes'] ?? null) as $r) if (is_array($r) && is_string($r['id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $r['id'])) {
            $at = self::findNamed($vn['routes'], (string)($r['name'] ?? '')); if ($at !== null) { $vn['routes'][$at]['id'] = $r['id']; $routeIds[$r['id']] = true; }
        }
        foreach ($vn['routes'] as $r) $routeIds[$r['id']] = true;
        $g = $part($in['gameplay'] ?? null);
        $text = is_string($g['text'] ?? null) ? trim(Stories::cleanText($g['text'], Stories::MAX_TEXT, t('The gameplay notes'))) : '';
        if ($text !== '') $vn['gameplay'] = ['text'=>$text, 'rev'=>1, 'by'=>$by($g['by'] ?? null), 'at'=>time()];
        $scenes = []; $labels = [];
        foreach ($list($in['scenes'] ?? null) as $n => $sc) {
            if (!is_array($sc)) continue;
            if (count($scenes) >= self::MAX_SCENES) throw new HttpError(422, t('A project can have up to {max} scenes.', ['max'=>self::MAX_SCENES]));
            try {
                $label = self::label($sc['label'] ?? ($sc['title'] ?? t('scene', ['ctx'=>'label'])));
                for ($base = $label, $k = 2; isset($labels[$label]); $k++) $label = $base.'_'.$k;
                $script = is_string($sc['script'] ?? null) ? rtrim(Stories::cleanText($sc['script'], self::MAX_SCRIPT, t('The script'))) : '';
                $exit = isset($sc['exit']) ? self::exit($sc['exit']) : ['type'=>'none'];
            } catch (HttpError $e) { throw new HttpError(422, 'Cena '.($n + 1).': '.$e->getMessage()); }
            $labels[$label] = true;
            $scenes[] = ['old'=>is_string($sc['id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $sc['id']) ? $sc['id'] : null, 'label'=>$label, 'title'=>Stories::cleanLine($sc['title'] ?? '', 200, t('The scene\'s title')), 'chapter'=>is_int($sc['chapter'] ?? null) ? $chapter($sc['chapter']) : null,
                'route'=>is_string($sc['route'] ?? null) && isset($routeIds[$sc['route']]) ? $sc['route'] : null,
                'summary'=>is_string($sc['summary'] ?? null) ? trim(Stories::cleanText($sc['summary'], 3000, t('The summary'))) : '', 'notes'=>is_string($sc['notes'] ?? null) ? trim(Stories::cleanText($sc['notes'], 6000, t('The notes'))) : '',
                'script'=>$script, 'exit'=>$exit, 'pos'=>self::pos($sc['pos'] ?? null), 'by'=>$by($sc['by'] ?? null), 'rev'=>is_int($sc['rev'] ?? null) ? max(1, $sc['rev']) : 1];
        }
        $art = ['rev'=>1, 'by'=>'person', 'at'=>time(), 'sprites'=>[], 'backgrounds'=>[], 'cgs'=>[]];
        $artIn = $part($in['art'] ?? null);
        $line = fn(mixed $v, int $max) => is_string($v) ? Stories::cleanLine($v, $max, t('The name')) : '';
        foreach ($list($artIn['sprites'] ?? null) as $a) {
            if (!is_array($a) || !isset($castIds[$a['character'] ?? '']) || ($image = $picture($a['image'] ?? null)) === null || count($art['sprites']) >= self::MAX_SPRITES) continue;
            $art['sprites'][] = ['id'=>self::newId(), 'character'=>$a['character'], 'outfit'=>self::attrs($line($a['outfit'] ?? '', 40)), 'expression'=>self::attrs($line($a['expression'] ?? '', 40)) ?: t('neutral', ['ctx'=>'expression']),
                'image'=>$image, 'job'=>null, 'prompt'=>mb_substr((string)($a['prompt'] ?? ''), 0, 4000), 'note'=>$line($a['note'] ?? '', 500), 'by'=>$by($a['by'] ?? null), 'at'=>time()];
        }
        foreach ($list($artIn['backgrounds'] ?? null) as $a) {
            if (!is_array($a) || ($image = $picture($a['image'] ?? null)) === null || count($art['backgrounds']) >= self::MAX_BACKGROUNDS) continue;
            $art['backgrounds'][] = ['id'=>self::newId(), 'place'=>is_string($a['place'] ?? null) && isset($castIds[$a['place']]) ? $a['place'] : null, 'name'=>$line($a['name'] ?? 'Lugar', 80) ?: 'Lugar',
                'variant'=>self::attrs($line($a['variant'] ?? '', 40)), 'image'=>$image, 'job'=>null, 'prompt'=>mb_substr((string)($a['prompt'] ?? ''), 0, 4000), 'note'=>$line($a['note'] ?? '', 500), 'by'=>$by($a['by'] ?? null), 'at'=>time()];
        }
        foreach ($list($artIn['cgs'] ?? null) as $a) {
            if (!is_array($a) || ($image = $picture($a['image'] ?? null)) === null || count($art['cgs']) >= self::MAX_CGS) continue;
            $art['cgs'][] = ['id'=>self::newId(), 'name'=>self::slug((string)($a['name'] ?? $a['title'] ?? 'cg')), 'title'=>$line($a['title'] ?? '', 120), 'image'=>$image, 'job'=>null,
                'prompt'=>mb_substr((string)($a['prompt'] ?? ''), 0, 4000), 'note'=>$line($a['note'] ?? '', 500), 'by'=>$by($a['by'] ?? null), 'at'=>time()];
        }
        return ['vn'=>$vn, 'art'=>$art, 'scenes'=>$scenes];
    }
}
