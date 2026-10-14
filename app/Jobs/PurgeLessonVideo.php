<?php

namespace App\Jobs;

use App\Contracts\VideoStorage;
use App\Models\LessonVideo;
use App\Models\StorageCleanupTask;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PurgeLessonVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $taskId) {}

    public function handle(VideoStorage $storage, AuditService $audit): void
    {
        $task = StorageCleanupTask::find($this->taskId);
        if (! $task || $task->status === 'COMPLETED') {
            return;
        }
        $task->update(['status' => 'PROCESSING', 'attempts' => $task->attempts + 1, 'last_error' => null]);
        $video = LessonVideo::withTrashed()->find($task->resource_id);
        if (! $video) {
            $task->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            return;
        }
        abort_unless($video->trashed(), 409, 'Video must be archived before purge.');
        try {
            foreach (array_filter([$video->storage_key, $video->processed_storage_key]) as $key) {
                if ($storage->exists($key)) {
                    $storage->delete($key);
                    if ($storage->exists($key)) {
                        throw new \RuntimeException('Storage provider did not remove an asset.');
                    }
                }
            }
            $audit->record('video.assets_purged', $video, ['source_present' => filled($video->storage_key), 'processed_present' => filled($video->processed_storage_key)]);
            $video->forceDelete();
            $task->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $task->update(['status' => 'FAILED', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }
}
