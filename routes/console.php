<?php

use App\Enums\AssignmentStatus;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonAssignment;
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
    $assignmentCount = 0;
    LessonAssignment::query()->where('status', AssignmentStatus::Published->value)->whereNotNull('available_at')->where('available_at', '<=', now())->whereNull('availability_notification_dispatched_at')->with('lesson.course')->chunkById(100, function ($assignments) use ($events, &$assignmentCount) {
        foreach ($assignments as $assignment) {
            if (! $assignment->lesson?->course || $assignment->lesson->trashed() || $assignment->lesson->course->trashed()) {
                continue;
            }
            $claimed = LessonAssignment::whereKey($assignment->id)->whereNull('availability_notification_dispatched_at')->update(['availability_notification_dispatched_at' => now()]);
            if ($claimed) {
                try {
                    $events->assignmentPublished($assignment);
                    $assignmentCount++;
                } catch (Throwable $e) {
                    LessonAssignment::whereKey($assignment->id)->update(['availability_notification_dispatched_at' => null]);
                    report($e);
                }
            }
        }
    });
    $this->info("Queued $assignmentCount due assignment notification(s).");
})->purpose('Queue idempotent notifications for due scheduled lessons');

Schedule::command('notifications:dispatch-due')->everyMinute()->withoutOverlapping();
