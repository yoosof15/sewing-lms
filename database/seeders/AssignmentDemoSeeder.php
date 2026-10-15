<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AssignmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('AssignmentDemoSeeder may run only locally.');
        }

        $lesson = Lesson::query()->where('status', 'PUBLISHED')->whereHas('course.teachers')->first();
        $student = User::role('STUDENT')->first();
        $teacher = $lesson?->course?->teachers()->first();
        if (! $lesson || ! $teacher) {
            return;
        }

        $assignment = LessonAssignment::firstOrCreate(
            ['lesson_id' => $lesson->id, 'title' => 'تمرین نمونه درس'],
            ['created_by' => $teacher->id, 'description' => 'نتیجه تمرین را به‌صورت متن یا فایل ارسال کنید.', 'status' => AssignmentStatus::Published, 'due_at' => now()->addWeek(), 'allow_late_submission' => true, 'allow_resubmission' => true, 'max_attempts' => 3],
        );

        if ($student) {
            $assignment->submissions()->firstOrCreate(
                ['student_id' => $student->id, 'attempt_number' => 1],
                ['text_content' => 'ارسال نمایشی هنرجو', 'status' => SubmissionStatus::Submitted, 'is_late' => false, 'submitted_at' => now()],
            );
        }
    }
}
