<?php

namespace App\Services;

use App\Contracts\VideoProcessor;
use App\Data\VideoMetadata;
use RuntimeException;
use Symfony\Component\Process\Process;

class LocalFFmpegVideoProcessor implements VideoProcessor
{
    public function inspect(string $localPath): VideoMetadata
    {
        $process = new Process([
            (string) config('video.ffprobe_binary'), '-v', 'error',
            '-show_streams', '-show_format', '-of', 'json', $localPath,
        ]);
        $process->setTimeout((int) config('video.processing_timeout'));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('FFprobe could not inspect the video. '.$this->diagnostic($process));
        }
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $video = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');
        if (! $video) {
            throw new RuntimeException('No video stream was found.');
        }
        $audio = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio');
        $duration = (float) ($video['duration'] ?? $data['format']['duration'] ?? 0);
        if ($duration <= 0) {
            throw new RuntimeException('Video duration is invalid.');
        }

        return new VideoMetadata(
            duration: $duration,
            width: (int) ($video['width'] ?? 0),
            height: (int) ($video['height'] ?? 0),
            frameRate: $this->rate($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? '0/1'),
            videoCodec: (string) ($video['codec_name'] ?? 'unknown'),
            audioCodec: isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            format: isset($data['format']['format_name']) ? (string) $data['format']['format_name'] : null,
            bitrate: isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null,
            rotation: $this->rotation($video),
        );
    }

    public function process(string $sourcePath, string $outputPath, VideoMetadata $source): void
    {
        $directory = dirname($outputPath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Processing workspace cannot be created.');
        }
        $required = (int) (filesize($sourcePath) * (float) config('video.processing_disk_overhead'));
        $available = disk_free_space($directory);
        if ($available !== false && $available < $required) {
            throw new RuntimeException('Insufficient disk space for video processing.');
        }

        $longEdge = max((int) config('video.target_max_width'), (int) config('video.target_max_height'));
        $shortEdge = min((int) config('video.target_max_width'), (int) config('video.target_max_height'));
        $filters = [sprintf(
            "scale=w='if(gte(iw,ih),min(iw,%d),min(iw,%d))':h='if(gte(iw,ih),min(ih,%d),min(ih,%d))':force_original_aspect_ratio=decrease:force_divisible_by=2",
            $longEdge,
            $shortEdge,
            $shortEdge,
            $longEdge,
        )];
        if ($source->frameRate > (float) config('video.target_max_fps')) {
            $filters[] = 'fps='.(float) config('video.target_max_fps');
        }
        $arguments = [
            (string) config('video.ffmpeg_binary'), '-y', '-i', $sourcePath,
            '-map', '0:v:0', '-map', '0:a?', '-vf', implode(',', $filters),
            '-c:v', (string) config('video.video_codec'), '-crf', (string) config('video.crf'),
            '-preset', (string) config('video.preset'), '-c:a', (string) config('video.audio_codec'),
            '-movflags', '+faststart', $outputPath,
        ];
        $process = new Process($arguments);
        $process->setTimeout((int) config('video.processing_timeout'));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('FFmpeg failed to process the video. '.$this->diagnostic($process));
        }
    }

    public function name(): string
    {
        return 'ffmpeg';
    }

    private function rate(string $value): float
    {
        [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');

        return (float) $denominator === 0.0 ? 0.0 : (float) $numerator / (float) $denominator;
    }

    private function rotation(array $stream): ?int
    {
        if (isset($stream['tags']['rotate'])) {
            return (int) $stream['tags']['rotate'];
        }
        foreach ($stream['side_data_list'] ?? [] as $sideData) {
            if (isset($sideData['rotation'])) {
                return (int) $sideData['rotation'];
            }
        }

        return null;
    }

    private function diagnostic(Process $process): string
    {
        return mb_substr(trim($process->getErrorOutput()), -2000);
    }
}
