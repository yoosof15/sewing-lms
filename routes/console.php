<?php

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\NotificationEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:dispatch-due', function (NotificationEvents $events) {
    $count = 0;
    Lesson::query()->where('status', LessonStatus::Published->value)->whereNotNull('available_at')->where('available_at', '<=', now())->whereNull('release_notification_dispatched_at')->with('course')->chunkById(100, function ($lessons) use ($events, &$count) {
        foreach ($lessons as $lesson) {
            if (! $lesson->course || $lesson->course->trashed()) {
                continue;
            } $claimed = Lesson::whereKey($lesson->id)->whereNull('release_notification_dispatched_at')->update(['release_notification_dispatched_at' => now()]);
            if ($claimed) {
                try {
                    $events->lesson($lesson, 'released');
                    $count++;
                } catch (Throwable $e) {
                    Lesson::whereKey($lesson->id)->update(['release_notification_dispatched_at' => null]);
                    report($e);
                }
            }
        }
    });
    $this->info("Queued $count due lesson notification(s).");
})->purpose('Queue idempotent notifications for due scheduled lessons');

Schedule::command('notifications:dispatch-due')->everyMinute()->withoutOverlapping();
