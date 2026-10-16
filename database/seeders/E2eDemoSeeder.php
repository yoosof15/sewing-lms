<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\CourseNavigationMode;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonQuestionStatus;
use App\Enums\LessonStatus;
use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonQuestion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class E2eDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['e2e', 'testing'])) {
            throw new RuntimeException('E2eDemoSeeder is restricted to isolated test environments.');
        }

        $this->call(RoleSeeder::class);
        $password = env('E2E_PASSWORD', 'Phase17-Test-Only!');
        $admin = $this->user('مدیر', 'آزمایش', '+989100000001', 'admin@example.test', $password, 'ADMIN');
        $teacher = $this->user('مدرس', 'آزمایش', '+989100000002', 'teacher@example.test', $password, 'TEACHER');
        $student = $this->user('هنرجوی', 'اول', '+989100000003', 'student@example.test', $password, 'STUDENT');
        $secondStudent = $this->user('هنرجوی', 'دوم', '+989100000004', 'student2@example.test', $password, 'STUDENT');

        $category = CourseCategory::create(['name' => 'دوخت آزمایشی', 'slug' => 'e2e-sewing', 'is_active' => true]);
        $course = Course::create(['category_id' => $category->id, 'title' => 'دوره آزمایشی مرورگر', 'description' => 'داده ایزوله برای آزمون مرورگر', 'status' => CourseStatus::Published, 'navigation_mode' => CourseNavigationMode::Sequential, 'published_at' => now()]);
        $course->teachers()->attach($teacher);
        $other = Course::create(['title' => 'دوره غیرمرتبط', 'status' => CourseStatus::Published, 'published_at' => now()]);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'فصل آغازین', 'description' => 'فصل آزمون', 'sort_order' => 1]);
        $first = Lesson::create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => 'درس آزاد آغازین', 'content' => 'محتوای درس نخست', 'sort_order' => 1, 'status' => LessonStatus::Published, 'published_at' => now(), 'created_by' => $teacher->id]);
        $locked = Lesson::create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => 'درس قفل‌شده بعدی', 'content' => 'محتوای درس دوم', 'sort_order' => 2, 'status' => LessonStatus::Published, 'published_at' => now(), 'created_by' => $teacher->id]);
        $locked->prerequisites()->attach($first);
        Lesson::create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => 'درس زمان‌بندی‌شده', 'content' => 'محتوای آینده', 'sort_order' => 3, 'status' => LessonStatus::Published, 'published_at' => now(), 'available_at' => now()->addWeek(), 'created_by' => $teacher->id]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $admin->id]);

        $assignment = LessonAssignment::create(['lesson_id' => $first->id, 'created_by' => $teacher->id, 'title' => 'تکلیف آزمایشی مرورگر', 'description' => 'پاسخ کوتاه خود را ارسال کنید.', 'status' => AssignmentStatus::Published, 'due_at' => now()->addDay(), 'allow_resubmission' => true, 'max_attempts' => 3]);
        AssignmentSubmission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'attempt_number' => 1, 'text_content' => 'پاسخ آماده بررسی', 'status' => SubmissionStatus::RevisionRequested, 'is_late' => false, 'submitted_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $teacher->id, 'reviewer_feedback' => 'لطفاً یک بار اصلاح کنید.']);
        LessonQuestion::create(['lesson_id' => $first->id, 'student_id' => $student->id, 'subject' => 'پرسش آزمایشی مرورگر', 'body' => 'لطفاً این پرسش را بررسی کنید.', 'status' => LessonQuestionStatus::Open, 'last_activity_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'e2e.demo', 'notifiable_type' => User::class, 'notifiable_id' => $student->id, 'data' => json_encode(['title' => 'اعلان آزمایشی', 'message' => 'این اعلان فقط در محیط E2E است.', 'action_url' => "/student/courses/{$course->id}"], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);

        cache()->forever('e2e.ids', compact('admin', 'teacher', 'student', 'secondStudent', 'course', 'other', 'first', 'locked', 'assignment'));
    }

    private function user(string $first, string $last, string $mobile, string $email, string $password, string $role): User
    {
        $user = User::create(['first_name' => $first, 'last_name' => $last, 'mobile' => $mobile, 'email' => $email, 'password' => $password, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
