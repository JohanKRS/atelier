<?php
declare(strict_types=1);
namespace Studio;

/**
 * What the page does to a picture on a canvas before a request, done with GD for clients that have no page (MCP): a
 * crop, the picture and the mask of Expandir, a painted area and Cobrir. Coordinates are the image's own pixels, as it
 * is stored (the width and height the library gives).
 *
 * A mask is the image API's: the size of the image, opaque where the image stays and transparent where it changes.
 */
final class Picture
{
    /** The most a picture made here may weigh, as the page's own (a crop, a cleaned image). */
    public const LIMIT = 13 * 1024 * 1024;
    /** The most pixels a picture is read or made at here: what the GPU's model takes, and what the library imports. */
    public const MAX_PIXELS = 40_000_000;

    /** An image as GD reads it: true color, its alpha kept as it is when drawn on. */
    public static function open(string $bytes): \GdImage
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] * $info[1] > self::MAX_PIXELS) throw new HttpError(422, t('Could not read the image.'));
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new HttpError(422, t('Could not read the image.'));
        if (!imageistruecolor($image)) imagepalettetotruecolor($image);
        imagealphablending($image, false); imagesavealpha($image, true);
        return $image;
    }

    /** A transparent canvas. */
    public static function canvas(int $width, int $height): \GdImage
    {
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) throw new HttpError(422, t('Invalid size.'));
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false); imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        return $canvas;
    }

    /** The part of an image inside a rectangle, as a picture of its own. */
    public static function crop(\GdImage $image, int $x, int $y, int $width, int $height): \GdImage
    {
        $out = self::canvas($width, $height);
        imagecopy($out, $image, 0, 0, $x, $y, $width, $height);
        return $out;
    }

    /**
     * The picture Expandir sends: a frame of $width × $height with the image at ($x, $y) in it (negative: cut off on that
     * side), transparent where the new areas are; and its mask, opaque over the part of the image that is kept.
     * @param array{x:int,y:int,width:int,height:int} $kept the part of the image the frame keeps, in the image's pixels
     * @return array{0:\GdImage,1:\GdImage}
     */
    public static function frame(\GdImage $image, int $width, int $height, int $x, int $y, array $kept): array
    {
        $picture = self::canvas($width, $height);
        imagecopy($picture, $image, $x + $kept['x'], $y + $kept['y'], $kept['x'], $kept['y'], $kept['width'], $kept['height']);
        $mask = self::canvas($width, $height);
        imagefilledrectangle($mask, $x + $kept['x'], $y + $kept['y'], $x + $kept['x'] + $kept['width'] - 1, $y + $kept['y'] + $kept['height'] - 1, imagecolorallocatealpha($mask, 0, 0, 0, 0));
        return [$picture, $mask];
    }

    /**
     * A mask with these shapes as the area to change (as the page's brush paints it): rectangles and ellipses, in pixels.
     * @param list<array{x:int,y:int,width:int,height:int,shape:string}> $shapes
     */
    public static function shapesMask(int $width, int $height, array $shapes): \GdImage
    {
        $mask = self::canvas($width, $height);
        imagefilledrectangle($mask, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($mask, 0, 0, 0, 0));
        self::clear($mask, $shapes);
        return $mask;
    }

    /** Makes the shapes transparent in a picture: the area to change in a mask, or the covered area erased (Cobrir). */
    public static function clear(\GdImage $image, array $shapes): void
    {
        imagealphablending($image, false);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        foreach ($shapes as $s) {
            if ($s['shape'] === 'ellipse') imagefilledellipse($image, $s['x'] + intdiv($s['width'], 2), $s['y'] + intdiv($s['height'], 2), $s['width'], $s['height'], $clear);
            else imagefilledrectangle($image, $s['x'], $s['y'], $s['x'] + $s['width'] - 1, $s['y'] + $s['height'] - 1, $clear);
        }
    }

    /**
     * A mask the client drew, as the image API's, at the image's size: where it is transparent (the image API's own
     * convention) the image changes; a mask with no transparency at all marks the area in white on black instead. A mask
     * of another size is stretched to the image. Null when it marks nothing.
     */
    public static function maskFrom(string $bytes, int $width, int $height): ?\GdImage
    {
        $drawn = self::open($bytes);
        if (imagesx($drawn) !== $width || imagesy($drawn) !== $height) {
            $scaled = self::canvas($width, $height);
            imagecopyresampled($scaled, $drawn, 0, 0, 0, 0, $width, $height, imagesx($drawn), imagesy($drawn));
            imagedestroy($drawn); $drawn = $scaled;
        }
        $transparent = false;
        for ($y = 0; $y < $height && !$transparent; $y++) for ($x = 0; $x < $width; $x++) if (((imagecolorat($drawn, $x, $y) >> 24) & 0x7F) >= 64) { $transparent = true; break; }
        $mask = self::shapesMask($width, $height, []);
        $clear = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        $marked = 0;
        for ($y = 0; $y < $height; $y++) for ($x = 0; $x < $width; $x++) {
            $c = imagecolorat($drawn, $x, $y);
            $area = $transparent ? (($c >> 24) & 0x7F) >= 64 : 299 * (($c >> 16) & 0xFF) + 587 * (($c >> 8) & 0xFF) + 114 * ($c & 0xFF) >= 128000;
            if ($area) { $marked++; imagesetpixel($mask, $x, $y, $clear); }
        }
        imagedestroy($drawn);
        if ($marked === 0) { imagedestroy($mask); return null; }
        return $mask;
    }

    /** The picture with the area of a mask erased (Cobrir): transparent there, so the model can't see what was in it. */
    public static function erase(\GdImage $image, \GdImage $mask): void
    {
        imagealphablending($image, false);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $width = imagesx($image); $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) for ($x = 0; $x < $width; $x++) if (((imagecolorat($mask, $x, $y) >> 24) & 0x7F) === 127) imagesetpixel($image, $x, $y, $clear);
    }

    /** Whether any pixel is not fully opaque. */
    public static function hasAlpha(\GdImage $image): bool
    {
        $width = imagesx($image); $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) for ($x = 0; $x < $width; $x++) if ((imagecolorat($image, $x, $y) >> 24) & 0x7F) return true;
        return false;
    }

    /**
     * The picture as a file: PNG, without loss; when that is over $limit, a lossless WebP; then, when it has no
     * transparency and $jpeg allows, a JPEG of high quality (as the page saves a large crop).
     * @return array{bytes:string,mime:string}
     */
    public static function encode(\GdImage $image, string $tooLarge, int $limit = self::LIMIT, bool $jpeg = true): array
    {
        imagesavealpha($image, true);
        ob_start(); imagepng($image, null, 6); $png = (string)ob_get_clean();
        if ($png !== '' && strlen($png) <= $limit) return ['bytes'=>$png, 'mime'=>'image/png'];
        if (function_exists('imagewebp')) {
            ob_start(); imagewebp($image, null, defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : 100); $webp = (string)ob_get_clean();
            if ($webp !== '' && strlen($webp) <= $limit) return ['bytes'=>$webp, 'mime'=>'image/webp'];
        }
        if ($jpeg && !self::hasAlpha($image)) {
            ob_start(); imagejpeg($image, null, 95); $file = (string)ob_get_clean();
            if ($file !== '' && strlen($file) <= $limit) return ['bytes'=>$file, 'mime'=>'image/jpeg'];
        }
        throw new HttpError(422, $tooLarge);
    }

    /** A PNG mask, as the image API takes it (under 4 MB). */
    public static function maskUrl(\GdImage $mask): string
    {
        imagesavealpha($mask, true);
        ob_start(); imagepng($mask, null, 9); $png = (string)ob_get_clean();
        if ($png === '' || strlen($png) >= 4 * 1024 * 1024) throw new HttpError(422, t('The mask must be a PNG smaller than 4 MB.'));
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** @param array{bytes:string,mime:string} $file */
    public static function dataUrl(array $file): string { return 'data:'.$file['mime'].';base64,'.base64_encode($file['bytes']); }
}
