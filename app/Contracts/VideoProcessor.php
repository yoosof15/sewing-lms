<?php

namespace App\Contracts;

use App\Data\VideoMetadata;

interface VideoProcessor
{
    public function inspect(string $localPath): VideoMetadata;

    public function process(string $sourcePath, string $outputPath, VideoMetadata $source): void;

    public function name(): string;
}
