<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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

    private function payload(string $role, string $mobile = '09121234567'): array
    {
        return ['first_name' => 'سارا', 'last_name' => 'احمدی', 'mobile' => $mobile, 'email' => strtolower($role).'@example.test', 'role' => $role, 'password' => 'Secure123', 'password_confirmation' => 'Secure123', 'is_active' => true];
    }

    public function test_admin_can_create_teacher(): void
    {
        $this->actingAs($this->user('ADMIN'))->post('/admin/users', $this->payload('TEACHER'))->assertRedirect('/admin/users');
        $this->assertTrue(User::whereMobile('+989121234567')->first()->hasRole('TEACHER'));
    }

    public function test_admin_can_create_student(): void
    {
        $this->actingAs($this->user('ADMIN'))->post('/admin/users', $this->payload('STUDENT'))->assertRedirect('/admin/users');
        $this->assertTrue(User::whereMobile('+989121234567')->first()->hasRole('STUDENT'));
    }

    public function test_teacher_cannot_create_users(): void
    {
        $this->actingAs($this->user('TEACHER'))->post('/admin/users', $this->payload('STUDENT'))->assertForbidden();
    }

    public function test_student_cannot_create_users(): void
    {
        $this->actingAs($this->user('STUDENT'))->post('/admin/users', $this->payload('TEACHER'))->assertForbidden();
    }

    public function test_equivalent_normalized_mobile_is_rejected(): void
    {
        User::factory()->create(['mobile' => '+989121234567']);
        $this->actingAs($this->user('ADMIN'))->post('/admin/users', $this->payload('STUDENT', '989121234567'))->assertSessionHasErrors('mobile');
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $admin = $this->user('ADMIN');
        $data = ['first_name' => $admin->first_name, 'last_name' => $admin->last_name, 'mobile' => $admin->mobile, 'email' => $admin->email, 'role' => 'ADMIN', 'password' => '', 'password_confirmation' => '', 'is_active' => false];
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", $data)->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
    }
}
