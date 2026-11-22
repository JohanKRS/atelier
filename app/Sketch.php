<?php
declare(strict_types=1);
namespace Studio;

/**
 * The rough storyboard of a comic page: an SVG the writer draws (or the app draws from the page's script), turned into
 * a PNG with GD, which goes to the image model as the composition guide of the page and back to the writer so it can
 * see what it drew.
 *
 * Only a plain subset of SVG is drawn, and the drawing needs nothing but GD (no Imagick, no fonts on the server):
 * svg, g, a, rect, circle, ellipse, line, polyline, polygon, path (all commands, arcs too), text and tspan; fill,
 * stroke, stroke-width, stroke-dasharray, stroke-linecap, opacity, fill-opacity, stroke-opacity, transform,
 * font-size, font-weight, text-anchor, marker-start and marker-end (an arrowhead), in attributes or in style="".
 * Text is drawn with a stroke font of its own, in capitals, which is all a storyboard needs. Anything else (images,
 * gradients, filters, CSS, scripts) is left out: the input is only ever read as shapes, never run or fetched.
 */
final class Sketch
{
    public const MAX_SVG = 150000;          // characters of SVG
    private const MAX_ELEMENTS = 6000;
    private const MAX_POINTS = 600000;      // vertices drawn, all shapes together
    private const SS = 2;                   // drawn at twice the size, then reduced: smooth edges without GD's antialiasing
    /** The width of a page in the sketch's units; the height follows the page's proportions (1500 for 2:3). */
    public const UNITS = 1000;

