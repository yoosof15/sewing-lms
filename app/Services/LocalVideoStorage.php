<?php

namespace App\Services;

use App\Contracts\VideoStorage;
use App\Exceptions\VideoOffsetMismatch;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LocalVideoStorage implements VideoStorage
{
    private function disk()
    {
        return Storage::disk('video_local');
    }

    public function createTemporary(string $key): void
    {
        $this->disk()->put($key, '');
    }

    public function append(string $key, string $bytes, int $expectedOffset): int
    {
        $path = $this->disk()->path($key);
        $handle = fopen($path, 'c+b');
        if (! $handle) {
            throw new RuntimeException('Temporary upload cannot be opened.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Temporary upload cannot be locked.');
            }fseek($handle, 0, SEEK_END);
            $actual = ftell($handle);
            if ($actual !== $expectedOffset) {
                throw new VideoOffsetMismatch($actual);
            }$written = fwrite($handle, $bytes);
            if ($written !== strlen($bytes)) {
                throw new RuntimeException('Chunk was not fully written.');
            }fflush($handle);

            return $actual + $written;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function temporarySize(string $key): int
    {
        return $this->disk()->size($key);
    }

    public function detectedMimeType(string $key): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($this->disk()->path($key)) ?: null;
    }

    public function promote(string $temporaryKey, string $storageKey): void
    {
        if (! $this->disk()->move($temporaryKey, $storageKey)) {
            throw new RuntimeException('Video could not be moved.');
        }
    }

    public function temporaryExists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function size(string $key): int
    {
        return $this->disk()->size($key);
    }

    public function deleteTemporary(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function localPathForProcessing(string $key): string
    {
        if (! $this->exists($key)) {
            throw new RuntimeException('Video source does not exist.');
        }

        return $this->disk()->path($key);
    }

    public function storeProcessed(string $localPath, string $storageKey): void
    {
        $stream = fopen($localPath, 'rb');
        if (! $stream) {
            throw new RuntimeException('Processed video cannot be opened.');
        }
        try {
            if (! $this->disk()->writeStream($storageKey, $stream)) {
                throw new RuntimeException('Processed video could not be stored.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $key)
    {
        $stream = $this->disk()->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException('Video asset cannot be opened.');
        }

        return $stream;
    }

    public function driver(): string
    {
        return 'local';
    }
}
