<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function student(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('STUDENT');

        return $user;
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = $this->student(['password' => Hash::make('Secret123')]);
        $this->post('/login', ['login' => $user->mobile, 'password' => 'Secret123'])->assertRedirect('/student');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = $this->student();
        $this->post('/login', ['login' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->student(['is_active' => false]);
        $this->post('/login', ['login' => $user->mobile, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_user_without_an_application_role_cannot_login(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['login' => $user->mobile, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_existing_session_of_inactive_user_cannot_access_dashboard(): void
    {
        $user = $this->student(['is_active' => false]);
        $this->actingAs($user)->get('/student')->assertForbidden();
    }

    public function test_public_registration_route_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_guest_cannot_access_dashboards(): void
    {
        foreach (['/admin', '/teacher', '/student'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_logout_works(): void
    {
        $user = $this->student();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
