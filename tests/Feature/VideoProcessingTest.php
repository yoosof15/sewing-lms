<?php

namespace Tests\Feature;

use App\Contracts\VideoProcessor;
use App\Contracts\VideoStorage;
use App\Data\VideoMetadata;
use App\Enums\VideoStatus;
use App\Jobs\ProcessLessonVideo;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FakeVideoProcessor implements VideoProcessor
{
    public int $inspectCalls = 0;

    public int $processCalls = 0;

    public bool $shouldFail = false;

    public function inspect(string $localPath): VideoMetadata
    {
        $this->inspectCalls++;

        return $this->inspectCalls === 1
            ? new VideoMetadata(60.2, 3840, 2160, 60, 'hevc', 'aac', 'mov,mp4', 20_000_000, 0)
            : new VideoMetadata(60.1, 1920, 1080, 30, 'h264', 'aac', 'mov,mp4', 2_000_000, 0);
    }

    public function process(string $sourcePath, string $outputPath, VideoMetadata $source): void
    {
        $this->processCalls++;
        if ($this->shouldFail) {
            file_put_contents($outputPath, 'partial');
            throw new RuntimeException('technical ffmpeg failure');
        }
        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }
        file_put_contents($outputPath, str_repeat('o', 50));
    }

    public function name(): string
    {
        return 'fake';
    }
}

class VideoProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
        config(['video.processing_temp_path' => Storage::disk('video_local')->path('processing-temp')]);
    }

    public function test_success_persists_metadata_and_preserves_source(): void
    {
        $video = $this->video();
        $processor = new FakeVideoProcessor;

        (new ProcessLessonVideo($video->id))->handle($processor, app(VideoStorage::class));

        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(1920, $video->width);
        $this->assertSame(1080, $video->height);
        $this->assertSame('hevc', $video->source_codec);
        $this->assertSame('h264', $video->processed_codec);
        $this->assertSame(50, $video->processed_size_bytes);
        $this->assertSame(60, $video->duration_seconds);
        $this->assertStringStartsWith('processed/', $video->processed_storage_key);
        $this->assertStringNotContainsString(':\\', $video->processed_storage_key);
        Storage::disk('video_local')->assertExists($video->storage_key);
        Storage::disk('video_local')->assertExists($video->processed_storage_key);
    }

    public function test_failure_marks_failed_preserves_source_and_removes_partial_output(): void
    {
        $video = $this->video();
        $processor = new FakeVideoProcessor;
        $processor->shouldFail = true;

        (new ProcessLessonVideo($video->id))->handle($processor, app(VideoStorage::class));

        $video->refresh();
        $this->assertSame(VideoStatus::Failed, $video->status);
        $this->assertNull($video->processed_storage_key);
        $this->assertStringNotContainsString('ffmpeg', $video->failure_reason);
        Storage::disk('video_local')->assertExists($video->storage_key);
        $this->assertSame([], Storage::disk('video_local')->files('processing-temp'));
    }

    public function test_original_is_deleted_only_after_success_when_configured(): void
    {
        config(['video.keep_original' => false]);
        $video = $this->video();
        (new ProcessLessonVideo($video->id))->handle(new FakeVideoProcessor, app(VideoStorage::class));

        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertNotNull($video->source_deleted_at);
        Storage::disk('video_local')->assertMissing($video->storage_key);
        Storage::disk('video_local')->assertExists($video->processed_storage_key);
    }

    public function test_ready_or_processing_video_is_not_processed_again(): void
    {
        $video = $this->video(VideoStatus::Ready);
        $processor = new FakeVideoProcessor;
        (new ProcessLessonVideo($video->id))->handle($processor, app(VideoStorage::class));
        $this->assertSame(0, $processor->processCalls);

        $video->update(['status' => VideoStatus::Processing]);
        (new ProcessLessonVideo($video->id))->handle($processor, app(VideoStorage::class));
        $this->assertSame(0, $processor->processCalls);
    }

    public function test_retry_is_authorized_and_only_available_for_failed_video(): void
    {
        Queue::fake();
        $video = $this->video(VideoStatus::Failed);
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post("/lessons/{$video->lesson_id}/videos/{$video->id}/retry")->assertRedirect();
        Queue::assertPushed(ProcessLessonVideo::class);

        $student = $this->user('STUDENT');
        $this->actingAs($student)->post("/lessons/{$video->lesson_id}/videos/{$video->id}/retry")->assertForbidden();

        $teacher = $this->user('TEACHER');
        $video->lesson->course->teachers()->attach($teacher);
        $video->lesson->update(['created_by' => $teacher->id]);
        $this->actingAs($teacher)->post("/lessons/{$video->lesson_id}/videos/{$video->id}/retry")->assertRedirect();
        $otherTeacher = $this->user('TEACHER');
        $video->lesson->course->teachers()->attach($otherTeacher);
        $this->actingAs($otherTeacher)->post("/lessons/{$video->lesson_id}/videos/{$video->id}/retry")->assertForbidden();

        $video->update(['status' => VideoStatus::Ready]);
        $this->actingAs($admin)->post("/lessons/{$video->lesson_id}/videos/{$video->id}/retry")->assertStatus(409);

        $video->update(['status' => VideoStatus::Failed, 'processing_attempts' => config('video.processing_max_attempts')]);
        $this->actingAs($admin)->post("/lessons/{$video->lesson_id}/videos/{$video->id}/retry")->assertStatus(409);
    }

    private function video(VideoStatus $status = VideoStatus::Stored): LessonVideo
    {
        $uploader = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $key = 'videos/'.$lesson->id.'/'.Str::ulid();
        Storage::disk('video_local')->put($key, str_repeat('s', 100));

        return LessonVideo::create(['lesson_id' => $lesson->id, 'uploaded_by' => $uploader->id, 'title' => 'Video', 'original_filename' => 'phone.mov', 'storage_driver' => 'local', 'storage_key' => $key, 'original_mime_type' => 'video/quicktime', 'size_bytes' => 100, 'sort_order' => 1, 'status' => $status]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
