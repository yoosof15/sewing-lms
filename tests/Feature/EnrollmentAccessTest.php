<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role, array $data = []): User
    {
        $u = User::factory()->create($data);
        $u->assignRole($role);

        return $u;
    }

    private function course(array $data = []): Course
    {
        return Course::factory()->create(array_merge(['status' => CourseStatus::Published, 'published_at' => now()], $data));
    }

    private function enroll(Course $c, User $s, array $data = []): CourseEnrollment
    {
        return CourseEnrollment::create(array_merge(['course_id' => $c->id, 'student_id' => $s->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()], $data));
    }

    public function test_admin_can_enroll_student_and_duplicate_is_prevented(): void
    {
        $a = $this->user('ADMIN');
        $s = $this->user('STUDENT');
        $c = $this->course();
        $this->actingAs($a)->post("/admin/courses/$c->id/enrollments", ['student_ids' => [$s->id]])->assertRedirect();
        $this->actingAs($a)->post("/admin/courses/$c->id/enrollments", ['student_ids' => [$s->id]])->assertSessionHasErrors('student_ids');
        $this->assertDatabaseCount('course_enrollments', 1);
    }

    public function test_teacher_and_student_cannot_enroll(): void
    {
        $s = $this->user('STUDENT');
        $c = $this->course();
        $this->actingAs($this->user('TEACHER'))->post("/admin/courses/$c->id/enrollments", ['student_ids' => [$s->id]])->assertForbidden();
        $this->actingAs($s)->post("/admin/courses/$c->id/enrollments", ['student_ids' => [$s->id]])->assertForbidden();
    }

    public function test_non_students_and_inactive_students_cannot_be_enrolled(): void
    {
        $a = $this->user('ADMIN');
        $c = $this->course();
        foreach ([$this->user('ADMIN'), $this->user('TEACHER'), $this->user('STUDENT', ['is_active' => false])] as $u) {
            $this->actingAs($a)->post("/admin/courses/$c->id/enrollments", ['student_ids' => [$u->id]])->assertSessionHasErrors('student_ids.0');
        }
    }

    public function test_valid_active_enrollment_allows_access(): void
    {
        $s = $this->user('STUDENT');
        $c = $this->course();
        $this->enroll($c, $s);
        $this->actingAs($s)->get("/student/courses/$c->id")->assertOk();
    }

    public function test_admin_can_deactivate_and_reactivate_enrollment(): void
    {
        $a = $this->user('ADMIN');
        $s = $this->user('STUDENT');
        $c = $this->course();
        $e = $this->enroll($c, $s);
        $this->actingAs($a)->put("/admin/courses/$c->id/enrollments/$e->id", ['status' => 'INACTIVE'])->assertRedirect();
        $this->actingAs($s)->get("/student/courses/$c->id")->assertForbidden();
        $this->actingAs($a)->put("/admin/courses/$c->id/enrollments/$e->id", ['status' => 'ACTIVE'])->assertRedirect();
        $this->actingAs($s)->get("/student/courses/$c->id")->assertOk();
    }

    public function test_inactive_future_and_expired_enrollments_block_access(): void
    {
        foreach ([['status' => EnrollmentStatus::Inactive], ['starts_at' => now()->addDay()], ['ends_at' => now()->subDay()]] as $data) {
            $s = $this->user('STUDENT');
            $c = $this->course();
            $this->enroll($c, $s, $data);
            $this->actingAs($s)->get("/student/courses/$c->id")->assertForbidden();
        }
    }

    public function test_unpublished_or_deleted_course_blocks_access(): void
    {
        foreach ([CourseStatus::Draft, CourseStatus::Inactive] as $status) {
            $s = $this->user('STUDENT');
            $c = $this->course(['status' => $status]);
            $this->enroll($c, $s);
            $this->actingAs($s)->get("/student/courses/$c->id")->assertForbidden();
        }$s = $this->user('STUDENT');
        $c = $this->course();
        $this->enroll($c, $s);
        $c->delete();
        $this->actingAs($s)->get("/student/courses/$c->id")->assertNotFound();
    }

    public function test_student_dashboard_lists_only_currently_accessible_courses(): void
    {
        $s = $this->user('STUDENT');
        $ok = $this->course(['title' => 'Accessible']);
        $blocked = $this->course(['title' => 'Blocked']);
        $this->enroll($ok, $s);
        $this->enroll($blocked, $s, ['status' => EnrollmentStatus::Inactive]);
        $this->actingAs($s)->get('/student/courses')->assertSee('Accessible')->assertDontSee('Blocked');
    }
}
