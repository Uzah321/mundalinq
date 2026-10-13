<?php

namespace App\Support\Images;

use App\Exceptions\BusinessRuleException;
use GdImage;
use RuntimeException;

/**
 * Resizes photos with GD. Output is WebP (JPEG if this PHP build lacks WebP): small enough for
 * buyers on mobile data. Re-encoding also drops all metadata, including any GPS position.
 */
class ImageProcessor
{
    private bool $webp;

    public function __construct()
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to process images.');
        }

        $this->webp = (imagetypes() & IMG_WEBP) !== 0;
    }

    /**
     * @param  array<string, int>  $sizes  Name => longest edge in pixels. Images are never enlarged.
     * @return array<string, EncodedImage>
     */
    public function resize(string $path, array $sizes): array
    {
        $source = $this->load($path);

        return array_map(fn (int $maxEdge) => $this->encode($this->fit($source, $maxEdge)), $sizes);
    }

    private function load(string $path): GdImage
    {
        $info = @getimagesize($path);

        $image = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw new BusinessRuleException(__('This photo could not be read. Please try another one.'), 422);
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $info[2] === IMAGETYPE_JPEG ? $this->orient($image, $path) : $image;
    }

    /**
     * Phones store photos sideways and record the rotation in EXIF; apply it before the metadata is dropped.
     */
    private function orient(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            2 => $this->flip($image),
            3 => imagerotate($image, 180, 0),
            4 => $this->flip(imagerotate($image, 180, 0)),
            5 => $this->flip(imagerotate($image, 270, 0)),
            6 => imagerotate($image, 270, 0),
            7 => $this->flip(imagerotate($image, 90, 0)),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function flip(GdImage $image): GdImage
    {
        imageflip($image, IMG_FLIP_HORIZONTAL);

        return $image;
    }

    private function fit(GdImage $source, int $maxEdge): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($this->webp) {
            // Keep transparency from PNG/WebP uploads.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private function encode(GdImage $image): EncodedImage
    {
        ob_start();

        if ($this->webp) {
            imagewebp($image, null, 80);
        } else {
            imageinterlace($image, true); // progressive: something shows early on slow connections
            imagejpeg($image, null, 82);
        }

        return new EncodedImage((string) ob_get_clean(), imagesx($image), imagesy($image), $this->webp ? 'webp' : 'jpg');
    }
}
