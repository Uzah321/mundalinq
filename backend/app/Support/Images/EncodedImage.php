<?php

namespace App\Support\Images;

final readonly class EncodedImage
{
    public function __construct(
        public string $data,
        public int $width,
        public int $height,
        public string $extension,
    ) {}
}
