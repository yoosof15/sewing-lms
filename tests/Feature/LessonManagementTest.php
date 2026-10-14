<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $r): User
    {
        $u = User::factory()->create();
        $u->assignRole($r);

        return $u;
    }

    private function course(): Course
    {
        return Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
    }

    private function payload(array $x = []): array
    {
        return array_merge(['title' => 'درس اول', 'content' => 'محتوا', 'status' => 'DRAFT'], $x);
    }

    public function test_admin_and_assigned_teacher_can_create_lesson(): void
    {
        $c = $this->course();
        $a = $this->user('ADMIN');
        $t = $this->user('TEACHER');
        $c->teachers()->attach($t);
        $this->actingAs($a)->post("/admin/courses/$c->id/lessons", $this->payload())->assertRedirect();
        $this->actingAs($t)->post("/teacher/courses/$c->id/lessons", $this->payload(['title' => 'درس دوم']))->assertRedirect();
        $this->assertDatabaseCount('lessons', 2);
    }

    public function test_unassigned_teacher_and_student_cannot_create(): void
    {
        $c = $this->course();
        $this->actingAs($this->user('TEACHER'))->post("/teacher/courses/$c->id/lessons", $this->payload())->assertForbidden();
        $this->actingAs($this->user('STUDENT'))->post("/admin/courses/$c->id/lessons", $this->payload())->assertForbidden();
    }

    public function test_teacher_can_edit_own_but_not_another_teachers_lesson_and_admin_can_edit_any(): void
    {
        $c = $this->course();
        $a = $this->user('ADMIN');
        $one = $this->user('TEACHER');
        $two = $this->user('TEACHER');
        $c->teachers()->attach([$one->id, $two->id]);
        $lesson = Lesson::factory()->create(['course_id' => $c->id, 'created_by' => $one->id]);
        $this->actingAs($one)->put("/teacher/courses/$c->id/lessons/$lesson->id", $this->payload(['title' => 'خودی']))->assertRedirect();
        $this->actingAs($two)->put("/teacher/courses/$c->id/lessons/$lesson->id", $this->payload(['title' => 'غیرمجاز']))->assertForbidden();
        $this->actingAs($a)->put("/admin/courses/$c->id/lessons/$lesson->id", $this->payload(['title' => 'مدیر']))->assertRedirect();
    }

    public function test_reordering_swaps_lessons_deterministically(): void
    {
        $c = $this->course();
        $a = $this->user('ADMIN');
        $one = Lesson::factory()->create(['course_id' => $c->id, 'sort_order' => 1]);
        $two = Lesson::factory()->create(['course_id' => $c->id, 'sort_order' => 2]);
        $this->actingAs($a)->post("/admin/courses/$c->id/lessons/$two->id/move/up")->assertRedirect();
        $this->assertSame(2, $one->fresh()->sort_order);
        $this->assertSame(1, $two->fresh()->sort_order);
    }

    public function test_student_sees_only_published_active_lesson(): void
    {
        $s = $this->user('STUDENT');
        $c = $this->course();
        CourseEnrollment::create(['course_id' => $c->id, 'student_id' => $s->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $published = Lesson::factory()->create(['course_id' => $c->id, 'sort_order' => 1, 'status' => LessonStatus::Published, 'published_at' => now()]);
        $draft = Lesson::factory()->create(['course_id' => $c->id, 'sort_order' => 2, 'status' => LessonStatus::Draft]);
        $inactive = Lesson::factory()->create(['course_id' => $c->id, 'sort_order' => 3, 'status' => LessonStatus::Inactive]);
        $this->actingAs($s)->get("/student/courses/$c->id")->assertSee($published->title)->assertDontSee($draft->title)->assertDontSee($inactive->title);
        $this->actingAs($s)->get("/student/courses/$c->id/lessons/$draft->id")->assertForbidden();
        $published->delete();
        $this->actingAs($s)->get("/student/courses/$c->id/lessons/$published->id")->assertNotFound();
    }
}
