<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ActiveSessionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_login_is_allowed_under_limit_and_denied_at_limit_while_expired_rows_do_not_count(): void
    {
        config(['learning.max_active_sessions' => 2]);
        $allowed = $this->user('STUDENT');
        $this->sessionRow('one', $allowed);
        $this->post('/login', ['login' => $allowed->mobile, 'password' => 'password'])->assertRedirect('/student');

        auth()->logout();
        $blocked = $this->user('STUDENT');
        $this->sessionRow('two', $blocked);
        $this->sessionRow('three', $blocked);
        $this->post('/login', ['login' => $blocked->mobile, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();

        $expired = $this->user('STUDENT');
        $this->sessionRow('old-a', $expired, now()->subMinutes(config('session.lifetime') + 1)->timestamp);
        $this->sessionRow('new-a', $expired);
        $this->post('/login', ['login' => $expired->mobile, 'password' => 'password'])->assertRedirect('/student');
    }

    public function test_user_sees_no_payload_and_can_revoke_only_own_other_session(): void
    {
        $user = $this->user('STUDENT');
        $other = $this->user('STUDENT');
        $this->sessionRow('own-session', $user, payload: 'TOP-SECRET-PAYLOAD');
        $this->sessionRow('other-session', $other);
        $service = app(ActiveSessionService::class);
        $own = $service->descriptors($user, 'current')[0];
        $response = $this->actingAs($user)->get('/account/sessions')->assertOk();
        $this->assertStringNotContainsString('TOP-SECRET-PAYLOAD', $response->getContent());
        $this->assertStringNotContainsString('own-session', $response->getContent());
        $this->actingAs($other)->delete('/account/sessions/'.$own['token'])->assertUnprocessable();
        $this->actingAs($user)->delete('/account/sessions/'.$own['token'])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'own-session']);
    }

    public function test_current_session_is_protected_from_accidental_revocation(): void
    {
        $user = $this->user('STUDENT');
        $this->sessionRow('current-id', $user);
        $service = app(ActiveSessionService::class);
        $token = $service->descriptors($user, 'current-id')[0]['token'];
        $this->assertFalse($service->revoke($user, $token, 'current-id'));
        $this->assertDatabaseHas('sessions', ['id' => 'current-id']);
    }

    public function test_admin_can_view_and_revoke_user_session_but_teacher_cannot(): void
    {
        $admin = $this->user('ADMIN');
        $teacher = $this->user('TEACHER');
        $student = $this->user('STUDENT');
        $this->sessionRow('student-session', $student);
        $token = app(ActiveSessionService::class)->descriptors($student, 'none')[0]['token'];
        $this->actingAs($teacher)->get("/admin/users/{$student->id}/sessions")->assertForbidden();
        $this->actingAs($admin)->get("/admin/users/{$student->id}/sessions")->assertOk();
        $this->actingAs($admin)->delete("/admin/users/{$student->id}/sessions/{$token}")->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'student-session']);
    }

    private function sessionRow(string $id, User $user, ?int $activity = null, string $payload = 'payload'): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '192.168.1.50', 'user_agent' => 'Mozilla/5.0 Windows Chrome/120', 'payload' => $payload, 'last_activity' => $activity ?? now()->timestamp]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
