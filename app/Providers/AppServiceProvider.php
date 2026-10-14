<?php

namespace App\Providers;

use App\Contracts\VideoStorage;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
