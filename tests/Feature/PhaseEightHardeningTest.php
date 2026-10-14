<?php

namespace Tests\Feature;

use App\Contracts\VideoStorage;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Enums\VideoStatus;
use App\Jobs\PurgeLessonVideo;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\StorageCleanupTask;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseEightHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_user_mutations_are_audited_without_password_data(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/users', [
            'first_name' => 'Test', 'last_name' => 'Student', 'mobile' => '+989121111111',
            'email' => 'student@example.test', 'role' => 'STUDENT', 'is_active' => true,
            'password' => 'Secure1234', 'password_confirmation' => 'Secure1234',
        ])->assertRedirect();

        $log = AuditLog::where('action', 'user.created')->firstOrFail();
        $encoded = json_encode($log->metadata);
        $this->assertStringNotContainsString('Secure1234', $encoded);
        $this->assertStringNotContainsString('password', strtolower($encoded));
    }

    public function test_global_audit_page_is_admin_only(): void
    {
        $this->actingAs($this->user('ADMIN'))->get('/admin/audit-logs')->assertOk();
        $this->actingAs($this->user('TEACHER'))->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($this->user('STUDENT'))->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_archived_course_immediately_loses_student_access_and_can_be_restored(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT');
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $admin->id]);

        $this->actingAs($admin)->delete("/admin/courses/{$course->id}")->assertRedirect();
        $this->actingAs($student)->get("/student/courses/{$course->id}")->assertNotFound();
        $this->actingAs($admin)->post("/admin/archive/courses/{$course->id}/restore")->assertRedirect();
        $this->assertFalse($course->fresh()->trashed());
    }

    public function test_course_with_learning_history_cannot_be_purged(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT');
        $course = Course::factory()->create();
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $admin->id]);
        $course->delete();

        $this->actingAs($admin)->delete("/admin/purge/courses/{$course->id}", ['confirmation' => 'PURGE'])->assertStatus(409);
        $this->assertNotNull(Course::withTrashed()->find($course->id));
    }

    public function test_video_purge_uses_storage_and_is_idempotent(): void
    {
        Storage::fake('video_local');
        $admin = $this->user('ADMIN');
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'created_by' => $admin->id, 'status' => LessonStatus::Draft]);
        $video = LessonVideo::create(['lesson_id' => $lesson->id, 'uploaded_by' => $admin->id, 'title' => 'Video', 'original_filename' => 'v.mp4', 'storage_driver' => 'local', 'storage_key' => 'videos/source', 'processed_storage_key' => 'processed/output.mp4', 'original_mime_type' => 'video/mp4', 'size_bytes' => 10, 'sort_order' => 1, 'status' => VideoStatus::Ready]);
        Storage::disk('video_local')->put('videos/source', 'source');
        Storage::disk('video_local')->put('processed/output.mp4', 'processed');
        $video->delete();
        $task = StorageCleanupTask::create(['resource_type' => 'lesson_video', 'resource_id' => $video->id]);
        $job = new PurgeLessonVideo($task->id);
        $job->handle(app(VideoStorage::class), app(AuditService::class));
        $job->handle(app(VideoStorage::class), app(AuditService::class));

        Storage::disk('video_local')->assertMissing('videos/source');
        Storage::disk('video_local')->assertMissing('processed/output.mp4');
        $this->assertNull(LessonVideo::withTrashed()->find($video->id));
        $this->assertSame('COMPLETED', $task->fresh()->status);
    }

    public function test_security_headers_are_applied(): void
    {
        $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
