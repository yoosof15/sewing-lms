<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseFifteenUserProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_every_role_can_view_only_its_own_profile(): void
    {
        foreach (['ADMIN', 'TEACHER', 'STUDENT'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/account/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Account/Profile')->where('profile.id', $user->id)
                ->missing('profile.password')->missing('profile.remember_token')->missing('profile.avatar_path'));
        }
    }

    public function test_self_edit_allows_safe_fields_but_not_mobile_role_or_status(): void
    {
        $student = $this->user('STUDENT');
        $mobile = $student->mobile;
        $this->actingAs($student)->put('/account/profile', [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'new@example.test', 'bio' => 'Short bio',
            'mobile' => '+989111111111', 'role' => 'ADMIN', 'is_active' => false,
        ])->assertRedirect();
        $student->refresh();
        $this->assertSame('New', $student->first_name);
        $this->assertSame($mobile, $student->mobile);
        $this->assertTrue($student->is_active);
        $this->assertTrue($student->hasRole('STUDENT'));
        $this->assertSame('Short bio', $student->profile->bio);
    }

    public function test_teacher_can_edit_professional_fields_but_student_cannot(): void
    {
        $teacher = $this->user('TEACHER');
        $this->actingAs($teacher)->put('/account/profile', [
            'first_name' => $teacher->first_name, 'last_name' => $teacher->last_name, 'email' => $teacher->email,
            'bio' => 'Teacher bio', 'teacher_specialty' => 'Pattern making', 'teacher_experience_text' => 'Ten years',
        ])->assertRedirect();
        $this->assertSame('Pattern making', $teacher->profile()->first()->teacher_specialty);

        $student = $this->user('STUDENT');
        $this->actingAs($student)->put('/account/profile', [
            'first_name' => $student->first_name, 'last_name' => $student->last_name,
            'teacher_specialty' => 'Injected',
        ])->assertSessionHasErrors('teacher_specialty');
        $this->assertDatabaseMissing('user_profiles', ['user_id' => $student->id, 'teacher_specialty' => 'Injected']);
    }

    public function test_password_change_requires_current_password_and_confirmation_and_is_not_audited(): void
    {
        $user = $this->user('STUDENT');
        $this->actingAs($user)->put('/account/profile/password', ['current_password' => 'wrong', 'password' => 'Newpass123', 'password_confirmation' => 'Newpass123'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/account/profile/password', ['current_password' => 'password', 'password' => 'Newpass123', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put('/account/profile/password', ['current_password' => 'password', 'password' => 'Newpass123', 'password_confirmation' => 'Newpass123'])->assertRedirect();
        $this->assertTrue(Hash::check('Newpass123', $user->fresh()->password));
        $this->assertStringNotContainsString('Newpass123', DB::table('audit_logs')->where('action', 'profile.password_changed')->value('metadata') ?? '');
    }

    public function test_avatar_validation_and_replacement_cleanup(): void
    {
        Storage::fake('public');
        $user = $this->user('STUDENT');
        $this->actingAs($user)->post('/account/profile/avatar', ['avatar' => UploadedFile::fake()->create('bad.php', 10, 'application/x-php')])->assertSessionHasErrors('avatar');
        $this->actingAs($user)->post('/account/profile/avatar', ['avatar' => UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('avatar');
        $this->actingAs($user)->post('/account/profile/avatar', ['avatar' => UploadedFile::fake()->image('large.jpg')->size(2200)])->assertSessionHasErrors('avatar');
        $this->actingAs($user)->post('/account/profile/avatar', ['avatar' => UploadedFile::fake()->image('first.png', 200, 200)])->assertRedirect();
        $first = $user->profile()->value('avatar_path');
        Storage::disk('public')->assertExists($first);
        $this->actingAs($user)->post('/account/profile/avatar', ['avatar' => UploadedFile::fake()->image('second.webp', 200, 200)])->assertRedirect();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->profile()->value('avatar_path'));
    }

    public function test_admin_detail_is_admin_only_and_contains_safe_session_summary(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT');
        DB::table('sessions')->insert(['id' => 'private-session-id', 'user_id' => $student->id, 'ip_address' => '192.168.1.99', 'user_agent' => 'Mozilla Chrome/120 Windows', 'payload' => 'TOP-SECRET-PAYLOAD', 'last_activity' => now()->timestamp]);
        $response = $this->actingAs($admin)->get("/admin/users/{$student->id}")->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Show')->where('managedUser.id', $student->id)->has('sessions', 1)->missing('managedUser.password')->missing('managedUser.remember_token'));
        $this->assertStringNotContainsString('TOP-SECRET-PAYLOAD', $response->getContent());
        $this->assertStringNotContainsString('private-session-id', $response->getContent());
        $this->actingAs($this->user('TEACHER'))->get("/admin/users/{$student->id}")->assertForbidden();
        $this->actingAs($this->user('STUDENT'))->get("/admin/users/{$student->id}")->assertForbidden();
    }

    public function test_admin_student_detail_reuses_learning_report_progress(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT');
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $this->actingAs($admin)->get("/admin/users/{$student->id}")->assertInertia(fn (Assert $page) => $page
            ->has('studentReport.courses', 1)->where('studentReport.courses.0.id', $course->id)->where('studentReport.courses.0.percent', 0));
    }

    public function test_admin_user_search_filters_sorting_and_mobile_normalization(): void
    {
        $admin = $this->user('ADMIN');
        $target = User::factory()->create(['first_name' => 'Needle', 'last_name' => 'Master', 'mobile' => '+989121234567', 'email' => 'needle@example.test', 'is_active' => false]);
        $target->assignRole('TEACHER');
        foreach (['Needle', 'needle@example.test', '09121234567', '989121234567', '+989121234567'] as $search) {
            $this->actingAs($admin)->get('/admin/users?search='.urlencode($search))->assertInertia(fn (Assert $page) => $page->where('users.data.0.id', $target->id));
        }
        $this->actingAs($admin)->get('/admin/users?role=TEACHER&active=0&sort=name')->assertInertia(fn (Assert $page) => $page->where('users.data.0.id', $target->id));
    }

    public function test_student_course_teacher_props_expose_only_public_profile_fields(): void
    {
        $student = $this->user('STUDENT');
        $teacher = $this->user('TEACHER');
        $teacher->profile()->create(['bio' => 'Public bio', 'teacher_specialty' => 'Sewing']);
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        $course->teachers()->attach($teacher);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $response = $this->actingAs($student)->get("/student/courses/{$course->id}")->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('teacherProfiles.0.name', $teacher->name)->where('teacherProfiles.0.specialty', 'Sewing')->missing('teacherProfiles.0.email')->missing('teacherProfiles.0.mobile')->missing('teacherProfiles.0.avatar_path'));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
