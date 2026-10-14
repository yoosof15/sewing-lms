<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Jobs\ProcessLessonVideo;
use App\Models\LessonVideo;
use Illuminate\Console\Command;

class DispatchPendingVideos extends Command
{
    protected $signature = 'video:dispatch-pending {--limit=100}';

    protected $description = 'Dispatch stored videos that are awaiting processing';

    public function handle(): int
    {
        if (in_array(config('video.processor_driver'), [null, 'null'], true)) {
            $this->error('Video processing is disabled.');

            return self::FAILURE;
        }
        $count = 0;
        LessonVideo::where('status', VideoStatus::Stored->value)
            ->where('processing_attempts', '<', (int) config('video.processing_max_attempts'))
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get()
            ->each(function (LessonVideo $video) use (&$count) {
                ProcessLessonVideo::dispatch($video->id);
                $count++;
            });
        $this->info("Dispatched {$count} video(s).");

        return self::SUCCESS;
    }
}