    /**
     * Strokes of each character on a grid 4 wide and 6 tall (0 is the top of the capitals, 6 the baseline); each
     * stroke is a list of points. Lower case is drawn in capitals.
     */
    private const GLYPHS = [
        'A'=>[[[0,6],[2,0],[4,6]],[[0.7,4],[3.3,4]]],
        'B'=>[[[0,6],[0,0],[3,0],[4,1],[4,2],[3,3],[0,3]],[[3,3],[4,4],[4,5],[3,6],[0,6]]],
        'C'=>[[[4,1],[3,0],[1,0],[0,1],[0,5],[1,6],[3,6],[4,5]]],
        'D'=>[[[0,0],[0,6],[2.5,6],[4,4.5],[4,1.5],[2.5,0],[0,0]]],
        'E'=>[[[4,0],[0,0],[0,6],[4,6]],[[0,3],[3,3]]],
        'F'=>[[[4,0],[0,0],[0,6]],[[0,3],[3,3]]],
        'G'=>[[[4,1],[3,0],[1,0],[0,1],[0,5],[1,6],[3,6],[4,5],[4,3],[2.5,3]]],
        'H'=>[[[0,0],[0,6]],[[4,0],[4,6]],[[0,3],[4,3]]],
        'I'=>[[[1,0],[3,0]],[[2,0],[2,6]],[[1,6],[3,6]]],
        'J'=>[[[4,0],[4,5],[3,6],[1,6],[0,5]]],
        'K'=>[[[0,0],[0,6]],[[4,0],[0,3.6]],[[1.4,2.4],[4,6]]],
        'L'=>[[[0,0],[0,6],[4,6]]],
        'M'=>[[[0,6],[0,0],[2,3.5],[4,0],[4,6]]],
        'N'=>[[[0,6],[0,0],[4,6],[4,0]]],
        'O'=>[[[1,0],[3,0],[4,1],[4,5],[3,6],[1,6],[0,5],[0,1],[1,0]]],
        'P'=>[[[0,6],[0,0],[3,0],[4,1],[4,2],[3,3],[0,3]]],
        'Q'=>[[[1,0],[3,0],[4,1],[4,5],[3,6],[1,6],[0,5],[0,1],[1,0]],[[2.4,4.4],[4,6.2]]],
        'R'=>[[[0,6],[0,0],[3,0],[4,1],[4,2],[3,3],[0,3]],[[2,3],[4,6]]],
        'S'=>[[[4,1],[3,0],[1,0],[0,1],[0,2],[1,3],[3,3],[4,4],[4,5],[3,6],[1,6],[0,5]]],
        'T'=>[[[0,0],[4,0]],[[2,0],[2,6]]],
        'U'=>[[[0,0],[0,5],[1,6],[3,6],[4,5],[4,0]]],
        'V'=>[[[0,0],[2,6],[4,0]]],
        'W'=>[[[0,0],[1,6],[2,2.5],[3,6],[4,0]]],
        'X'=>[[[0,0],[4,6]],[[4,0],[0,6]]],
        'Y'=>[[[0,0],[2,3],[4,0]],[[2,3],[2,6]]],
        'Z'=>[[[0,0],[4,0],[0,6],[4,6]]],
        '0'=>[[[1,0],[3,0],[4,1],[4,5],[3,6],[1,6],[0,5],[0,1],[1,0]],[[3.4,1],[0.6,5]]],
        '1'=>[[[1,1],[2,0],[2,6]],[[1,6],[3,6]]],
        '2'=>[[[0,1],[1,0],[3,0],[4,1],[4,2],[0,6],[4,6]]],
        '3'=>[[[0,1],[1,0],[3,0],[4,1],[4,2],[3,3],[1.5,3]],[[3,3],[4,4],[4,5],[3,6],[1,6],[0,5]]],
        '4'=>[[[3,6],[3,0],[0,4],[4,4]]],
        '5'=>[[[4,0],[0,0],[0,3],[3,3],[4,4],[4,5],[3,6],[1,6],[0,5]]],
        '6'=>[[[3.5,0],[1,0],[0,1],[0,5],[1,6],[3,6],[4,5],[4,4],[3,3],[0,3]]],
        '7'=>[[[0,0],[4,0],[1.5,6]]],
        '8'=>[[[1,3],[0,2],[0,1],[1,0],[3,0],[4,1],[4,2],[3,3],[1,3],[0,4],[0,5],[1,6],[3,6],[4,5],[4,4],[3,3]]],
        '9'=>[[[4,3],[1,3],[0,2],[0,1],[1,0],[3,0],[4,1],[4,5],[3,6],[0.5,6]]],
        '.'=>[[[1.8,5.5],[2.2,5.5],[2.2,6],[1.8,6],[1.8,5.5]]],
        ','=>[[[2.2,5.4],[2.2,6],[1.5,7]]],
        '!'=>[[[2,0],[2,4.2]],[[1.8,5.5],[2.2,5.5],[2.2,6],[1.8,6],[1.8,5.5]]],
        '?'=>[[[0,1],[1,0],[3,0],[4,1],[4,2],[2,3.4],[2,4.3]],[[1.8,5.5],[2.2,5.5],[2.2,6],[1.8,6],[1.8,5.5]]],
        ':'=>[[[1.8,1.8],[2.2,1.8],[2.2,2.3],[1.8,2.3],[1.8,1.8]],[[1.8,5.5],[2.2,5.5],[2.2,6],[1.8,6],[1.8,5.5]]],
        ';'=>[[[1.8,1.8],[2.2,1.8],[2.2,2.3],[1.8,2.3],[1.8,1.8]],[[2.2,5.4],[2.2,6],[1.5,7]]],
        '-'=>[[[1,3.3],[3,3.3]]],
        '—'=>[[[-0.5,3.3],[4.5,3.3]]],
        '–'=>[[[0,3.3],[4,3.3]]],
        '_'=>[[[0,6.6],[4,6.6]]],
        '\''=>[[[2,0],[2,1.6]]],
        '"'=>[[[1.3,0],[1.3,1.6]],[[2.7,0],[2.7,1.6]]],
        '('=>[[[2.8,-0.3],[1.6,1],[1.4,3],[1.6,5],[2.8,6.3]]],
        ')'=>[[[1.2,-0.3],[2.4,1],[2.6,3],[2.4,5],[1.2,6.3]]],
        '['=>[[[2.8,-0.3],[1.4,-0.3],[1.4,6.3],[2.8,6.3]]],
        ']'=>[[[1.2,-0.3],[2.6,-0.3],[2.6,6.3],[1.2,6.3]]],
        '/'=>[[[4,0],[0,6]]],
        '\\'=>[[[0,0],[4,6]]],
        '+'=>[[[2,1.5],[2,4.5]],[[0.5,3],[3.5,3]]],
        '='=>[[[0.5,2.2],[3.5,2.2]],[[0.5,3.8],[3.5,3.8]]],
        '*'=>[[[2,1],[2,5]],[[0.4,2],[3.6,4]],[[3.6,2],[0.4,4]]],
        '#'=>[[[1.3,0.5],[1,5.5]],[[3,0.5],[2.7,5.5]],[[0.2,2],[4,2]],[[0,4],[3.8,4]]],
        '%'=>[[[4,0],[0,6]],[[0.5,0.5],[1.3,0.5],[1.3,1.6],[0.5,1.6],[0.5,0.5]],[[2.7,4.4],[3.5,4.4],[3.5,5.5],[2.7,5.5],[2.7,4.4]]],
        '&'=>[[[4,6],[1,2],[1,1],[2,0],[3,1],[3,2],[0,4.5],[0,5.2],[1,6],[2.5,6],[4,4]]],
        '@'=>[[[3,4],[3,2],[1.8,2],[1.2,3],[1.8,4],[3,4],[4,3.6],[4,1],[3,0],[1,0],[0,1],[0,5],[1,6],[3.5,6]]],
        '<'=>[[[3.5,1],[0.5,3],[3.5,5]]],
        '>'=>[[[0.5,1],[3.5,3],[0.5,5]]],
        '~'=>[[[0.3,3.4],[1.2,2.6],[2.8,3.4],[3.7,2.6]]],
        '$'=>[[[4,1],[3,0.5],[1,0.5],[0,1.5],[1,3],[3,3],[4,4.5],[3,5.5],[1,5.5],[0,5]],[[2,-0.3],[2,6.3]]],
        '♪'=>[[[1.5,6],[0.5,5.5],[1,4.6],[2,4.8],[2,0],[3.8,1]]],
        '♥'=>[[[2,6],[0.2,3],[0,1.5],[1,0.5],[2,1.4],[3,0.5],[4,1.5],[3.8,3],[2,6]]],
    ];
    /** Accents over (or under) a letter, on the same grid. */
    private const MARKS = [
        'acute'=>[[[1.6,-0.6],[2.8,-2]]],
        'grave'=>[[[1.2,-2],[2.4,-0.6]]],
        'circ'=>[[[1,-0.6],[2,-1.8],[3,-0.6]]],
        'tilde'=>[[[0.6,-0.8],[1.4,-1.6],[2.6,-0.9],[3.4,-1.7]]],
        'uml'=>[[[1.1,-1.4],[1.1,-0.8]],[[2.9,-1.4],[2.9,-0.8]]],
        'ring'=>[[[1.5,-1],[2,-1.6],[2.5,-1],[2,-0.4],[1.5,-1]]],
        'cedil'=>[[[2,6],[2.5,6.8],[1.6,7.4]]],
    ];
    private const COMPOSED = [
        'Á'=>['A','acute'],'À'=>['A','grave'],'Â'=>['A','circ'],'Ã'=>['A','tilde'],'Ä'=>['A','uml'],'Å'=>['A','ring'],
        'É'=>['E','acute'],'È'=>['E','grave'],'Ê'=>['E','circ'],'Ë'=>['E','uml'],
        'Í'=>['I','acute'],'Ì'=>['I','grave'],'Î'=>['I','circ'],'Ï'=>['I','uml'],
        'Ó'=>['O','acute'],'Ò'=>['O','grave'],'Ô'=>['O','circ'],'Õ'=>['O','tilde'],'Ö'=>['O','uml'],
        'Ú'=>['U','acute'],'Ù'=>['U','grave'],'Û'=>['U','circ'],'Ü'=>['U','uml'],
        'Ç'=>['C','cedil'],'Ñ'=>['N','tilde'],'Ý'=>['Y','acute'],
    ];
    private const ALIASES = ['“'=>'"','”'=>'"','„'=>'"','«'=>'"','»'=>'"','‘'=>'\'','’'=>'\'','…'=>'...','¡'=>'!','¿'=>'?','×'=>'X','·'=>'.','•'=>'.','ß'=>'SS','Æ'=>'AE','Œ'=>'OE','Ø'=>'0'];
    private const NAMED = ['black'=>[0,0,0],'white'=>[255,255,255],'red'=>[220,30,30],'green'=>[30,140,50],'blue'=>[30,70,220],'yellow'=>[240,210,30],
        'orange'=>[245,140,20],'purple'=>[130,50,160],'pink'=>[240,130,170],'brown'=>[130,80,40],'cyan'=>[0,200,220],'magenta'=>[210,40,180],'gray'=>[128,128,128],
        'grey'=>[128,128,128],'lightgray'=>[211,211,211],'lightgrey'=>[211,211,211],'darkgray'=>[90,90,90],'darkgrey'=>[90,90,90],'dimgray'=>[105,105,105],
        'dimgrey'=>[105,105,105],'silver'=>[192,192,192],'gainsboro'=>[220,220,220],'whitesmoke'=>[245,245,245],'navy'=>[0,0,128],'teal'=>[0,128,128],
        'maroon'=>[128,0,0],'olive'=>[128,128,0],'lime'=>[0,255,0],'gold'=>[255,215,0],'crimson'=>[220,20,60],'skyblue'=>[135,206,235],'beige'=>[245,245,220],
        'tan'=>[210,180,140],'khaki'=>[240,230,140],'salmon'=>[250,128,114],'violet'=>[238,130,238],'indigo'=>[75,0,130],'darkblue'=>[0,0,139],'darkred'=>[139,0,0],
        'darkgreen'=>[0,100,0],'lightblue'=>[173,216,230],'lightyellow'=>[255,255,224],'ivory'=>[255,255,240],'snow'=>[255,250,250],'slategray'=>[112,128,144]];
    private const DRAWN = ['rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path', 'text'];
    private const GROUPS = ['svg', 'g', 'a', 'switch'];

    // -----------------------------------------------------------------------------------------------------------------
    // The page's units
    // -----------------------------------------------------------------------------------------------------------------

