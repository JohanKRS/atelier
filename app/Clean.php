<?php
declare(strict_types=1);
namespace Studio;

/**
 * Limpar on the server: the same filter as assets/clean.js, step by step and with the same numbers, for the requests
 * that don't come from the page (MCP). It removes the light dots and the fine web of lines that the image model leaves
 * on skin, hair and everything else, and knows nothing of what the picture shows. No API is called.
 *
 * 1. Dots: a pixel brighter or darker than every pixel of the ring 3 px around it, where that ring is itself even,
 *    takes the ring's median.
 * 2. Non-local means: every pixel becomes the weighted mean of the pixels up to SEARCH px away whose 7×7 surroundings
 *    look alike, with patch distances from running sums, a band of rows at a time.
 * 3. Edges back: wherever the cleaned image has an edge, the pixels of step 1 return.
 *
 * tests/clean_php.php checks that it gives what clean.js gives, to within one level of rounding.
 */
final class Clean
{
    /** strength: how different two patches may be and still average. keep/full: edge strength where the original starts to return and where it is fully back. */
    public const LEVELS = [
        'leve' => ['strength'=>6, 'keep'=>1.5, 'full'=>4],
        'media' => ['strength'=>8, 'keep'=>2, 'full'=>6],
        'forte' => ['strength'=>10, 'keep'=>3, 'full'=>8],
    ];
    private const SEARCH = 5, PATCH = 3, BAND = 192, RING = 3, DOT = 18, EVEN = 32;
    private const LUT_SIZE = 4096, LUT_MAX = 12;
    /** What one cleaned image may weigh, as the page keeps it (CLEANED_LIMIT in assets/app.js). */
    public const LIMIT = 13 * 1024 * 1024;
    /** The largest image cleaned here: about 4 bytes of PHP memory per pixel and channel, times a few. */
    public const MAX_PIXELS = 16_800_000;

