<?php

namespace App\Jobs;

use App\Contracts\VideoProcessor;
use App\Contracts\VideoStorage;
use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessLessonVideo implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 86400;

    public int $timeout;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $videoId)
    {
        $this->timeout = (int) config('video.processing_timeout');
    }

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('lesson-video-'.$this->videoId))->expireAfter((int) config('video.processing_timeout') + 300)->dontRelease()];
    }

    public function handle(VideoProcessor $processor, VideoStorage $storage): void
    {
        $video = DB::transaction(function () {
            $video = LessonVideo::lockForUpdate()->find($this->videoId);
            if (! $video || ! $video->status->canStartProcessing() || $video->processing_attempts >= (int) config('video.processing_max_attempts')) {
                return null;
            }
            $video->update([
                'status' => VideoStatus::Processing,
                'processing_started_at' => now(),
                'processed_at' => null,
                'failure_reason' => null,
                'processing_attempts' => $video->processing_attempts + 1,
            ]);

            return $video->fresh();
        });
        if (! $video) {
            return;
        }

        $workspace = rtrim((string) config('video.processing_temp_path'), '\\/');
        $outputPath = $workspace.DIRECTORY_SEPARATOR.Str::lower((string) Str::ulid()).'.mp4';
        $processedKey = 'processed/'.$video->lesson_id.'/'.Str::lower((string) Str::ulid()).'.mp4';
        $storedOutput = false;
        try {
            $sourcePath = $storage->localPathForProcessing($video->storage_key);
            $source = $processor->inspect($sourcePath);
            $processor->process($sourcePath, $outputPath, $source);
            if (! is_file($outputPath) || filesize($outputPath) < 1) {
                throw new \RuntimeException('Processed output is missing or empty.');
            }
            $output = $processor->inspect($outputPath);
            $this->validateOutput($source, $output);
            $storage->storeProcessed($outputPath, $processedKey);
            $storedOutput = true;
            if (! $storage->exists($processedKey) || $storage->size($processedKey) < 1) {
                throw new \RuntimeException('Stored processed output is invalid.');
            }

            DB::transaction(function () use ($video, $source, $output, $storage, $processedKey) {
                $locked = LessonVideo::lockForUpdate()->findOrFail($video->id);
                if ($locked->status !== VideoStatus::Processing) {
                    throw new \RuntimeException('Video processing state changed unexpectedly.');
                }
                $locked->update([
                    'status' => VideoStatus::Ready,
                    'processed_storage_key' => $processedKey,
                    'processed_size_bytes' => $storage->size($processedKey),
                    'processed_mime_type' => 'video/mp4',
                    'duration_seconds' => (int) round($output->duration),
                    'width' => $output->width,
                    'height' => $output->height,
                    'frame_rate' => $output->frameRate,
                    'source_codec' => $source->videoCodec,
                    'processed_codec' => $output->videoCodec,
                    'audio_codec' => $output->audioCodec,
                    'container_format' => $output->format,
                    'source_bitrate' => $source->bitrate,
                    'processed_at' => now(),
                    'failure_reason' => null,
                ]);
            });

            if (! config('video.keep_original')) {
                try {
                    $storage->delete($video->storage_key);
                    $video->fresh()->update(['source_deleted_at' => now()]);
                } catch (Throwable $retentionError) {
                    Log::warning('Processed video is ready but source cleanup failed', ['lesson_video_id' => $video->id, 'processor' => $processor->name(), 'stage' => 'source_cleanup', 'category' => get_class($retentionError)]);
                }
            }
            Log::info('Video processing completed', ['lesson_video_id' => $video->id, 'processor' => $processor->name(), 'attempt' => $video->processing_attempts, 'stage' => 'complete']);
        } catch (Throwable $e) {
            if ($storedOutput) {
                $storage->delete($processedKey);
            }
            LessonVideo::whereKey($video->id)->where('status', VideoStatus::Processing->value)->update([
                'status' => VideoStatus::Failed->value,
                'failure_reason' => 'پردازش ویدئو ناموفق بود. لطفاً دوباره تلاش کنید.',
            ]);
            Log::error('Video processing failed', ['lesson_video_id' => $video->id, 'processor' => $processor->name(), 'attempt' => $video->processing_attempts, 'stage' => 'process', 'category' => get_class($e), 'technical_message' => $e->getMessage()]);
        } finally {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    private function validateOutput($source, $output): void
    {
        if ($output->videoCodec !== 'h264' || $output->duration <= 0 || abs($source->duration - $output->duration) > max(2, $source->duration * 0.05)) {
            throw new \RuntimeException('Processed output validation failed.');
        }
        $longEdge = max($output->width, $output->height);
        $shortEdge = min($output->width, $output->height);
        if ($longEdge > max((int) config('video.target_max_width'), (int) config('video.target_max_height')) || $shortEdge > min((int) config('video.target_max_width'), (int) config('video.target_max_height'))) {
            throw new \RuntimeException('Processed output dimensions are invalid.');
        }
    }
}
