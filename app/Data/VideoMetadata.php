<?php

namespace App\Data;

final readonly class VideoMetadata
{
    public function __construct(
        public float $duration,
        public int $width,
        public int $height,
        public float $frameRate,
        public string $videoCodec,
        public ?string $audioCodec,
        public ?string $format,
        public ?int $bitrate,
        public ?int $rotation = null,
    ) {}
}