    /** The sketch's size in its units for a page of this aspect ratio: 1000 wide, and the height that keeps it. */
    public static function units(string $aspect): array
    {
        [$w, $h] = array_map('floatval', explode(':', $aspect) + [1 => 1]);
        return [self::UNITS, (int)round(self::UNITS * ($h > 0 && $w > 0 ? $h / $w : 1.5))];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Checking and drawing
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * The SVG as it will be kept, once it is known to be one the app can draw. The messages are for the writer,
     * which wrote it (in English, as its tools speak).
     */
    public static function check(string $svg): string
    {
        $svg = trim(str_replace(["\r\n", "\r"], "\n", $svg));
        if ($svg === '') throw new HttpError(422, 'sketch_svg is empty.');
        if (strlen($svg) > self::MAX_SVG) throw new HttpError(422, 'sketch_svg is too long ('.number_format(strlen($svg)).' characters; at most '.number_format(self::MAX_SVG).'). Draw it more simply: a storyboard needs only rough shapes.');
        if (!mb_check_encoding($svg, 'UTF-8')) throw new HttpError(422, 'sketch_svg is not valid UTF-8.');
        $root = self::parse($svg);
        $drawn = 0; $walk = function (array $n) use (&$walk, &$drawn): void { if (in_array($n['name'], self::DRAWN, true)) $drawn++; foreach ($n['children'] as $c) if (is_array($c)) $walk($c); };
        $walk($root);
        if (!$drawn) throw new HttpError(422, 'sketch_svg draws nothing the app can draw. Use rect, circle, ellipse, line, polyline, polygon, path and text inside <svg viewBox="...">.');
        return $svg;
    }

    /** The SVG drawn as a PNG of $width × $height pixels, on white, fitted into it without distortion. */
    public static function png(string $svg, int $width, int $height): string
    {
        $root = self::parse($svg);
        $W = $width * self::SS; $H = $height * self::SS;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $box = self::viewBox($root['attrs']);
        [$minX, $minY, $vw, $vh] = $box ?? [0.0, 0.0, (float)$width, (float)$height];
        $s = min($W / $vw, $H / $vh);
        $base = [$s, 0.0, 0.0, $s, ($W - $vw * $s) / 2 - $minX * $s, ($H - $vh * $s) / 2 - $minY * $s];
        $state = new SketchState($im, $W, $H, $vw, $vh);
        self::drawNode($root, $base, self::ROOT_STYLE, $state, 0);
        $out = imagecreatetruecolor($width, $height);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $width, $height, $W, $H);
        imagedestroy($im);
        ob_start(); imagepng($out, null, 6); $png = (string)ob_get_clean();
        imagedestroy($out);
        return $png;
    }

    private const ROOT_STYLE = ['fill'=>[0, 0, 0], 'stroke'=>null, 'stroke-width'=>1.0, 'opacity'=>1.0, 'fill-opacity'=>1.0, 'stroke-opacity'=>1.0,
        'font-size'=>16.0, 'font-weight'=>'normal', 'text-anchor'=>'start', 'dash'=>[], 'linecap'=>'butt'];

