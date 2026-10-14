<?php

namespace App\Services;

use App\Contracts\VideoDelivery;
use App\Contracts\VideoStorage;
use App\Models\LessonVideo;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocalProtectedVideoDelivery implements VideoDelivery
{
    private const CHUNK_SIZE = 1024 * 1024;

    public function __construct(private readonly VideoStorage $storage) {}

    public function deliver(LessonVideo $video, ?string $range): Response
    {
        $key = $video->processed_storage_key;
        $size = $this->storage->size($key);
        $resolved = $this->resolveRange($range, $size);
        if ($resolved === null) {
            return new Response('', 416, [
                'Content-Range' => "bytes */{$size}",
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-cache, max-age=0, must-revalidate',
            ]);
        }
        [$start, $end, $partial] = $resolved;
        $length = $end - $start + 1;
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
            'Content-Type' => $video->processed_mime_type ?: 'video/mp4',
            'Content-Disposition' => 'inline; filename="video-'.$video->id.'.mp4"',
            'Cache-Control' => 'private, no-cache, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(function () use ($key, $start, $length) {
            $stream = $this->storage->readStream($key);
            try {
                if ($start > 0 && fseek($stream, $start) !== 0) {
                    return;
                }
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream) && ! connection_aborted()) {
                    $chunk = fread($stream, min(self::CHUNK_SIZE, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, $partial ? 206 : 200, $headers);
    }

    /** @return array{int, int, bool}|null */
    private function resolveRange(?string $range, int $size): ?array
    {
        if ($size < 1) {
            return null;
        }
        if ($range === null || $range === '') {
            return [0, $size - 1, false];
        }
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) || ($matches[1] === '' && $matches[2] === '')) {
            return null;
        }
        if ($matches[1] === '') {
            $suffix = (int) $matches[2];
            if ($suffix < 1) {
                return null;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
            if ($start >= $size || $end < $start) {
                return null;
            }
        }

        return [$start, $end, true];
    }
}
