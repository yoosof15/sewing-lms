<?php

namespace Database\Seeders;

use App\Enums\LessonQuestionStatus;
use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LessonQuestionDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LessonQuestionDemoSeeder may run only locally.');
        }$lesson = Lesson::where('title', 'like', '%زیپ%')->first() ?? Lesson::where('status', 'PUBLISHED')->first();
        $student = User::role('STUDENT')->first();
        $teacher = $lesson?->course?->teachers()->first();
        if (! $lesson || ! $student) {
            return;
        }foreach ([['دوخت زیپ کج می‌شود', 'چطور خط دوخت را صاف نگه دارم؟', LessonQuestionStatus::Open], ['انتخاب پایه مناسب', 'برای این زیپ چه پایه‌ای مناسب است؟', LessonQuestionStatus::Answered], ['مشکل حل شد', 'با تنظیم کشش نخ مشکل برطرف شد.', LessonQuestionStatus::Resolved]] as $i => $data) {
            $q = LessonQuestion::firstOrCreate(['lesson_id' => $lesson->id, 'student_id' => $student->id, 'subject' => $data[0]], ['body' => $data[1], 'status' => $data[2], 'last_activity_at' => now()->subMinutes(30 - $i)]);
            if ($teacher && $data[2] !== LessonQuestionStatus::Open) {
                $q->replies()->firstOrCreate(['user_id' => $teacher->id, 'body' => 'پاسخ نمایشی مدرس'], ['is_staff' => true]);
            }
        }
    }
}
