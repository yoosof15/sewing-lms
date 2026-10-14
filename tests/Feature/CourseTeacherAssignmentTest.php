<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseTeacherAssignmentTest extends TestCase
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

    private function payload(array $ids): array
    {
        return ['title' => 'Course', 'category_id' => null, 'short_description' => null, 'description' => null, 'status' => 'DRAFT', 'teacher_ids' => $ids];
    }

    public function test_admin_can_assign_one_or_multiple_teachers(): void
    {
        $admin = $this->user('ADMIN');
        $a = $this->user('TEACHER');
        $b = $this->user('TEACHER');
        $this->actingAs($admin)->post('/admin/courses', $this->payload([$a->id, $b->id]))->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Course::first()->teachers()->pluck('users.id')->all());
    }

    public function test_duplicate_teacher_assignment_is_rejected(): void
    {
        $teacher = $this->user('TEACHER');
        $this->actingAs($this->user('ADMIN'))->post('/admin/courses', $this->payload([$teacher->id, $teacher->id]))->assertSessionHasErrors('teacher_ids.1');
    }

    public function test_student_cannot_be_assigned_as_teacher(): void
    {
        $student = $this->user('STUDENT');
        $this->actingAs($this->user('ADMIN'))->post('/admin/courses', $this->payload([$student->id]))->assertSessionHasErrors('teacher_ids.0');
    }

    public function test_removing_assignment_works(): void
    {
        $admin = $this->user('ADMIN');
        $teacher = $this->user('TEACHER');
        $course = Course::factory()->create();
        $course->teachers()->attach($teacher);
        $this->actingAs($admin)->put("/admin/courses/{$course->id}", $this->payload([]))->assertRedirect();
        $this->assertCount(0, $course->fresh()->teachers);
    }
}
