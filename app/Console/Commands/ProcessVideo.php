<?php

namespace App\Console\Commands;

use App\Jobs\ProcessLessonVideo;
use App\Models\LessonVideo;
use Illuminate\Console\Command;

class ProcessVideo extends Command
{
    protected $signature = 'video:process {video : LessonVideo ID} {--sync : Process in this command instead of dispatching}';

    protected $description = 'Dispatch an eligible lesson video for processing';

    public function handle(): int
    {
        if (in_array(config('video.processor_driver'), [null, 'null'], true)) {
            $this->error('Video processing is disabled.');

            return self::FAILURE;
        }
        $video = LessonVideo::find($this->argument('video'));
        if (! $video || ! $video->status->canStartProcessing()) {
            $this->error('Video was not found or is not eligible for processing.');

            return self::FAILURE;
        }
        if ($video->processing_attempts >= (int) config('video.processing_max_attempts')) {
            $this->error('Maximum processing attempts reached.');

            return self::FAILURE;
        }
        $this->option('sync') ? ProcessLessonVideo::dispatchSync($video->id) : ProcessLessonVideo::dispatch($video->id);
        $this->info($this->option('sync') ? 'Processing finished.' : 'Video processing was dispatched.');

        return self::SUCCESS;
    }
}
