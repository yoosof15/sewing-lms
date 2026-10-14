<?php

namespace Tests\Feature;

use App\Jobs\ProcessLessonVideo;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoUploadSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
        $this->admin = $this->user('ADMIN');
        $this->course = Course::factory()->create();
        $this->lesson = Lesson::factory()->create(['course_id' => $this->course->id, 'created_by' => $this->admin->id]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function initiate(User $user, int $bytes = 24, ?Lesson $lesson = null)
    {
        return $this->actingAs($user)->postJson('/lessons/'.($lesson ?? $this->lesson)->id.'/video-uploads', ['title' => 'ویدئوی تست', 'original_filename' => 'phone.mp4', 'declared_mime_type' => 'video/mp4', 'total_bytes' => $bytes]);
    }

    private function chunk(User $user, string $id, int $offset, string $bytes)
    {
        return $this->actingAs($user)->call('PATCH', "/video-uploads/$id/chunk", [], [], [], ['HTTP_UPLOAD_OFFSET' => (string) $offset, 'CONTENT_TYPE' => 'application/octet-stream'], $bytes);
    }

    private function mp4(): string
    {
        return hex2bin('00000018667479706d703432000000006d70343269736f6d');
    }

    public function test_admin_and_authorized_teacher_can_initiate_but_others_cannot(): void
    {
        $this->initiate($this->admin)->assertCreated();
        $t = $this->user('TEACHER');
        $this->course->teachers()->attach($t);
        $own = Lesson::factory()->create(['course_id' => $this->course->id, 'created_by' => $t->id, 'sort_order' => 2]);
        $this->initiate($t, 24, $own)->assertCreated();
        $other = $this->user('TEACHER');
        $this->course->teachers()->attach($other);
        $this->initiate($other, 24, $own)->assertForbidden();
        $this->initiate($this->user('TEACHER'))->assertForbidden();
        $this->initiate($this->user('STUDENT'))->assertForbidden();
    }

    public function test_zero_and_oversized_uploads_are_rejected(): void
    {
        config(['video.max_upload_mb' => 1]);
        $this->initiate($this->admin, 0)->assertUnprocessable();
        $this->initiate($this->admin, 1048577)->assertUnprocessable();
    }

    public function test_session_is_private_to_creator(): void
    {
        $id = $this->initiate($this->admin)->json('id');
        $other = $this->user('TEACHER');
        $this->course->teachers()->attach($other);
        $this->actingAs($other)->getJson("/video-uploads/$id")->assertForbidden();
        $this->chunk($other, $id, 0, 'abc')->assertForbidden();
    }

    public function test_correct_offset_advances_and_retry_does_not_duplicate(): void
    {
        $id = $this->initiate($this->admin, 6)->json('id');
        $this->chunk($this->admin, $id, 0, 'abc')->assertOk()->assertJsonPath('expected_offset', 3);
        $this->chunk($this->admin, $id, 0, 'abc')->assertConflict()->assertJsonPath('expected_offset', 3);
        $updatedSession = VideoUploadSession::where('public_id', $id)->first();
        $this->assertSame(3, $updatedSession->uploaded_bytes);
        $this->assertTrue($updatedSession->expires_at->isFuture());
    }

    public function test_expired_and_cancelled_sessions_cannot_continue(): void
    {
        $id = $this->initiate($this->admin, 3)->json('id');
        $s = VideoUploadSession::where('public_id', $id)->first();
        $s->update(['expires_at' => now()->subMinute()]);
        $this->chunk($this->admin, $id, 0, 'abc')->assertStatus(410);
        $id2 = $this->initiate($this->admin, 3)->json('id');
        $this->actingAs($this->admin)->deleteJson("/video-uploads/$id2")->assertOk();
        $this->chunk($this->admin, $id2, 0, 'abc')->assertStatus(409);
    }

    public function test_incomplete_and_non_video_uploads_do_not_finalize(): void
    {
        $id = $this->initiate($this->admin, 4)->json('id');
        $this->actingAs($this->admin)->postJson("/video-uploads/$id/finalize")->assertConflict();
        $this->chunk($this->admin, $id, 0, 'text')->assertOk();
        $this->actingAs($this->admin)->postJson("/video-uploads/$id/finalize")->assertUnprocessable();
        $this->assertDatabaseCount('lesson_videos', 0);
    }

    public function test_valid_mp4_finalizes_privately_and_finalize_is_idempotent(): void
    {
        Queue::fake();
        $bytes = $this->mp4();
        $id = $this->initiate($this->admin, strlen($bytes))->json('id');
        $this->chunk($this->admin, $id, 0, $bytes)->assertOk();
        $first = $this->actingAs($this->admin)->postJson("/video-uploads/$id/finalize")->assertCreated();
        $this->actingAs($this->admin)->postJson("/video-uploads/$id/finalize")->assertOk();
        $this->assertDatabaseCount('lesson_videos', 1);
        $video = $this->lesson->videos()->first();
        $this->assertSame('local', $video->storage_driver);
        $this->assertStringNotContainsString(':\\', $video->storage_key);
        $this->assertStringStartsWith('videos/', $video->storage_key);
        Storage::disk('video_local')->assertExists($video->storage_key);
        $this->get('/storage/'.$video->storage_key)->assertForbidden();
        Queue::assertPushed(ProcessLessonVideo::class, fn ($job) => $job->videoId === $video->id);
    }

    public function test_nested_video_session_authorization_is_rechecked(): void
    {
        $id = $this->initiate($this->admin, 3)->json('id');
        $this->lesson->delete();
        $this->chunk($this->admin, $id, 0, 'abc')->assertForbidden();
    }

    public function test_disabled_processing_leaves_finalized_video_stored_without_dispatch(): void
    {
        Queue::fake();
        config(['video.processor_driver' => null]);
        $bytes = $this->mp4();
        $id = $this->initiate($this->admin, strlen($bytes))->json('id');
        $this->chunk($this->admin, $id, 0, $bytes)->assertOk();
        $this->actingAs($this->admin)->postJson("/video-uploads/$id/finalize")->assertCreated();
        $this->assertSame('STORED', $this->lesson->videos()->first()->status->value);
        Queue::assertNotPushed(ProcessLessonVideo::class);
    }
}
