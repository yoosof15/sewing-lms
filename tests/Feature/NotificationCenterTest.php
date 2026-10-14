<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Jobs\DispatchInAppNotification;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function course(): Course
    {
        return Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
    }

    public function test_enrollment_and_teacher_assignment_notifications_are_idempotent(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT');
        $teacher = $this->user('TEACHER');
        $course = $this->course();
        $this->actingAs($admin)->post("/admin/courses/$course->id/enrollments", ['student_ids' => [$student->id]])->assertRedirect();
        $this->assertCount(1, $student->fresh()->notifications);
        $payload = ['title' => $course->title, 'category_id' => '', 'short_description' => '', 'description' => '', 'status' => 'PUBLISHED', 'navigation_mode' => 'FREE', 'completion_threshold_percent' => 80, 'teacher_ids' => [$teacher->id]];
        $this->actingAs($admin)->put("/admin/courses/$course->id", $payload)->assertRedirect();
        $this->assertCount(1, $teacher->fresh()->notifications);
        $this->actingAs($admin)->put("/admin/courses/$course->id", $payload)->assertRedirect();
        $this->assertCount(1, $teacher->fresh()->notifications);
    }

    public function test_due_release_is_idempotent_and_skips_invalid_enrollment(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $student = $this->user('STUDENT');
        $inactive = $this->user('STUDENT');
        $course = $this->course();
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $inactive->id, 'status' => EnrollmentStatus::Inactive, 'enrolled_at' => now()]);
        Lesson::factory()->create(['course_id' => $course->id, 'status' => LessonStatus::Published, 'published_at' => now()->subDay(), 'available_at' => now(), 'sort_order' => 1]);
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertCount(1, $student->fresh()->notifications);
        $this->assertCount(0, $inactive->fresh()->notifications);
        Carbon::setTestNow();
    }

    public function test_center_is_user_scoped_and_read_actions_are_idor_safe(): void
    {
        $one = $this->user('STUDENT');
        $two = $this->user('STUDENT');
        DispatchInAppNotification::dispatchSync('test:one', 'COURSE_ACCESS', ['title' => 'اعلان من', 'message' => 'متن', 'action_url' => null], 'user', $one->id);
        $item = $one->fresh()->notifications()->first();
        $this->actingAs($one)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.title', 'اعلان من'));
        $this->actingAs($two)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('notifications.total', 0));
        $this->actingAs($two)->patch("/notifications/$item->id/read")->assertNotFound();
        $this->actingAs($one)->patch("/notifications/$item->id/read")->assertRedirect();
        $this->assertNotNull($item->fresh()->read_at);
    }

    public function test_future_lesson_waits_for_due_command(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT');
        $course = $this->course();
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $this->actingAs($admin)->post("/admin/courses/$course->id/lessons", ['title' => 'درس اکنون', 'status' => 'PUBLISHED'])->assertRedirect();
        $this->assertCount(1, $student->fresh()->notifications);
        $this->actingAs($admin)->post("/admin/courses/$course->id/lessons", ['title' => 'درس آینده', 'status' => 'PUBLISHED', 'available_at' => now()->addHour()->format('Y-m-d H:i:s')])->assertRedirect();
        $this->assertCount(1, $student->fresh()->notifications);
        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertCount(2, $student->fresh()->notifications);
        Carbon::setTestNow();
    }
}
