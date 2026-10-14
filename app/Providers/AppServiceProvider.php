<?php

namespace App\Providers;

use App\Contracts\VideoProcessor;
use App\Contracts\VideoStorage;
use App\Services\LocalFFmpegVideoProcessor;
use App\Services\LocalVideoStorage;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
