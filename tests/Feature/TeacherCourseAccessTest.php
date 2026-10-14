<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeacherCourseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_teacher_sees_only_assigned_courses(): void
    {
        $teacher = $this->user('TEACHER');
        $assigned = Course::factory()->create(['title' => 'Assigned']);
        Course::factory()->create(['title' => 'Other']);
        $assigned->teachers()->attach($teacher);
        $this->actingAs($teacher)->get('/teacher/courses')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Teacher/Courses/Index')->has('courses.data', 1)->where('courses.data.0.id', $assigned->id));
        $this->actingAs($teacher)->get("/teacher/courses/{$assigned->id}")->assertOk();
    }

    public function test_teacher_cannot_view_unassigned_course(): void
    {
        $this->actingAs($this->user('TEACHER'))->get('/teacher/courses/'.Course::factory()->create()->id)->assertForbidden();
    }

    public function test_teacher_cannot_edit_course_or_manage_assignments(): void
    {
        $teacher = $this->user('TEACHER');
        $course = Course::factory()->create();
        $course->teachers()->attach($teacher);
        $this->actingAs($teacher)->get("/admin/courses/{$course->id}/edit")->assertForbidden();
        $this->actingAs($teacher)->put("/admin/courses/{$course->id}", ['title' => 'No'])->assertForbidden();
    }

    public function test_student_has_no_admin_or_teacher_course_access(): void
    {
        $student = $this->user('STUDENT');
        $course = Course::factory()->create();
        $this->actingAs($student)->get('/admin/courses')->assertForbidden();
        $this->actingAs($student)->get('/teacher/courses')->assertForbidden();
        $this->actingAs($student)->get("/teacher/courses/{$course->id}")->assertForbidden();
    }
}
