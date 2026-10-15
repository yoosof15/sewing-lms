<?php

namespace App\Providers;

use App\Contracts\VideoDelivery;
use App\Contracts\VideoProcessor;
use App\Contracts\VideoStorage;
use App\Models\AssignmentSubmission;
use App\Models\LessonAssignment;
use App\Models\SubmissionFile;
use App\Policies\AssignmentSubmissionPolicy;
use App\Policies\LessonAssignmentPolicy;
use App\Policies\SubmissionFilePolicy;
use App\Services\LocalFFmpegVideoProcessor;
use App\Services\LocalProtectedVideoDelivery;
use App\Services\LocalVideoStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VideoStorage::class, function () {
            if (config('video.storage_driver') !== 'local') {
                throw new RuntimeException('Configured video storage driver is not implemented.');
            }

            return new LocalVideoStorage;
        });
        $this->app->bind(VideoProcessor::class, function () {
            if (config('video.processor_driver') !== 'ffmpeg') {
                throw new RuntimeException('Configured video processor is unavailable.');
            }

            return new LocalFFmpegVideoProcessor;
        });
        $this->app->bind(VideoDelivery::class, LocalProtectedVideoDelivery::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(LessonAssignment::class, LessonAssignmentPolicy::class);
        Gate::policy(AssignmentSubmission::class, AssignmentSubmissionPolicy::class);
        Gate::policy(SubmissionFile::class, SubmissionFilePolicy::class);
    }
}
