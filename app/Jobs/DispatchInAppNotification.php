<?php

namespace App\Jobs;

use App\Enums\EnrollmentStatus;
use App\Enums\NotificationCategory;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Services\StudentLessonAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchInAppNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $eventKey, public string $category, public array $payload, public string $audience, public int $targetId) {}

    public function handle(InAppNotificationService $service, StudentLessonAccess $lessonAccess): void
    {
        $category = NotificationCategory::from($this->category);
        if ($this->audience === 'user') {
            if ($user = User::find($this->targetId)) {
                $service->send($user, $this->eventKey, $category, $this->payload);
            }

return;
        }
        $course = Course::find($this->targetId);
        if (! $course || $course->trashed() || $course->status->value !== 'PUBLISHED') {
            return;
        }
        $lesson = isset($this->payload['lesson_id']) ? Lesson::find($this->payload['lesson_id']) : null;
        User::role('STUDENT')->where('is_active', true)->whereHas('enrollments', fn ($q) => $q->where('course_id', $course->id)->where('status', EnrollmentStatus::Active->value)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now())))->chunkById(200, function ($users) use ($service, $category, $lesson, $lessonAccess) {
            $users->each(function ($user) use ($service, $category, $lesson, $lessonAccess) {
                $payload = $this->payload;
                if ($lesson && ! $lessonAccess->allows($user, $lesson)) {
                    $payload['message'] .= ' این جلسه منتشر شده است، اما برای مشاهده باید شرایط دسترسی آن را تکمیل کنید.';
                } $service->send($user, $this->eventKey, $category, $payload);
            });
        });
    }
}
