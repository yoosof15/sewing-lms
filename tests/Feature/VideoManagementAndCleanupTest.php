<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Enums\VideoUploadStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\User;
use App\Models\VideoUploadSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoManagementAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
    }

    private function user(string $r): User
    {
        $u = User::factory()->create();
        $u->assignRole($r);

        return $u;
    }

    private function video(Lesson $l, User $u, int $order): LessonVideo
    {
        return LessonVideo::create(['lesson_id' => $l->id, 'uploaded_by' => $u->id, 'title' => 'Video '.$order, 'original_filename' => 'v.mp4', 'storage_driver' => 'local', 'storage_key' => 'videos/'.$l->id.'/'.Str::ulid(), 'original_mime_type' => 'video/mp4', 'size_bytes' => 24, 'sort_order' => $order, 'status' => VideoStatus::Stored]);
    }

    public function test_multiple_videos_order_and_authorized_management(): void
    {
        $admin = $this->user('ADMIN');
        $course = Course::factory()->create();
        $teacher = $this->user('TEACHER');
        $course->teachers()->attach($teacher);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);
        $one = $this->video($lesson, $teacher, 1);
        $two = $this->video($lesson, $teacher, 2);
        $this->actingAs($teacher)->post("/lessons/$lesson->id/videos/$two->id/move/up")->assertRedirect();
        $this->assertSame(1, $two->fresh()->sort_order);
        $other = $this->user('TEACHER');
        $course->teachers()->attach($other);
        $this->actingAs($other)->delete("/lessons/$lesson->id/videos/$one->id")->assertForbidden();
        $this->actingAs($admin)->delete("/lessons/$lesson->id/videos/$one->id")->assertRedirect();
        $this->assertSoftDeleted($one);
        $this->actingAs($this->user('STUDENT'))->delete("/lessons/$lesson->id/videos/$two->id")->assertForbidden();
    }

    public function test_nested_video_idor_is_blocked(): void
    {
        $a = $this->user('ADMIN');
        $l1 = Lesson::factory()->create();
        $l2 = Lesson::factory()->create(['sort_order' => 2]);
        $v = $this->video($l2, $a, 1);
        $this->actingAs($a)->delete("/lessons/$l1->id/videos/$v->id")->assertNotFound();
    }

    public function test_cleanup_expires_only_stale_uploads_and_is_idempotent(): void
    {
        $u = $this->user('ADMIN');
        $lesson = Lesson::factory()->create();
        $expired = $this->uploadSession($u, $lesson, 'uploads/expired.part', now()->subHour());
        $active = $this->uploadSession($u, $lesson, 'uploads/active.part', now()->addHour());
        Storage::disk('video_local')->put($expired->temporary_key, 'x');
        Storage::disk('video_local')->put($active->temporary_key, 'x');
        $this->artisan('video:cleanup-uploads')->assertSuccessful();
        $this->artisan('video:cleanup-uploads')->assertSuccessful();
        $this->assertSame(VideoUploadStatus::Expired, $expired->fresh()->status);
        $this->assertSame(VideoUploadStatus::Uploading, $active->fresh()->status);
        Storage::disk('video_local')->assertMissing($expired->temporary_key);
        Storage::disk('video_local')->assertExists($active->temporary_key);
    }

    private function uploadSession(User $u, Lesson $l, string $key, $expires): VideoUploadSession
    {
        return VideoUploadSession::create(['public_id' => (string) Str::ulid(), 'user_id' => $u->id, 'lesson_id' => $l->id, 'title' => 'x', 'original_filename' => 'x.mp4', 'total_bytes' => 1, 'uploaded_bytes' => 0, 'temporary_key' => $key, 'status' => VideoUploadStatus::Uploading, 'expires_at' => $expires]);
    }
}