    /**
     * Cleans an image file (PNG, JPEG or WebP) and gives it back as a file the library takes: a PNG, or a lossless WebP
     * and last a JPEG at 97 when the PNG is over LIMIT, as the page does. The image is cleaned in strips of rows, each
     * with the MARGIN rows around it that the filter looks at (as the page's workers do), so the memory stays bounded;
     * the strips join into exactly the whole image cleaned at once.
     * @param ?\Closure(float):void $progress called with 0..1
     * @return array{bytes:string,mime:string,width:int,height:int}
     */
    public static function file(string $bytes, string $level, ?\Closure $progress = null, int $stripPixels = self::STRIP_PIXELS): array
    {
        if (!isset(self::LEVELS[$level])) throw new HttpError(422, t('Unknown cleaning level.'));
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new HttpError(422, t('Could not read the image.'));
        $w = imagesx($image); $h = imagesy($image);
        if ($w * $h > self::MAX_PIXELS) { imagedestroy($image); throw new HttpError(422, t('This image is too large to clean on the server.')); }
        if (!imageistruecolor($image)) imagepalettetotruecolor($image);
        $result = imagecreatetruecolor($w, $h);
        imagealphablending($result, false);
        $alpha = false;
        $parts = self::strips($h, $w, $stripPixels);
        foreach ($parts as $n => $part) {
            // The strip with the rows around it; GD keeps 7 bits of alpha (0 opaque, 127 clear), which stays as it was.
            $R = []; $G = []; $B = [];
            for ($y = $part['top']; $y < $part['bottom']; $y++) for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($image, $x, $y);
                $R[] = ($c >> 16) & 0xFF; $G[] = ($c >> 8) & 0xFF; $B[] = $c & 0xFF;
            }
            $done = $n / count($parts); $share = 1 / count($parts);
            [$oR, $oG, $oB] = self::channels($R, $G, $B, $w, $part['bottom'] - $part['top'], self::LEVELS[$level],
                $progress === null ? null : static fn(float $d) => $progress(min(0.99, $done + $d * $share)));
            unset($R, $G, $B);
            for ($y = $part['from'], $i = ($part['from'] - $part['top']) * $w; $y < $part['to']; $y++) for ($x = 0; $x < $w; $x++, $i++) {
                $a = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                if ($a) $alpha = true;
                imagesetpixel($result, $x, $y, ($a << 24) | ($oR[$i] << 16) | ($oG[$i] << 8) | $oB[$i]);
            }
        }
        imagedestroy($image);
        imagesavealpha($result, $alpha);
        $file = self::encode($result, $alpha);
        imagedestroy($result);
        if ($progress !== null) $progress(1.0);
        return $file + ['width'=>$w, 'height'=>$h];
    }

    /** How far a pixel's result reaches: the ring of step 1, the search and patch of step 2, and the edge strength of step 3. */
    public const MARGIN = self::RING + self::SEARCH + self::PATCH + 4;
    /** About how many pixels one strip holds, with its margins: some 150 MB of PHP memory. */
    private const STRIP_PIXELS = 1_000_000;

    /**
     * The strips an image of this height and width is cleaned in: its own rows from..to, cleaned with the rows top..bottom
     * (strips() in assets/clean.js, with as many strips as the memory asks for).
     * @return list<array{from:int,to:int,top:int,bottom:int}>
     */
    public static function strips(int $height, int $width, int $pixels = self::STRIP_PIXELS): array
    {
        $rows = max(64, intdiv($pixels, max(1, $width)) - 2 * self::MARGIN);
        $n = max(1, (int)ceil($height / $rows));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $from = (int)round($i * $height / $n); $to = (int)round(($i + 1) * $height / $n);
            $out[] = ['from'=>$from, 'to'=>$to, 'top'=>max(0, $from - self::MARGIN), 'bottom'=>min($height, $to + self::MARGIN)];
        }
        return $out;
    }

    /** @return array{bytes:string,mime:string} */
    private static function encode(\GdImage $image, bool $alpha): array
    {
        ob_start(); imagepng($image, null, 6); $png = (string)ob_get_clean();
        if (strlen($png) <= self::LIMIT) return ['bytes'=>$png, 'mime'=>'image/png'];
        if (function_exists('imagewebp')) {
            ob_start(); imagewebp($image, null, defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : 100); $webp = (string)ob_get_clean();
            if ($webp !== '' && strlen($webp) <= self::LIMIT) return ['bytes'=>$webp, 'mime'=>'image/webp'];
        }
        if ($alpha) throw new HttpError(422, t('The cleaned image is over 13 MB and could not be saved.'));
        ob_start(); imagejpeg($image, null, 97); $jpeg = (string)ob_get_clean();
        if (strlen($jpeg) > self::LIMIT) throw new HttpError(422, t('The cleaned image is over 13 MB and could not be saved.'));
        return ['bytes'=>$jpeg, 'mime'=>'image/jpeg'];
    }

    /**
     * The filter on 4 values a pixel (alpha is not read, and comes back as it was). Same steps and numbers as clean()
     * in assets/clean.js.
     * @param list<int> $rgba
     * @return list<int>
     */
    public static function pixels(array $rgba, int $width, int $height, array $level, ?\Closure $progress = null): array
    {
        $N = $width * $height;
        if ($width < 1 || $height < 1 || count($rgba) !== $N * 4) throw new \InvalidArgumentException('Invalid image size.');
        $R = []; $G = []; $B = [];
        for ($p = 0; $p < $N * 4; $p += 4) { $R[] = $rgba[$p]; $G[] = $rgba[$p + 1]; $B[] = $rgba[$p + 2]; }
        [$oR, $oG, $oB] = self::channels($R, $G, $B, $width, $height, $level, $progress);
        $out = $rgba;
        for ($i = 0, $p = 0; $i < $N; $i++, $p += 4) { $out[$p] = $oR[$i]; $out[$p + 1] = $oG[$i]; $out[$p + 2] = $oB[$i]; }
        if ($progress !== null) $progress(1.0);
        return $out;
    }

    /**
     * The three steps on the red, green and blue values of an image.
     * @return array{0:list<int>,1:list<int>,2:list<int>}
     */
    private static function channels(array $R, array $G, array $B, int $W, int $H, array $level, ?\Closure $progress): array
    {
        $Y = [];
        foreach ($R as $i => $r) $Y[] = self::f32(0.299 * $r + 0.587 * $G[$i] + 0.114 * $B[$i]);
        self::removeDots($R, $G, $B, $Y, $W, $H);
        [$oR, $oG, $oB] = self::average($R, $G, $B, $Y, $W, $H, (float)$level['strength'], $progress === null ? null : fn(float $d) => $progress($d * 0.95));
        unset($Y);
        self::keepEdges($R, $G, $B, $oR, $oG, $oB, $W, $H, (float)$level['keep'], (float)$level['full']);
        return [$oR, $oG, $oB];
    }

    /** A value as a Float32Array keeps it. */
    private static function f32(float $v): float { return unpack('g', pack('g', $v))[1]; }

    /** What a Uint8ClampedArray keeps of a value: clamped to 0..255, rounded to the nearest, a half to the even one. */
    private static function u8(float $v): int
    {
        if (!($v > 0)) return 0;
        if ($v >= 255) return 255;
        return (int)round($v, 0, PHP_ROUND_HALF_EVEN);
    }

    /** Step 1, on the image as it was (the medians don't lean on one another's replacements). */
    private static function removeDots(array &$R, array &$G, array &$B, array &$Y, int $W, int $H): void
    {
        $ring = self::RING;
        if ($W <= 2 * $ring || $H <= 2 * $ring) return;
        $offsets = [];
        for ($dy = -$ring; $dy <= $ring; $dy++) for ($dx = -$ring; $dx <= $ring; $dx++) if (max(abs($dy), abs($dx)) === $ring) $offsets[] = $dy * $W + $dx;
        $count = count($offsets); $middle = $count >> 1; $hits = [];
        for ($y = $ring; $y < $H - $ring; $y++) {
            for ($x = $ring, $i = $y * $W + $ring; $x < $W - $ring; $x++, $i++) {
                $v = $Y[$i]; $lo = INF; $hi = -INF;
                foreach ($offsets as $o) { $r = $Y[$i + $o]; if ($r < $lo) $lo = $r; if ($r > $hi) $hi = $r; }
                if ($hi - $lo < self::EVEN && ($v - $hi > self::DOT || $lo - $v > self::DOT)) $hits[] = $i;
            }
        }
        $fixed = [];
        foreach ($hits as $i) {
            foreach ([$R, $G, $B] as $c => $channel) {
                $values = [];
                foreach ($offsets as $o) $values[] = $channel[$i + $o];
                sort($values);
                $fixed[$i][$c] = self::u8(($values[$middle - 1] + $values[$middle]) / 2);
            }
        }
        foreach ($fixed as $i => [$r, $g, $b]) {
            $R[$i] = $r; $G[$i] = $g; $B[$i] = $b;
            $Y[$i] = self::f32(0.299 * $r + 0.587 * $g + 0.114 * $b);
        }
    }

    /**
     * Step 2: non-local means, weights from the luminance.
     * @return array{0:list<int>,1:list<int>,2:list<int>}
     */
    private static function average(array $R, array $G, array $B, array $Y, int $W, int $H, float $strength, ?\Closure $progress): array
    {
        $LS = self::LUT_SIZE; $P = self::PATCH; $S = self::SEARCH;
        $lut = [];
        for ($k = 0; $k < $LS; $k++) $lut[$k] = self::f32(exp(-($k / $LS) * self::LUT_MAX));
        $area = (2 * $P + 1) ** 2;
        $scale = self::f32($LS / (self::LUT_MAX * $strength * $strength * $area));
        $oR = $R; $oG = $G; $oB = $B;
        $colOfs = [];
        for ($dx = -$S; $dx <= $S; $dx++) { $c = []; for ($x = 0; $x < $W; $x++) { $v = $x + $dx; $c[] = $v < 0 ? 0 : ($v >= $W ? $W - 1 : $v); } $colOfs[$dx] = $c; }
        for ($y0 = 0; $y0 < $H; $y0 += self::BAND) {
            $rows = min(self::BAND, $H - $y0); $er = $rows + 2 * $P; $n = $rows * $W;
            $aR = array_fill(0, $n, 0.0); $aG = $aR; $aB = $aR; $ws = $aR;
            $rowOf = [];
            for ($k = 0; $k < $er; $k++) { $v = $y0 - $P + $k; $rowOf[$k] = $v < 0 ? 0 : ($v >= $H ? $H - 1 : $v); }
            for ($dy = -$S; $dy <= $S; $dy++) {
                $shifted = [];
                for ($k = 0; $k < $er; $k++) { $v = $rowOf[$k] + $dy; $shifted[$k] = $v < 0 ? 0 : ($v >= $H ? $H - 1 : $v); }
                for ($dx = -$S; $dx <= $S; $dx++) {
                    $colOf = $colOfs[$dx];
                    // Squared luminance difference to the shifted image, summed over the patch along each row.
                    $T = [];
                    for ($k = 0; $k < $er; $k++) {
                        $a = $rowOf[$k] * $W; $b = $shifted[$k] * $W;
                        $D = [];
                        for ($x = 0; $x < $W; $x++) { $d = $Y[$a + $x] - $Y[$b + $colOf[$x]]; $D[] = $d * $d; }
                        $s = 0.0;
                        for ($x = -$P; $x <= $P; $x++) $s += $D[$x < 0 ? 0 : ($x >= $W ? $W - 1 : $x)];
                        for ($x = 0; $x < $W; $x++) {
                            $T[] = $s;
                            $hi = $x + $P + 1; $lo = $x - $P;
                            $s += $D[$hi >= $W ? $W - 1 : $hi] - $D[$lo < 0 ? 0 : $lo];
                        }
                    }
                    // Then along the columns, a row of the band at a time.
                    $col = array_fill(0, $W, 0.0);
                    for ($k = 0; $k < 2 * $P; $k++) { $o = $k * $W; for ($x = 0; $x < $W; $x++) $col[$x] += $T[$o + $x]; }
                    for ($r = 0; $r < $rows; $r++) {
                        $add = ($r + 2 * $P) * $W; $sub = $r * $W; $o = $r * $W; $src = $shifted[$r + $P] * $W;
                        for ($x = 0; $x < $W; $x++) {
                            $sum = $col[$x] + $T[$add + $x];
                            $col[$x] = $sum - $T[$sub + $x];
                            $k = $sum * $scale;
                            if ($k >= $LS) continue;
                            $w = $lut[(int)$k]; $q = $src + $colOf[$x]; $i = $o + $x;
                            $aR[$i] += $w * $R[$q]; $aG[$i] += $w * $G[$q]; $aB[$i] += $w * $B[$q]; $ws[$i] += $w;
                        }
                    }
                }
            }
            for ($i = 0, $p = $y0 * $W; $i < $n; $i++, $p++) {
                $w = $ws[$i];
                $oR[$p] = self::u8($aR[$i] / $w); $oG[$p] = self::u8($aG[$i] / $w); $oB[$p] = self::u8($aB[$i] / $w);
            }
            if ($progress !== null) $progress(($y0 + $rows) / $H);
        }
        return [$oR, $oG, $oB];
    }

    /** Step 3: where the cleaned image has an edge (its gradient, spread and smoothed), the pixels of step 1 return. */
    private static function keepEdges(array $R, array $G, array $B, array &$oR, array &$oG, array &$oB, int $W, int $H, float $keep, float $full): void
    {
        $N = $W * $H; $A = []; $E = [];
        for ($i = 0; $i < $N; $i++) $A[] = self::f32(0.299 * $oR[$i] + 0.587 * $oG[$i] + 0.114 * $oB[$i]);
        for ($y = 0; $y < $H; $y++) {
            $up = max(0, $y - 1) * $W; $mid = $y * $W; $down = min($H - 1, $y + 1) * $W;
            for ($x = 0; $x < $W; $x++) {
                $l = $x > 0 ? $x - 1 : 0; $r = $x < $W - 1 ? $x + 1 : $W - 1;
                $gx = $A[$up + $r] + 2 * $A[$mid + $r] + $A[$down + $r] - $A[$up + $l] - 2 * $A[$mid + $l] - $A[$down + $l];
                $gy = $A[$down + $l] + 2 * $A[$down + $x] + $A[$down + $r] - $A[$up + $l] - 2 * $A[$up + $x] - $A[$up + $r];
                $E[$mid + $x] = sqrt($gx * $gx + $gy * $gy) / 8;
            }
        }
        // Largest value in each 3×3 neighbourhood: along rows, then columns.
        for ($y = 0; $y < $H; $y++) {
            $o = $y * $W;
            for ($x = 0; $x < $W; $x++) $A[$o + $x] = max($E[$o + ($x > 0 ? $x - 1 : 0)], $E[$o + $x], $E[$o + ($x < $W - 1 ? $x + 1 : $W - 1)]);
        }
        for ($y = 0; $y < $H; $y++) {
            $up = max(0, $y - 1) * $W; $mid = $y * $W; $down = min($H - 1, $y + 1) * $W;
            for ($x = 0; $x < $W; $x++) $E[$mid + $x] = max($A[$up + $x], $A[$mid + $x], $A[$down + $x]);
        }
        // Mean over 5×5, the same way.
        $Rd = 2; $size = 2 * $Rd + 1;
        for ($y = 0; $y < $H; $y++) {
            $o = $y * $W; $s = 0.0;
            for ($x = -$Rd; $x <= $Rd; $x++) $s += $E[$o + ($x < 0 ? 0 : ($x >= $W ? $W - 1 : $x))];
            for ($x = 0; $x < $W; $x++) {
                $A[$o + $x] = $s / $size;
                $hi = $x + $Rd + 1; $lo = $x - $Rd;
                $s += $E[$o + ($hi >= $W ? $W - 1 : $hi)] - $E[$o + ($lo < 0 ? 0 : $lo)];
            }
        }
        $col = array_fill(0, $W, 0.0);
        for ($y = -$Rd; $y <= $Rd; $y++) { $o = ($y < 0 ? 0 : ($y >= $H ? $H - 1 : $y)) * $W; for ($x = 0; $x < $W; $x++) $col[$x] += $A[$o + $x]; }
        $range = $full - $keep;
        for ($y = 0; $y < $H; $y++) {
            $a = $y + $Rd + 1; $b = $y - $Rd;
            $add = ($a >= $H ? $H - 1 : $a) * $W; $sub = ($b < 0 ? 0 : $b) * $W;
            for ($x = 0; $x < $W; $x++) {
                $m = ($col[$x] / $size - $keep) / $range; $i = $y * $W + $x;
                $col[$x] += $A[$add + $x] - $A[$sub + $x];
                if ($m <= 0) continue;
                if ($m >= 1) { $oR[$i] = $R[$i]; $oG[$i] = $G[$i]; $oB[$i] = $B[$i]; continue; }
                $oR[$i] = self::u8($oR[$i] + $m * ($R[$i] - $oR[$i]));
                $oG[$i] = self::u8($oG[$i] + $m * ($G[$i] - $oG[$i]));
                $oB[$i] = self::u8($oB[$i] + $m * ($B[$i] - $oB[$i]));
            }
        }
    }
}
