<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFourteenSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(SystemSettings::class)->forget();
    }

    public function test_settings_page_is_admin_only(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
        $this->actingAs($this->user('ADMIN'))->get('/admin/settings')->assertOk();
        $this->actingAs($this->user('TEACHER'))->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->user('STUDENT'))->get('/admin/settings')->assertForbidden();
    }

    public function test_fallback_update_cache_invalidation_and_group_reset_work(): void
    {
        $settings = app(SystemSettings::class);
        $this->assertSame(80, $settings->integer('default_completion_threshold_percent'));

        $settings->setGroup('learning', [
            'default_completion_threshold_percent' => 72,
            'default_navigation_mode' => 'SEQUENTIAL',
        ], $this->user('ADMIN')->id);

        $this->assertSame(72, $settings->integer('default_completion_threshold_percent'));
        $this->assertSame('SEQUENTIAL', $settings->string('default_navigation_mode'));
        $settings->resetGroup('learning');
        $this->assertSame(80, $settings->integer('default_completion_threshold_percent'));
        $this->assertDatabaseMissing('system_settings', ['group' => 'learning']);
    }

    public function test_unknown_and_sensitive_keys_are_rejected_and_never_stored(): void
    {
        $admin = $this->user('ADMIN');
        $payload = $this->groupPayload('general') + [
            'APP_KEY' => 'attacker-value',
            'DB_PASSWORD' => 'attacker-value',
            'MAIL_PASSWORD' => 'attacker-value',
            'AWS_SECRET_ACCESS_KEY' => 'attacker-value',
        ];

        $this->actingAs($admin)->put('/admin/settings/general', $payload)->assertSessionHasErrors('settings');
        $this->assertSame(0, SystemSetting::query()->count());
        $this->assertNotSame('attacker-value', config('app.key'));
    }

    public function test_type_and_range_validation_rejects_invalid_values(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->put('/admin/settings/sessions', ['max_active_sessions' => -1])->assertSessionHasErrors('max_active_sessions');
        $this->actingAs($admin)->put('/admin/settings/video', $this->groupPayload('video', ['watermark_enabled' => 'invalid']))->assertSessionHasErrors('watermark_enabled');
        $this->actingAs($admin)->put('/admin/settings/video', $this->groupPayload('video', ['watermark_move_seconds' => 19]))->assertSessionHasErrors('watermark_move_seconds');
        $this->actingAs($admin)->put('/admin/settings/video', $this->groupPayload('video', ['video_max_upload_mb' => (int) config('video.max_upload_mb') + 1]))->assertSessionHasErrors('video_max_upload_mb');
        $this->actingAs($admin)->put('/admin/settings/learning', $this->groupPayload('learning', ['default_completion_threshold_percent' => 101]))->assertSessionHasErrors('default_completion_threshold_percent');
        $this->actingAs($admin)->put('/admin/settings/assignments', $this->groupPayload('assignments', ['assignment_max_files' => 11]))->assertSessionHasErrors('assignment_max_files');
        $this->actingAs($admin)->put('/admin/settings/qa', $this->groupPayload('qa', ['qa_max_images' => 4]))->assertSessionHasErrors('qa_max_images');
        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_hard_limits_always_win_over_database_values(): void
    {
        config(['video.max_upload_mb' => 100, 'assignments.max_files' => 5, 'assignments.max_file_kb' => 2048]);
        $actor = $this->user('ADMIN');
        SystemSetting::insert([
            ['key' => 'video_max_upload_mb', 'value' => '500', 'type' => 'INTEGER', 'group' => 'video', 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'assignment_max_files', 'value' => '9', 'type' => 'INTEGER', 'group' => 'assignments', 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'assignment_max_file_mb', 'value' => '20', 'type' => 'INTEGER', 'group' => 'assignments', 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $settings = app(SystemSettings::class);
        $settings->forget();

        $this->assertSame(100, $settings->videoMaxUploadMb());
        $this->assertSame(5, $settings->assignmentMaxFiles());
        $this->assertSame(2048, $settings->assignmentMaxFileKb());
    }

    public function test_new_courses_use_current_defaults_without_rewriting_existing_courses(): void
    {
        $admin = $this->user('ADMIN');
        $existing = Course::factory()->create(['completion_threshold_percent' => 80, 'navigation_mode' => 'FREE']);
        app(SystemSettings::class)->setGroup('learning', ['default_completion_threshold_percent' => 65, 'default_navigation_mode' => 'SEQUENTIAL'], $admin->id);

        $this->actingAs($admin)->post('/admin/courses', ['title' => 'Defaulted course', 'status' => 'DRAFT', 'teacher_ids' => []])->assertRedirect();

        $created = Course::where('title', 'Defaulted course')->firstOrFail();
        $this->assertSame(65, $created->completion_threshold_percent);
        $this->assertSame('SEQUENTIAL', $created->navigation_mode->value);
        $this->assertSame(80, $existing->fresh()->completion_threshold_percent);
        $this->assertSame('FREE', $existing->fresh()->navigation_mode->value);
    }

    public function test_session_setting_changes_future_login_enforcement_immediately(): void
    {
        $student = $this->user('STUDENT');
        $admin = $this->user('ADMIN');
        DB::table('sessions')->insert([
            $this->sessionRow('one', $student),
            $this->sessionRow('two', $student),
        ]);
        app(SystemSettings::class)->setGroup('sessions', ['max_active_sessions' => 2], $admin->id);
        $this->post('/login', ['login' => $student->mobile, 'password' => 'password'])->assertSessionHasErrors('login');

        app(SystemSettings::class)->setGroup('sessions', ['max_active_sessions' => 3], $admin->id);
        $this->post('/login', ['login' => $student->mobile, 'password' => 'password'])->assertRedirect('/student');
    }

    public function test_disabling_qa_preserves_history_but_blocks_new_student_activity(): void
    {
        [$student, $course, $lesson] = $this->learningContext();
        $admin = $this->user('ADMIN');
        app(SystemSettings::class)->setGroup('qa', ['qa_max_images' => 2, 'qa_max_image_mb' => 2, 'lesson_qa_enabled' => false], $admin->id);

        $this->actingAs($student)->post("/student/courses/{$course->id}/lessons/{$lesson->id}/questions", ['body' => 'A valid question'])->assertForbidden();
        $this->actingAs($student)->get("/student/courses/{$course->id}/lessons/{$lesson->id}")->assertOk();
    }

    public function test_logo_validation_storage_replacement_and_audit_are_safe(): void
    {
        Storage::fake('public');
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/settings/branding/logo', ['logo' => UploadedFile::fake()->create('payload.php', 2, 'application/x-php')])->assertSessionHasErrors('logo');

        $this->actingAs($admin)->post('/admin/settings/branding/logo', ['logo' => UploadedFile::fake()->image('first.png', 200, 200)])->assertRedirect();
        $first = app(SystemSettings::class)->string('branding_logo_path');
        Storage::disk('public')->assertExists($first);

        $this->actingAs($admin)->post('/admin/settings/branding/logo', ['logo' => UploadedFile::fake()->image('second.webp', 200, 200)])->assertRedirect();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists(app(SystemSettings::class)->string('branding_logo_path'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'branding.logo_updated', 'actor_user_id' => $admin->id]);
    }

    public function test_setting_update_is_audited_with_safe_values(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->put('/admin/settings/sessions', ['max_active_sessions' => 4])->assertRedirect();
        $log = AuditLog::where('action', 'system_setting.updated')->firstOrFail();
        $this->assertSame('max_active_sessions', $log->metadata['key']);
        $this->assertSame(4, $log->metadata['to']);
        $this->assertStringNotContainsString('password', strtolower(json_encode($log->metadata)));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function groupPayload(string $group, array $overrides = []): array
    {
        $settings = app(SystemSettings::class);
        $values = collect($settings->definitions())->filter(fn ($definition) => $definition['group'] === $group)->mapWithKeys(fn ($definition, $key) => [$key => $settings->get($key)])->all();
        unset($values['branding_logo_path']);

        return array_replace($values, $overrides);
    }

    private function sessionRow(string $id, User $user): array
    {
        return ['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'payload' => 'payload', 'last_activity' => now()->timestamp];
    }

    private function learningContext(): array
    {
        $student = $this->user('STUDENT');
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'status' => LessonStatus::Published, 'published_at' => now()]);

        return [$student, $course, $lesson];
    }
}
