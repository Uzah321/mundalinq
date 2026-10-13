<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Guards server memory: decoding a photo needs about 4 bytes per pixel, whatever its file size.
 */
class PhotoWithinMegapixels implements ValidationRule
{
    public function __construct(private int $megapixels) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $size = $value instanceof UploadedFile ? @getimagesize($value->getRealPath()) : false;

        if ($size === false) {
            $fail(__('This photo could not be read. Please try another one.'));
        } elseif ($this->megapixels * 1_000_000 < $size[0] * $size[1]) {
            $fail(__('This photo is too large. Please use one under :limit megapixels.', ['limit' => $this->megapixels]));
        }
    }
}
