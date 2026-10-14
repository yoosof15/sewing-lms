<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function as(string $role): self
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $this->actingAs($u);
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $this->as('ADMIN')->get('/admin')->assertOk();
    }

    public function test_teacher_cannot_access_admin_dashboard(): void
    {
        $this->as('TEACHER')->get('/admin')->assertForbidden();
    }

    public function test_student_cannot_access_admin_dashboard(): void
    {
        $this->as('STUDENT')->get('/admin')->assertForbidden();
    }

    public function test_teacher_can_access_teacher_dashboard(): void
    {
        $this->as('TEACHER')->get('/teacher')->assertOk();
    }

    public function test_student_cannot_access_teacher_dashboard(): void
    {
        $this->as('STUDENT')->get('/teacher')->assertForbidden();
    }

    public function test_student_can_access_student_dashboard(): void
    {
        $this->as('STUDENT')->get('/student')->assertOk();
    }
}
