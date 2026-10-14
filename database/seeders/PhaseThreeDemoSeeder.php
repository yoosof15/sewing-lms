<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class PhaseThreeDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('PhaseThreeDemoSeeder may run only in the local environment.');
        }
        $students = User::role('STUDENT')->where('is_active', true)->get();
        $admin = User::role('ADMIN')->where('is_active', true)->first();
        $courses = Course::query()->where('status', CourseStatus::Published->value)->with('teachers')->limit(5)->get();
        foreach ($courses as $course) {
            $creator = $course->teachers->first() ?? $admin;
            if (! $creator) {
                continue;
            }
            foreach (['معرفی و اهداف دوره', 'مبانی و نکات اولیه', 'تمرین پایانی'] as $title) {
                if (! Lesson::withTrashed()->where('course_id', $course->id)->where('title', $title)->exists()) {
                    Lesson::create(['course_id' => $course->id, 'title' => $title, 'content' => 'محتوای متنی نمونه برای بررسی محیط محلی.', 'sort_order' => ((int) $course->lessons()->max('sort_order')) + 1, 'status' => LessonStatus::Published, 'published_at' => now(), 'created_by' => $creator->id]);
                }
            }
            foreach ($students->take(3) as $student) {
                CourseEnrollment::firstOrCreate(['course_id' => $course->id, 'student_id' => $student->id], ['status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $admin?->id]);
            }
        }
        $this->command?->info('Local Phase 3 lessons and enrollments are ready; users and passwords were not changed.');
    }
}
