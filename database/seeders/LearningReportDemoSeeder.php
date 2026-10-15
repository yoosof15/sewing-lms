<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonQuestionStatus;
use App\Enums\SubmissionStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LessonAssignment;
use App\Models\LessonManualCompletion;
use App\Models\LessonQuestion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class LearningReportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LearningReportDemoSeeder may run only locally.');
        }
        $courses = Course::query()->where('status', 'PUBLISHED')->whereHas('lessons')->with(['lessons' => fn ($q) => $q->where('status', 'PUBLISHED'), 'teachers'])->limit(2)->get();
        if ($courses->isEmpty()) {
            return;
        }

        foreach (range(1, 18) as $number) {
            $student = User::firstOrCreate(['email' => "report.student.$number@example.test"], ['first_name' => 'هنرجوی', 'last_name' => "گزارش $number", 'mobile' => '+98910000'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'password' => Hash::make(Str::random(40)), 'is_active' => true]);
            $student->syncRoles(['STUDENT']);
            foreach ($courses as $course) {
                $enrollment = CourseEnrollment::firstOrCreate(['course_id' => $course->id, 'student_id' => $student->id], ['status' => EnrollmentStatus::Active, 'enrolled_at' => now()->subDays(45 - $number)]);
                $enrollment->update(['status' => $number % 9 === 0 ? EnrollmentStatus::Inactive : EnrollmentStatus::Active]);
                $available = $course->lessons->filter(fn ($lesson) => ! $lesson->available_at?->isFuture())->values();
                $complete = $number % 3 === 0 ? $available->count() : ($number % 3 === 1 ? 0 : (int) ceil($available->count() / 2));
                foreach ($available->take($complete) as $lesson) {
                    if ($lesson->videos()->where('status', 'READY')->where('publication_status', 'PUBLISHED')->doesntExist()) {
                        LessonManualCompletion::firstOrCreate(['user_id' => $student->id, 'lesson_id' => $lesson->id], ['completed_at' => now()->subDays($number % 20)]);
                    }
                }
                $lesson = $available->first();
                if (! $lesson) {
                    continue;
                }
                $assignment = LessonAssignment::firstOrCreate(['lesson_id' => $lesson->id, 'title' => 'تمرین گزارش نمایشی'], ['created_by' => $course->teachers->first()?->id ?? $lesson->created_by, 'status' => AssignmentStatus::Published, 'allow_late_submission' => true, 'allow_resubmission' => true]);
                if ($number % 4 !== 0) {
                    $statuses = [SubmissionStatus::Submitted, SubmissionStatus::Approved, SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected];
                    $assignment->submissions()->firstOrCreate(['student_id' => $student->id, 'attempt_number' => 1], ['text_content' => 'ارسال نمایشی', 'status' => $statuses[$number % 4], 'is_late' => $number % 5 === 0, 'submitted_at' => now()->subDays($number % 25)]);
                }
                if ($number % 3 === 0) {
                    LessonQuestion::firstOrCreate(['lesson_id' => $lesson->id, 'student_id' => $student->id, 'subject' => 'پرسش گزارش نمایشی'], ['body' => 'متن پرسش نمایشی برای بررسی گزارش‌ها', 'status' => $number % 2 ? LessonQuestionStatus::Open : LessonQuestionStatus::Answered, 'last_activity_at' => now()->subDays($number % 10)]);
                }
            }
        }
    }
}
