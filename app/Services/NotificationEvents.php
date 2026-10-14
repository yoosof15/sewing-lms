<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Jobs\DispatchInAppNotification;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\LessonQuestionReply;
use App\Models\LessonVideo;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationEvents
{
    public function enrollment(User $student, Course $course, bool $active, int|string $version): void
    {
        $verb = $active ? 'فعال شد' : 'غیرفعال شد';
        $this->user("enrollment:$version:".($active ? 'active' : 'inactive'), $student, NotificationCategory::CourseAccess, ['title' => 'دسترسی دوره', 'message' => "دسترسی شما به دوره «{$course->title}» $verb.", 'action_url' => $active ? route('student.courses.show', $course) : null, 'entity_type' => 'course', 'entity_id' => $course->id]);
    }

    public function teacher(User $teacher, Course $course, bool $assigned): void
    {
        $verb = $assigned ? 'به شما اختصاص داده شد' : 'از دسترسی تدریس شما برداشته شد';
        $this->user("teacher:{$course->id}:{$teacher->id}:".($assigned ? 'assigned' : 'removed'), $teacher, NotificationCategory::TeacherAssignment, ['title' => 'تغییر تخصیص دوره', 'message' => "دوره «{$course->title}» $verb.", 'action_url' => $assigned ? route('teacher.courses.show', $course) : null, 'entity_type' => 'course', 'entity_id' => $course->id]);
    }

    public function lesson(Lesson $lesson, string $kind = 'published'): void
    {
        $this->course("lesson:{$lesson->id}:$kind", $lesson->course, NotificationCategory::NewLesson, ['title' => 'جلسه جدید', 'message' => "جلسه «{$lesson->title}» منتشر شد.", 'action_url' => route('student.lessons.show', [$lesson->course, $lesson]), 'entity_type' => 'lesson', 'entity_id' => $lesson->id, 'lesson_id' => $lesson->id]);
    }

    public function video(LessonVideo $video): void
    {
        $lesson = $video->lesson;
        $this->course("video:{$video->id}:published", $lesson->course, NotificationCategory::NewVideo, ['title' => 'ویدیوی جدید', 'message' => "ویدیوی «{$video->title}» در درس «{$lesson->title}» منتشر شد.", 'action_url' => route('student.lessons.show', [$lesson->course, $lesson]), 'entity_type' => 'video', 'entity_id' => $video->id]);
    }

    public function questionCreated(LessonQuestion $question): void
    {
        $question->lesson->course->teachers()->where('is_active', true)->get()->each(fn (User $teacher) => $this->user("qa:question:{$question->id}:teacher", $teacher, NotificationCategory::LessonQa, ['title' => 'پرسش جدید', 'message' => "پرسش جدیدی در درس «{$question->lesson->title}» ثبت شد.", 'action_url' => route('questions.show', $question), 'entity_type' => 'lesson_question', 'entity_id' => $question->id]));
    }

    public function questionReply(LessonQuestion $question, LessonQuestionReply $reply, bool $staff): void
    {
        if ($staff) {
            $this->user("qa:reply:{$reply->id}:student", $question->student, NotificationCategory::LessonQa, ['title' => 'پاسخ جدید', 'message' => "به پرسش شما در درس «{$question->lesson->title}» پاسخ داده شد.", 'action_url' => route('questions.show', $question), 'entity_type' => 'lesson_question', 'entity_id' => $question->id]);
        } else {
            $question->lesson->course->teachers()->where('is_active', true)->get()->each(fn (User $teacher) => $this->user("qa:reply:{$reply->id}:teacher", $teacher, NotificationCategory::LessonQa, ['title' => 'پیگیری پرسش', 'message' => "هنرجو در پرسش درس «{$question->lesson->title}» پیام جدیدی ثبت کرد.", 'action_url' => route('questions.show', $question), 'entity_type' => 'lesson_question', 'entity_id' => $question->id]));
        }
    }

    private function user(string $key, User $user, NotificationCategory $category, array $payload): void
    {
        try {
            DispatchInAppNotification::dispatch($key, $category->value, $payload, 'user', $user->id);
        } catch (Throwable $exception) {
            Log::error('Notification dispatch failed', ['event_key' => $key, 'exception' => $exception::class]);
        }
    }

    private function course(string $key, Course $course, NotificationCategory $category, array $payload): void
    {
        try {
            DispatchInAppNotification::dispatch($key, $category->value, $payload, 'course', $course->id);
        } catch (Throwable $exception) {
            Log::error('Notification dispatch failed', ['event_key' => $key, 'exception' => $exception::class]);
        }
    }
}