    /** @return ?array{0:float,1:float,2:float,3:float} */
    private static function viewBox(array $a): ?array
    {
        if (isset($a['viewbox'])) {
            $n = self::numbers($a['viewbox']);
            if (count($n) === 4 && $n[2] > 0 && $n[3] > 0) return [$n[0], $n[1], $n[2], $n[3]];
        }
        $w = isset($a['width']) ? self::plain($a['width']) : null; $h = isset($a['height']) ? self::plain($a['height']) : null;
        return $w && $h && $w > 0 && $h > 0 ? [0.0, 0.0, $w, $h] : null;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Reading the SVG
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * The elements as a tree: name (lower case), attributes (lower-case names, style="" folded in) and children
     * (elements, or strings of text). Read leniently: a storyboard with a tag left open still draws.
     * @return array{name:string,attrs:array<string,string>,children:list<array|string>}
     */
    private static function parse(string $svg): array
    {
        $svg = preg_replace(['/<!--.*?-->/s', '/<\?.*?\?>/s', '/<!DOCTYPE[^>]*>/i'], '', $svg) ?? '';
        $svg = preg_replace_callback('/<!\[CDATA\[(.*?)\]\]>/s', fn($m) => htmlspecialchars($m[1], ENT_XML1), $svg) ?? '';
        if (!preg_match_all('/<(\/?)([A-Za-z][\w:.-]*)((?:\s+[^\s=\/>]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>|([^<]+)/s', $svg, $tokens, PREG_SET_ORDER))
            throw new HttpError(422, 'sketch_svg is not SVG: it must start with <svg viewBox="...">.');
        $root = null; $stack = []; $count = 0;
        foreach ($tokens as $t) {
            if (($t[5] ?? '') !== '') {
                if ($stack) { $text = html_entity_decode($t[5], ENT_XML1 | ENT_QUOTES, 'UTF-8'); if (trim($text) !== '') $stack[count($stack) - 1]['children'][] = $text; }
                continue;
            }
            $name = strtolower(preg_replace('/^.*:/', '', $t[2]) ?? $t[2]);
            if ($t[1] === '/') {
                // Closes the innermost open element of that name (and whatever was left open inside it).
                for ($i = count($stack) - 1; $i >= 0; $i--) if ($stack[$i]['name'] === $name) break;
                if ($i < 0) continue;
                while (count($stack) > $i) self::close($stack, $root);
                continue;
            }
            if (++$count > self::MAX_ELEMENTS) throw new HttpError(422, 'sketch_svg has more than '.self::MAX_ELEMENTS.' elements. Draw it more simply.');
            if (count($stack) > 40) throw new HttpError(422, 'sketch_svg nests elements too deeply.');
            $attrs = [];
            if (preg_match_all('/([^\s=\/>]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $t[3], $found, PREG_SET_ORDER)) foreach ($found as $f) {
                $attrs[strtolower(preg_replace('/^xlink:/', '', $f[1]) ?? $f[1])] = html_entity_decode(($f[2] ?? '') !== '' ? $f[2] : ($f[3] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
            // style="a: b; c: d" counts as attributes (and wins over them, as in CSS).
            if (isset($attrs['style'])) { foreach (explode(';', $attrs['style']) as $decl) if (str_contains($decl, ':')) { [$k, $v] = explode(':', $decl, 2); $attrs[strtolower(trim($k))] = trim(preg_replace('/!important/i', '', $v) ?? $v); } unset($attrs['style']); }
            $stack[] = ['name'=>$name, 'attrs'=>$attrs, 'children'=>[]];
            if ($t[4] === '/') self::close($stack, $root);
        }
        while ($stack) self::close($stack, $root);
        if ($root === null || $root['name'] !== 'svg') throw new HttpError(422, 'sketch_svg is not SVG: it must be one <svg viewBox="..."> element with the drawing inside.');
        return $root;
    }

    private static function close(array &$stack, ?array &$root): void
    {
        $node = array_pop($stack);
        if ($stack) $stack[count($stack) - 1]['children'][] = $node;
        elseif ($root === null) $root = $node;
    }

    /** @return list<float> */
    private static function numbers(string $s): array
    {
        preg_match_all('/[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?/', $s, $m);
        return array_map('floatval', $m[0]);
    }

    /** A length in user units: a number, with px or another unit (taken as is), em (of the font size) or % (of the view). */
    private static function length(?string $v, float $percentOf = 0.0, float $em = 16.0): ?float
    {
        if ($v === null) return null;
        if (!preg_match('/^\s*([-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?)\s*(%|em|ex|px|pt|pc|mm|cm|in)?\s*$/i', $v, $m)) return null;
        $n = (float)$m[1]; $unit = strtolower($m[2] ?? '');
        return match ($unit) { '%'=>$n * $percentOf / 100, 'em'=>$n * $em, 'ex'=>$n * $em / 2, default=>$n };
    }

    private static function plain(string $v): ?float { return self::length($v); }

    /** A color: [r, g, b], null for none, or 'current' resolved by the caller. */
    private static function paint(string $v): ?array
    {
        $v = strtolower(trim($v));
        if ($v === '' || $v === 'none' || $v === 'transparent') return null;
        if (preg_match('/^#([0-9a-f]{3,4})$/', $v, $m)) return [hexdec($m[1][0]) * 17, hexdec($m[1][1]) * 17, hexdec($m[1][2]) * 17];
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $v, $m)) return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
        if (preg_match('/^rgba?\(([^)]*)\)$/', $v, $m)) {
            $parts = array_map('trim', preg_split('/[\s,\/]+/', trim($m[1])) ?: []);
            $c = [];
            for ($i = 0; $i < 3; $i++) { $p = $parts[$i] ?? '0'; $c[] = (int)max(0, min(255, str_ends_with($p, '%') ? (float)$p * 2.55 : (float)$p)); }
            return $c;
        }
        // A gradient or a pattern is drawn as a light gray: the storyboard needs the shape, not the shading.
        if (str_starts_with($v, 'url(')) return [200, 200, 200];
        if ($v === 'currentcolor') return [0, 0, 0];
        return self::NAMED[$v] ?? [0, 0, 0];
    }

    /** The style an element draws with: its parent's, changed by its own attributes. */
    private static function style(array $attrs, array $parent): ?array
    {
        $s = $parent;
        if (($attrs['display'] ?? '') === 'none' || in_array($attrs['visibility'] ?? '', ['hidden', 'collapse'], true)) return null;
        if (isset($attrs['fill'])) $s['fill'] = self::paint($attrs['fill']);
        if (isset($attrs['stroke'])) $s['stroke'] = self::paint($attrs['stroke']);
        if (isset($attrs['font-size'])) { $f = self::length($attrs['font-size'], $parent['font-size'] * 100 / 100, $parent['font-size']); if ($f !== null && $f > 0) $s['font-size'] = $f; }
        if (isset($attrs['stroke-width'])) { $w = self::length($attrs['stroke-width'], 10.0, $s['font-size']); if ($w !== null && $w >= 0) $s['stroke-width'] = $w; }
        foreach (['fill-opacity', 'stroke-opacity'] as $k) if (isset($attrs[$k]) && is_numeric(trim($attrs[$k]))) $s[$k] = max(0.0, min(1.0, (float)$attrs[$k]));
        // Opacity isn't inherited in SVG, but a group's opacity reaches what is inside it: the same, multiplied down.
        if (isset($attrs['opacity']) && is_numeric(trim($attrs['opacity']))) $s['opacity'] = $parent['opacity'] * max(0.0, min(1.0, (float)$attrs['opacity']));
        if (isset($attrs['font-weight'])) $s['font-weight'] = in_array(strtolower($attrs['font-weight']), ['bold', 'bolder', '600', '700', '800', '900'], true) ? 'bold' : 'normal';
        if (isset($attrs['text-anchor'])) $s['text-anchor'] = in_array($attrs['text-anchor'], ['middle', 'end'], true) ? $attrs['text-anchor'] : 'start';
        if (isset($attrs['stroke-dasharray'])) { $d = array_values(array_filter(self::numbers($attrs['stroke-dasharray']), fn($x) => $x >= 0)); $s['dash'] = array_sum($d) > 0 ? (count($d) % 2 ? [...$d, ...$d] : $d) : []; }
        if (isset($attrs['stroke-linecap'])) $s['linecap'] = in_array($attrs['stroke-linecap'], ['round', 'square'], true) ? $attrs['stroke-linecap'] : 'butt';
        return $s;
    }

    /** The element's transform, as the matrix [a, b, c, d, e, f] (x' = ax + cy + e, y' = bx + dy + f). */
    private static function transform(?string $t): array
    {
        $m = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        if ($t === null || !preg_match_all('/(matrix|translate|scale|rotate|skewx|skewy)\s*\(([^)]*)\)/i', $t, $found, PREG_SET_ORDER)) return $m;
        foreach ($found as $f) {
            $n = self::numbers($f[2]);
            $op = match (strtolower($f[1])) {
                'matrix'=>count($n) >= 6 ? array_slice($n, 0, 6) : null,
                'translate'=>[1.0, 0.0, 0.0, 1.0, $n[0] ?? 0.0, $n[1] ?? 0.0],
                'scale'=>[$n[0] ?? 1.0, 0.0, 0.0, $n[1] ?? ($n[0] ?? 1.0), 0.0, 0.0],
                'rotate'=>self::rotation($n[0] ?? 0.0, $n[1] ?? 0.0, $n[2] ?? 0.0),
                'skewx'=>[1.0, 0.0, tan(deg2rad($n[0] ?? 0.0)), 1.0, 0.0, 0.0],
                'skewy'=>[1.0, tan(deg2rad($n[0] ?? 0.0)), 0.0, 1.0, 0.0, 0.0],
            };
            if ($op !== null) $m = self::multiply($m, $op);
        }
        return $m;
    }

    private static function rotation(float $deg, float $cx, float $cy): array
    {
        $a = deg2rad($deg); $c = cos($a); $s = sin($a);
        return [$c, $s, -$s, $c, $cx - $c * $cx + $s * $cy, $cy - $s * $cx - $c * $cy];
    }

    /** $m then $n: the matrix of applying $n first, then $m. */
    private static function multiply(array $m, array $n): array
    {
        return [$m[0] * $n[0] + $m[2] * $n[1], $m[1] * $n[0] + $m[3] * $n[1], $m[0] * $n[2] + $m[2] * $n[3], $m[1] * $n[2] + $m[3] * $n[3],
            $m[0] * $n[4] + $m[2] * $n[5] + $m[4], $m[1] * $n[4] + $m[3] * $n[5] + $m[5]];
    }

    private static function apply(array $m, float $x, float $y): array { return [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]]; }

    // -----------------------------------------------------------------------------------------------------------------
    // Shapes
    // -----------------------------------------------------------------------------------------------------------------

    private static function drawNode(array $node, array $matrix, array $parentStyle, SketchState $st, int $depth): void
    {
        $name = $node['name']; $a = $node['attrs'];
        if (!in_array($name, self::GROUPS, true) && !in_array($name, self::DRAWN, true)) return; // defs, image, style, script, filters…
        $style = self::style($a, $parentStyle);
        if ($style === null) return;
        $m = $depth === 0 ? $matrix : self::multiply($matrix, self::transform($a['transform'] ?? null));
        if (in_array($name, self::GROUPS, true)) { foreach ($node['children'] as $c) if (is_array($c)) self::drawNode($c, $m, $style, $st, $depth + 1); return; }
        if ($name === 'text') { self::text($node, $m, $style, $st); return; }
        $paths = self::shape($name, $a, $st, $style);
        if (!$paths) return;
        $device = [];
        foreach ($paths as [$points, $closed]) {
            $st->points += count($points);
            if ($st->points > self::MAX_POINTS) throw new HttpError(422, 'sketch_svg is too detailed to draw. Draw it more simply: a storyboard needs only rough shapes.');
            $device[] = [array_map(fn($p) => self::apply($m, $p[0], $p[1]), $points), $closed];
        }
        $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
        if ($style['fill'] !== null && $name !== 'line') foreach ($device as [$points]) if (count($points) >= 3) self::fill($st, $points, $style['fill'], $style['opacity'] * $style['fill-opacity']);
        if ($style['stroke'] !== null && $style['stroke-width'] > 0) {
            $width = $style['stroke-width'] * $scale;
            $dash = array_map(fn($d) => $d * $scale, $style['dash']);
            foreach ($device as [$points, $closed]) self::stroke($st, $closed && count($points) > 2 ? [...$points, $points[0]] : $points, $width, $style['stroke'], $style['opacity'] * $style['stroke-opacity'], $dash, $style['linecap']);
            // An arrowhead for a line that names a marker (the writer's arrows of movement or of reading).
            foreach (['marker-end'=>true, 'marker-start'=>false] as $marker => $atEnd) {
                if (!isset($a[$marker]) || in_array(trim($a[$marker]), ['', 'none'], true) || !$device) continue;
                $points = $atEnd ? end($device)[0] : $device[0][0];
                if (count($points) < 2) continue;
                [$tip, $from] = $atEnd ? [$points[count($points) - 1], $points[count($points) - 2]] : [$points[0], $points[1]];
                self::arrowhead($st, $tip, $from, max($width * 4, 9 * self::SS), $style['stroke'], $style['opacity'] * $style['stroke-opacity']);
            }
        }
    }

    /** @return list<array{0:list<array{0:float,1:float}>,1:bool}> the shape's outlines in its own units, each closed or not */
    private static function shape(string $name, array $a, SketchState $st, array $style): array
    {
        $len = fn(string $k, float $of = 0.0) => self::length($a[$k] ?? null, $of, $style['font-size']) ?? 0.0;
        switch ($name) {
            case 'rect': {
                $x = $len('x', $st->vw); $y = $len('y', $st->vh); $w = $len('width', $st->vw); $h = $len('height', $st->vh);
                if ($w <= 0 || $h <= 0) return [];
                $rx = isset($a['rx']) ? $len('rx', $st->vw) : null; $ry = isset($a['ry']) ? $len('ry', $st->vh) : null;
                $rx ??= $ry ?? 0.0; $ry ??= $rx; $rx = min($rx, $w / 2); $ry = min($ry, $h / 2);
                if ($rx <= 0 || $ry <= 0) return [[[[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]], true]];
                $p = [];
                foreach ([[$x + $w - $rx, $y + $ry, -90], [$x + $w - $rx, $y + $h - $ry, 0], [$x + $rx, $y + $h - $ry, 90], [$x + $rx, $y + $ry, 180]] as [$cx, $cy, $start])
                    for ($k = 0; $k <= 8; $k++) { $t = deg2rad($start + $k * 90 / 8); $p[] = [$cx + $rx * cos($t), $cy + $ry * sin($t)]; }
                return [[$p, true]];
            }
            case 'circle': case 'ellipse': {
                $cx = $len('cx', $st->vw); $cy = $len('cy', $st->vh);
                if ($name === 'circle') { $rx = $ry = $len('r', sqrt(($st->vw ** 2 + $st->vh ** 2) / 2)); }
                else { $rx = $len('rx', $st->vw); $ry = $len('ry', $st->vh); }
                if ($rx <= 0 || $ry <= 0) return [];
                $n = (int)max(20, min(160, ceil(max($rx, $ry) / 3)));
                $p = []; for ($k = 0; $k < $n; $k++) { $t = 2 * M_PI * $k / $n; $p[] = [$cx + $rx * cos($t), $cy + $ry * sin($t)]; }
                return [[$p, true]];
            }
            case 'line':
                return [[[[$len('x1', $st->vw), $len('y1', $st->vh)], [$len('x2', $st->vw), $len('y2', $st->vh)]], false]];
            case 'polyline': case 'polygon': {
                $n = self::numbers($a['points'] ?? ''); $p = [];
                for ($k = 0; $k + 1 < count($n); $k += 2) $p[] = [$n[$k], $n[$k + 1]];
                return count($p) >= 2 ? [[$p, $name === 'polygon']] : [];
            }
            case 'path': return self::path($a['d'] ?? '');
        }
        return [];
    }

    /** A path's outlines, with its curves and arcs as short straight lines. */
    private static function path(string $d): array
    {
        $out = []; $cur = []; $x = $y = 0.0; $sx = $sy = 0.0; $cx = $cy = null; $cmd = ''; $prev = '';
        $i = 0; $n = strlen($d);
        $skip = function () use ($d, $n, &$i): void { while ($i < $n && (ctype_space($d[$i]) || $d[$i] === ',')) $i++; };
        $number = function () use ($d, $n, &$i, $skip): ?float {
            $skip();
            if (!preg_match('/\G[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?/', $d, $m, 0, $i)) return null;
            $i += strlen($m[0]); return (float)$m[0];
        };
        // An arc's flags are single digits, which may be written together ("a10 10 0 01 20 0").
        $flag = function () use ($d, $n, &$i, $skip): ?float { $skip(); if ($i < $n && ($d[$i] === '0' || $d[$i] === '1')) return (float)$d[$i++]; return null; };
        $flush = function (bool $closed) use (&$cur, &$out): void { if (count($cur) >= 2) $out[] = [$cur, $closed]; $cur = []; };
        while (true) {
            $skip();
            if ($i >= $n) break;
            if (ctype_alpha($d[$i])) { $cmd = $d[$i++]; if (strtolower($cmd) === 'z') {
                $flush(true); $x = $sx; $y = $sy; $cx = $cy = null; $prev = 'z'; continue; } }
            elseif ($cmd === '' || strtolower($cmd) === 'z') break;
            $rel = ctype_lower($cmd); $c = strtolower($cmd);
            $ox = $rel ? $x : 0.0; $oy = $rel ? $y : 0.0;
            if ($c === 'm') {
                $px = $number(); $py = $number(); if ($px === null || $py === null) break;
                $flush(false); $x = $ox + $px; $y = $oy + $py; $sx = $x; $sy = $y; $cur = [[$x, $y]];
                $cmd = $rel ? 'l' : 'L'; $cx = $cy = null; $prev = 'm'; continue;
            }
            if (!$cur) $cur = [[$x, $y]];
            if ($c === 'l') { $px = $number(); $py = $number(); if ($py === null) break; $x = $ox + $px; $y = $oy + $py; $cur[] = [$x, $y]; $cx = $cy = null; }
            elseif ($c === 'h') { $px = $number(); if ($px === null) break; $x = $ox + $px; $cur[] = [$x, $y]; $cx = $cy = null; }
            elseif ($c === 'v') { $py = $number(); if ($py === null) break; $y = ($rel ? $y : 0.0) + $py; $cur[] = [$x, $y]; $cx = $cy = null; }
            elseif ($c === 'c' || $c === 's') {
                if ($c === 'c') { $x1 = $number(); $y1 = $number(); if ($y1 === null) break; $x1 += $ox; $y1 += $oy; }
                else { [$x1, $y1] = in_array($prev, ['c', 's'], true) && $cx !== null ? [2 * $x - $cx, 2 * $y - $cy] : [$x, $y]; }
                $x2 = $number(); $y2 = $number(); $ex = $number(); $ey = $number(); if ($ey === null) break;
                $x2 += $ox; $y2 += $oy; $ex += $ox; $ey += $oy;
                for ($k = 1; $k <= 20; $k++) { $t = $k / 20; $u = 1 - $t;
                    $cur[] = [$u ** 3 * $x + 3 * $u * $u * $t * $x1 + 3 * $u * $t * $t * $x2 + $t ** 3 * $ex, $u ** 3 * $y + 3 * $u * $u * $t * $y1 + 3 * $u * $t * $t * $y2 + $t ** 3 * $ey]; }
                $cx = $x2; $cy = $y2; $x = $ex; $y = $ey;
            }
            elseif ($c === 'q' || $c === 't') {
                if ($c === 'q') { $x1 = $number(); $y1 = $number(); if ($y1 === null) break; $x1 += $ox; $y1 += $oy; }
                else { [$x1, $y1] = in_array($prev, ['q', 't'], true) && $cx !== null ? [2 * $x - $cx, 2 * $y - $cy] : [$x, $y]; }
                $ex = $number(); $ey = $number(); if ($ey === null) break; $ex += $ox; $ey += $oy;
                for ($k = 1; $k <= 16; $k++) { $t = $k / 16; $u = 1 - $t; $cur[] = [$u * $u * $x + 2 * $u * $t * $x1 + $t * $t * $ex, $u * $u * $y + 2 * $u * $t * $y1 + $t * $t * $ey]; }
                $cx = $x1; $cy = $y1; $x = $ex; $y = $ey;
            }
            elseif ($c === 'a') {
                $rx = $number(); $ry = $number(); $rot = $number(); $large = $flag(); $sweep = $flag(); $ex = $number(); $ey = $number();
                if ($ey === null || $sweep === null || $large === null) break;
                $ex += $ox; $ey += $oy;
                foreach (self::arc($x, $y, $rx, $ry, $rot, (bool)$large, (bool)$sweep, $ex, $ey) as $p) $cur[] = $p;
                $x = $ex; $y = $ey; $cx = $cy = null;
            }
            else break;
            $prev = $c;
        }
        $flush(false);
        return $out;
    }

    /** An elliptical arc of a path as points (SVG's endpoint parameterization, turned into the ellipse's center). */
    private static function arc(float $x1, float $y1, float $rx, float $ry, float $rot, bool $large, bool $sweep, float $x2, float $y2): array
    {
        $rx = abs($rx); $ry = abs($ry);
        if ($rx < 1e-9 || $ry < 1e-9 || ($x1 === $x2 && $y1 === $y2)) return [[$x2, $y2]];
        $phi = deg2rad($rot); $cos = cos($phi); $sin = sin($phi);
        $dx = ($x1 - $x2) / 2; $dy = ($y1 - $y2) / 2;
        $x1p = $cos * $dx + $sin * $dy; $y1p = -$sin * $dx + $cos * $dy;
        $lambda = ($x1p ** 2) / ($rx ** 2) + ($y1p ** 2) / ($ry ** 2);
        if ($lambda > 1) { $rx *= sqrt($lambda); $ry *= sqrt($lambda); }
        $num = $rx ** 2 * $ry ** 2 - $rx ** 2 * $y1p ** 2 - $ry ** 2 * $x1p ** 2; $den = $rx ** 2 * $y1p ** 2 + $ry ** 2 * $x1p ** 2;
        $coef = ($large === $sweep ? -1 : 1) * sqrt(max(0.0, $den > 0 ? $num / $den : 0.0));
        $cxp = $coef * $rx * $y1p / $ry; $cyp = -$coef * $ry * $x1p / $rx;
        $cx = $cos * $cxp - $sin * $cyp + ($x1 + $x2) / 2; $cy = $sin * $cxp + $cos * $cyp + ($y1 + $y2) / 2;
        $angle = fn($ux, $uy, $vx, $vy) => atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        $t1 = $angle(1, 0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $dt = $angle(($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry);
        if (!$sweep && $dt > 0) $dt -= 2 * M_PI; elseif ($sweep && $dt < 0) $dt += 2 * M_PI;
        $steps = (int)max(4, min(90, ceil(abs($dt) / (M_PI / 24))));
        $p = [];
        for ($k = 1; $k <= $steps; $k++) { $t = $t1 + $dt * $k / $steps; $p[] = [$cx + $rx * cos($t) * $cos - $ry * sin($t) * $sin, $cy + $rx * cos($t) * $sin + $ry * sin($t) * $cos]; }
        return $p;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Text, in the stroke font
    // -----------------------------------------------------------------------------------------------------------------

    private static function text(array $node, array $m, array $style, SketchState $st): void
    {
        $a = $node['attrs'];
        $x = self::length(self::firstOf($a['x'] ?? null), $st->vw, $style['font-size']) ?? 0.0;
        $y = self::length(self::firstOf($a['y'] ?? null), $st->vh, $style['font-size']) ?? 0.0;
        $x += self::length(self::firstOf($a['dx'] ?? null), $st->vw, $style['font-size']) ?? 0.0;
        $y += self::length(self::firstOf($a['dy'] ?? null), $st->vh, $style['font-size']) ?? 0.0;
        $pos = [$x, $y];
        foreach ($node['children'] as $c) {
            if (is_string($c)) { $pos = self::words($c, $pos, $m, $style, $st, true); continue; }
            if ($c['name'] !== 'tspan' && $c['name'] !== 'a') continue;
            $s = self::style($c['attrs'], $style); if ($s === null) continue;
            $ca = $c['attrs']; $fresh = false;
            if (isset($ca['x'])) { $pos[0] = self::length(self::firstOf($ca['x']), $st->vw, $s['font-size']) ?? $pos[0]; $fresh = true; }
            if (isset($ca['y'])) $pos[1] = self::length(self::firstOf($ca['y']), $st->vh, $s['font-size']) ?? $pos[1];
            $pos[0] += self::length(self::firstOf($ca['dx'] ?? null), $st->vw, $s['font-size']) ?? 0.0;
            $pos[1] += self::length(self::firstOf($ca['dy'] ?? null), $st->vh, $s['font-size']) ?? 0.0;
            $words = implode(' ', array_filter($c['children'], 'is_string'));
            $pos = self::words($words, $pos, $m, $s, $st, $fresh);
        }
    }

    private static function firstOf(?string $v): ?string { if ($v === null) return null; $p = preg_split('/[\s,]+/', trim($v)); return $p[0] ?? null; }

    /** Draws a run of text at $pos (its baseline), anchored as the style says. Returns where the next run starts. */
    private static function words(string $text, array $pos, array $m, array $style, SketchState $st, bool $anchored): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') return $pos;
        $chars = self::glyphRun($text);
        $u = $style['font-size'] * 0.7 / 6;              // one unit of the grid: the capitals are 0.7 of the font size
        $advance = 5.5 * $u; $width = count($chars) * $advance - 1.5 * $u;
        $x = $pos[0];
        if ($anchored) $x -= match ($style['text-anchor']) { 'middle'=>$width / 2, 'end'=>$width, default=>0.0 };
        $color = $style['fill'] ?? $style['stroke'];
        if ($color === null) return [$x + count($chars) * $advance, $pos[1]];
        $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
        $stroke = max(1.0, $style['font-size'] * ($style['font-weight'] === 'bold' ? 0.13 : 0.085) * $scale);
        $opacity = $style['opacity'] * ($style['fill'] !== null ? $style['fill-opacity'] : $style['stroke-opacity']);
        foreach ($chars as $n => $strokes) {
            $ox = $x + $n * $advance; $oy = $pos[1] - 6 * $u;
            foreach ($strokes as $line) {
                $points = array_map(fn($p) => self::apply($m, $ox + $p[0] * $u, $oy + $p[1] * $u), $line);
                $st->points += count($points);
                if ($st->points > self::MAX_POINTS) throw new HttpError(422, 'sketch_svg is too detailed to draw. Draw it more simply.');
                self::stroke($st, $points, $stroke, $color, $opacity, [], 'round');
            }
        }
        return [$x + count($chars) * $advance, $pos[1]];
    }

    /** @return list<list<list<array{0:float,1:float}>>> the strokes of each character of $text, in capitals */
    private static function glyphRun(string $text): array
    {
        $out = [];
        foreach (mb_str_split(mb_strtoupper($text, 'UTF-8')) as $ch) {
            $ch = self::ALIASES[$ch] ?? $ch;
            foreach (mb_str_split($ch) as $c) {
                if ($c === ' ') { $out[] = []; continue; }
                if (isset(self::COMPOSED[$c])) { [$base, $mark] = self::COMPOSED[$c]; $out[] = [...self::GLYPHS[$base], ...self::MARKS[$mark]]; continue; }
                $out[] = self::GLYPHS[$c] ?? self::GLYPHS['?'];
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Painting with GD
    // -----------------------------------------------------------------------------------------------------------------

    private static function color(SketchState $st, array $rgb, float $opacity): int
    {
        return imagecolorallocatealpha($st->im, $rgb[0], $rgb[1], $rgb[2], (int)round(127 - 127 * max(0.0, min(1.0, $opacity))));
    }

    /** @param list<array{0:float,1:float}> $points */
    private static function flat(array $points): array
    {
        $out = [];
        foreach ($points as $p) { $out[] = (int)round(max(-1e5, min(1e5, $p[0]))); $out[] = (int)round(max(-1e5, min(1e5, $p[1]))); }
        return $out;
    }

    private static function fill(SketchState $st, array $points, array $rgb, float $opacity): void
    {
        if ($opacity <= 0) return;
        imagefilledpolygon($st->im, self::flat($points), self::color($st, $rgb, $opacity));
    }

    /** A line through $points, $width pixels wide, with round joints; dashed when $dash has lengths. */
    private static function stroke(SketchState $st, array $points, float $width, array $rgb, float $opacity, array $dash, string $cap): void
    {
        if ($opacity <= 0 || count($points) < 2) return;
        $color = self::color($st, $rgb, $opacity);
        foreach ($dash ? self::dashes($points, $dash) : [$points] as $run) {
            if (count($run) < 2) continue;
            if ($width <= 1.6) {
                imagesetthickness($st->im, 1);
                for ($k = 1; $k < count($run); $k++) imageline($st->im, (int)round($run[$k - 1][0]), (int)round($run[$k - 1][1]), (int)round($run[$k][0]), (int)round($run[$k][1]), $color);
                continue;
            }
            $r = $width / 2;
            for ($k = 1; $k < count($run); $k++) {
                [$x1, $y1] = $run[$k - 1]; [$x2, $y2] = $run[$k];
                $len = hypot($x2 - $x1, $y2 - $y1);
                if ($len < 0.01) continue;
                $nx = -($y2 - $y1) / $len * $r; $ny = ($x2 - $x1) / $len * $r;
                imagefilledpolygon($st->im, self::flat([[$x1 + $nx, $y1 + $ny], [$x2 + $nx, $y2 + $ny], [$x2 - $nx, $y2 - $ny], [$x1 - $nx, $y1 - $ny]]), $color);
            }
            // Round joints, and round ends when asked for.
            $d = (int)max(2, round($width));
            for ($k = 1; $k < count($run) - 1; $k++) imagefilledellipse($st->im, (int)round($run[$k][0]), (int)round($run[$k][1]), $d, $d, $color);
            if ($cap === 'round') foreach ([$run[0], $run[count($run) - 1]] as $p) imagefilledellipse($st->im, (int)round($p[0]), (int)round($p[1]), $d, $d, $color);
            elseif (count($run) > 2 && abs($run[0][0] - $run[count($run) - 1][0]) < 0.5 && abs($run[0][1] - $run[count($run) - 1][1]) < 0.5) imagefilledellipse($st->im, (int)round($run[0][0]), (int)round($run[0][1]), $d, $d, $color);
        }
    }

    /** The pieces of a line drawn by a dash pattern (lengths on, off, on, off…). */
    private static function dashes(array $points, array $dash): array
    {
        $runs = []; $run = [$points[0]]; $k = 0; $left = $dash[0]; $on = true;
        for ($i = 1; $i < count($points); $i++) {
            [$x1, $y1] = $points[$i - 1]; [$x2, $y2] = $points[$i];
            $len = hypot($x2 - $x1, $y2 - $y1); $at = 0.0;
            $guard = 0;
            while ($len - $at > $left && $guard++ < 10000) {
                $at += $left; $t = $at / $len; $p = [$x1 + ($x2 - $x1) * $t, $y1 + ($y2 - $y1) * $t];
                if ($on) { $run[] = $p; $runs[] = $run; $run = []; } else $run = [$p];
                $on = !$on; $k = ($k + 1) % count($dash); $left = max(0.5, $dash[$k]);
            }
            $left -= $len - $at;
            if ($on) $run[] = [$x2, $y2];
        }
        if ($on && count($run) >= 2) $runs[] = $run;
        return $runs;
    }

    private static function arrowhead(SketchState $st, array $tip, array $from, float $size, array $rgb, float $opacity): void
    {
        $dx = $tip[0] - $from[0]; $dy = $tip[1] - $from[1]; $len = hypot($dx, $dy);
        if ($len < 0.01) return;
        $ux = $dx / $len; $uy = $dy / $len;
        $back = [$tip[0] - $ux * $size, $tip[1] - $uy * $size];
        self::fill($st, [$tip, [$back[0] - $uy * $size * 0.5, $back[1] + $ux * $size * 0.5], [$back[0] + $uy * $size * 0.5, $back[1] - $ux * $size * 0.5]], $rgb, $opacity);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The app's own sketch, from the page's script
    // -----------------------------------------------------------------------------------------------------------------

    /** How many panels go in each row, top to bottom, for a page of $n panels with no layout given. */
    public static function defaultRows(int $n): array
    {
        return [1=>[1], 2=>[1, 1], 3=>[1, 1, 1], 4=>[2, 2], 5=>[2, 1, 2], 6=>[2, 2, 2], 7=>[2, 3, 2], 8=>[3, 2, 3], 9=>[3, 3, 3], 10=>[3, 4, 3], 11=>[4, 3, 4], 12=>[4, 4, 4]][$n]
            ?? array_fill(0, (int)ceil($n / 4), 4);
    }

    /**
     * Where each panel is on the page, in the sketch's units, in reading order: rows from the top, and in each row from
     * the left (or from the right, for a page read right to left).
     * @return list<array{x:float,y:float,w:float,h:float}>
     */
    public static function grid(int $count, ?array $rows, bool $rtl, int $vw, int $vh): array
    {
        $rows = $rows && array_sum($rows) === $count && min($rows) >= 1 ? array_values($rows) : self::defaultRows($count);
        $margin = 40.0; $gutter = 22.0;
        $rowH = ($vh - 2 * $margin - $gutter * (count($rows) - 1)) / count($rows);
        $boxes = [];
        foreach ($rows as $r => $k) {
            $w = ($vw - 2 * $margin - $gutter * ($k - 1)) / $k;
            for ($i = 0; $i < $k; $i++) {
                $col = $rtl ? $k - 1 - $i : $i;
                $boxes[] = ['x'=>$margin + $col * ($w + $gutter), 'y'=>$margin + $r * ($rowH + $gutter), 'w'=>$w, 'h'=>$rowH];
            }
        }
        return array_slice($boxes, 0, $count);
    }

    /**
     * A rough sketch of the page drawn from its script, for a page the writer didn't draw: the panels in their order
     * with their numbers, a simple figure for each character where the script puts them, the balloons numbered in
     * reading order, the sound effects, and the setting and framing written small.
     */
    public static function auto(array $page, bool $rtl, string $aspect): string
    {
        [$vw, $vh] = self::units($aspect);
        $panels = $page['panels'] ?? [];
        $boxes = self::grid(max(1, count($panels)), $page['rows'] ?? null, $rtl, $vw, $vh);
        $e = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$vw.' '.$vh.'"><rect width="'.$vw.'" height="'.$vh.'" fill="#fff"/>';
        $balloon = 0;
        foreach ($boxes as $n => $b) {
            $p = $panels[$n] ?? [];
            ['x'=>$x, 'y'=>$y, 'w'=>$w, 'h'=>$h] = $b;
            $svg .= sprintf('<rect x="%.0f" y="%.0f" width="%.0f" height="%.0f" fill="#fff" stroke="#000" stroke-width="6"/>', $x, $y, $w, $h);
            $svg .= sprintf('<text x="%.0f" y="%.0f" font-size="40" font-weight="bold" fill="#999" text-anchor="%s">%d</text>', $rtl ? $x + $w - 14 : $x + 14, $y + 50, $rtl ? 'end' : 'start', $n + 1);
            $framing = mb_strtolower((string)($p['framing'] ?? ''));
            $close = (bool)preg_match('/close|detail|insert|extreme close|rosto|face/u', $framing);
            $wide = (bool)preg_match('/wide|establishing|long shot|extreme long|aerial|panoram/u', $framing);
            // The characters: where the script puts them, else spread across the panel.
            $people = array_values(array_filter($p['characters'] ?? [], 'is_array'));
            $count = count($people); $xs = [];
            foreach ($people as $k => $c) {
                $where = mb_strtolower((string)($c['where'] ?? ''));
                $fx = str_contains($where, 'left') ? 0.25 : (str_contains($where, 'right') ? 0.75 : (preg_match('/center|centre|middle/', $where) ? 0.5 : ($k + 1) / ($count + 1)));
                $depth = preg_match('/fore|front|close|near/', $where) ? 1.25 : (preg_match('/back|far|distance|behind/', $where) ? 0.6 : 1.0);
                $size = min($w, $h) * ($close ? 0.5 : ($wide ? 0.16 : 0.3)) * $depth;
                $cx = $x + $w * $fx; $ground = $y + $h * ($close ? 1.0 : 0.88) - ($depth < 1 ? $h * 0.12 : 0.0);
                $xs[] = $cx;
                $head = $size * 0.22;
                if ($close) {
                    $cy = $y + $h * 0.62;
                    $svg .= sprintf('<circle cx="%.0f" cy="%.0f" r="%.0f" fill="#e6e6e6" stroke="#333" stroke-width="4"/>', $cx, $cy - $head * 0.6, $head * 1.9);
                    $svg .= sprintf('<path d="M %.0f %.0f Q %.0f %.0f %.0f %.0f" fill="none" stroke="#333" stroke-width="4"/>', $cx - $head * 3.4, $y + $h, $cx, $cy + $head * 0.6, $cx + $head * 3.4, $y + $h);
                    $label = $cy - $head * 2.8;
                } else {
                    $top = $ground - $size;
                    $svg .= sprintf('<circle cx="%.0f" cy="%.0f" r="%.0f" fill="#e6e6e6" stroke="#333" stroke-width="4"/>', $cx, $top + $head, $head);
                    $svg .= sprintf('<polyline points="%.0f,%.0f %.0f,%.0f" fill="none" stroke="#333" stroke-width="5"/>', $cx, $top + 2 * $head, $cx, $ground - $size * 0.38);
                    $svg .= sprintf('<polyline points="%.0f,%.0f %.0f,%.0f %.0f,%.0f" fill="none" stroke="#333" stroke-width="5"/>', $cx - $size * 0.2, $top + $size * 0.48, $cx, $top + 2.6 * $head, $cx + $size * 0.2, $top + $size * 0.48);
                    $svg .= sprintf('<polyline points="%.0f,%.0f %.0f,%.0f %.0f,%.0f" fill="none" stroke="#333" stroke-width="5"/>', $cx - $size * 0.16, $ground, $cx, $ground - $size * 0.38, $cx + $size * 0.16, $ground);
                    $label = $top - 10;
                }
                $svg .= sprintf('<text x="%.0f" y="%.0f" font-size="20" fill="#333" text-anchor="middle">%s</text>', $cx, max($y + 30, $label), $e(mb_substr((string)($c['name'] ?? ''), 0, 18)));
            }
            // Balloons and captions along the top, in reading order; sound effects big, lower down.
            $texts = array_values(array_filter($p['text'] ?? [], fn($t) => is_array($t) && ($t['type'] ?? '') !== 'sfx'));
            $sfx = array_values(array_filter($p['text'] ?? [], fn($t) => is_array($t) && ($t['type'] ?? '') === 'sfx'));
            $slots = count($texts);
            foreach ($texts as $k => $t) {
                $balloon++;
                $type = (string)($t['type'] ?? 'speech');
                $bw = min($w / max(1, $slots) - 16, 70 + mb_strlen((string)($t['text'] ?? '')) * 2.6); $bw = max(70.0, $bw);
                $bh = min($h * 0.28, 52 + mb_strlen((string)($t['text'] ?? '')) * 0.5);
                $slot = $rtl ? $slots - 1 - $k : $k;
                $bx = $x + ($slot + 0.5) * $w / max(1, $slots); $by = $y + 62 + $bh / 2 + ($k % 2) * 16;
                $label = 'B'.$balloon;
                if ($type === 'caption' || $type === 'sign') {
                    $svg .= sprintf('<rect x="%.0f" y="%.0f" width="%.0f" height="%.0f" fill="#fff" stroke="#000" stroke-width="3"/>', $bx - $bw / 2, $by - $bh / 2, $bw, $bh);
                } elseif ($type === 'shout') {
                    $pts = []; for ($q = 0; $q < 18; $q++) { $r = $q % 2 ? 0.78 : 1.0; $a = 2 * M_PI * $q / 18; $pts[] = sprintf('%.0f,%.0f', $bx + $bw / 2 * $r * cos($a), $by + $bh / 2 * $r * sin($a)); }
                    $svg .= '<polygon points="'.implode(' ', $pts).'" fill="#fff" stroke="#000" stroke-width="3"/>';
                } else {
                    $svg .= sprintf('<ellipse cx="%.0f" cy="%.0f" rx="%.0f" ry="%.0f" fill="#fff" stroke="#000" stroke-width="3"%s/>', $bx, $by, $bw / 2, $bh / 2, in_array($type, ['whisper', 'thought'], true) ? ' stroke-dasharray="10 8"' : '');
                }
                // The tail points to whoever speaks, when the panel shows them.
                $who = mb_strtolower(trim((string)($t['by'] ?? '')));
                foreach ($people as $q => $c) if ($who !== '' && mb_strtolower(trim((string)($c['name'] ?? ''))) === $who && !in_array($type, ['caption', 'sign'], true))
                    $svg .= sprintf('<line x1="%.0f" y1="%.0f" x2="%.0f" y2="%.0f" stroke="#000" stroke-width="3"/>', $bx, $by + $bh / 2, $bx + ($xs[$q] - $bx) * 0.5, $by + $bh / 2 + 40);
                $svg .= sprintf('<text x="%.0f" y="%.0f" font-size="24" font-weight="bold" fill="#000" text-anchor="middle">%s</text>', $bx, $by + 9, $e($label));
            }
            foreach ($sfx as $k => $t) $svg .= sprintf('<text x="%.0f" y="%.0f" font-size="%d" font-weight="bold" fill="#777" text-anchor="middle" transform="rotate(-8 %.0f %.0f)">%s</text>',
                $x + $w * (0.5 + 0.2 * ($k % 2 ? 1 : -1) * min(1, count($sfx) - 1)), $y + $h * 0.55, (int)min(64, max(28, $w / 8)), $x + $w / 2, $y + $h * 0.55, $e(mb_substr((string)($t['text'] ?? ''), 0, 20)));
            $foot = trim(implode(' · ', array_filter([mb_substr((string)($p['place'] ?? ''), 0, 40), mb_substr((string)($p['framing'] ?? ''), 0, 40)])));
            if ($foot !== '') $svg .= sprintf('<text x="%.0f" y="%.0f" font-size="17" fill="#888" text-anchor="middle">%s</text>', $x + $w / 2, $y + $h - 12, $e($foot));
        }
        return $svg.'</svg>';
    }
}

/** What the drawing of one sketch keeps between shapes. */
final class SketchState
{
    public int $points = 0;
    public function __construct(public readonly \GdImage $im, public readonly int $width, public readonly int $height, public readonly float $vw, public readonly float $vh) {}
}
