<?php

namespace Tests\Feature;

use App\Models\CourseCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCategoryManagementTest extends TestCase
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

    public function test_admin_can_create_category(): void
    {
        $this->actingAs($this->user('ADMIN'))->post('/admin/categories', ['name' => 'دوخت', 'description' => 'توضیح', 'is_active' => true, 'sort_order' => 2])->assertRedirect('/admin/categories');
        $this->assertDatabaseHas('course_categories', ['name' => 'دوخت', 'is_active' => true, 'sort_order' => 2]);
    }

    public function test_admin_can_edit_category(): void
    {
        $category = CourseCategory::factory()->create();
        $this->actingAs($this->user('ADMIN'))->put("/admin/categories/{$category->id}", ['name' => 'الگوسازی', 'description' => null, 'is_active' => false, 'sort_order' => 4])->assertRedirect('/admin/categories');
        $this->assertDatabaseHas('course_categories', ['id' => $category->id, 'name' => 'الگوسازی', 'is_active' => false]);
    }

    public function test_teacher_cannot_manage_categories(): void
    {
        $this->actingAs($this->user('TEACHER'))->post('/admin/categories', ['name' => 'ممنوع'])->assertForbidden();
    }

    public function test_student_cannot_manage_categories(): void
    {
        $this->actingAs($this->user('STUDENT'))->get('/admin/categories')->assertForbidden();
    }
}
