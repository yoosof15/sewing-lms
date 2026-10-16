<?php

namespace Tests\Feature;

use App\Contracts\VideoProcessor;
use App\Contracts\VideoStorage;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Jobs\ProcessLessonVideo;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RealVideoPipelineRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_ffmpeg_pipeline_and_protected_range_delivery(): void
    {
        $probe = new Process([(string) config('video.ffmpeg_binary'), '-version']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('FFmpeg is unavailable in this environment.');
        }

        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
        config(['video.processing_temp_path' => Storage::disk('video_local')->path('processing-temp')]);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $student = User::factory()->create();
        $student->assignRole('STUDENT');
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'status' => LessonStatus::Published, 'published_at' => now(), 'created_by' => $admin->id]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $admin->id]);

        $sourceKey = 'videos/'.$lesson->id.'/'.Str::lower((string) Str::ulid()).'.mp4';
        $sourcePath = Storage::disk('video_local')->path($sourceKey);
        if (! is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0755, true);
        }
        $generator = new Process([
            (string) config('video.ffmpeg_binary'), '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=15',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '1', '-pix_fmt', 'yuv420p',
            '-c:v', 'libx264', '-c:a', 'aac', $sourcePath,
        ]);
        $generator->setTimeout(30);
        $generator->mustRun();

        $video = LessonVideo::create([
            'lesson_id' => $lesson->id,
            'uploaded_by' => $admin->id,
            'title' => 'Synthetic rehearsal',
            'original_filename' => 'synthetic.mp4',
            'storage_driver' => 'local',
            'storage_key' => $sourceKey,
            'original_mime_type' => 'video/mp4',
            'size_bytes' => filesize($sourcePath),
            'sort_order' => 1,
            'status' => VideoStatus::Stored,
        ]);
        $this->assertSame(VideoStatus::Stored, $video->status);

        (new ProcessLessonVideo($video->id))->handle(app(VideoProcessor::class), app(VideoStorage::class));
        $video->refresh();

        $this->assertSame(VideoStatus::Ready, $video->status, (string) $video->failure_reason);
        $this->assertSame('h264', $video->processed_codec);
        $this->assertTrue(AuditLog::where('action', 'video.processing_started')->where('auditable_id', $video->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'video.processing_succeeded')->where('auditable_id', $video->id)->exists());
        Storage::disk('video_local')->assertExists($video->processed_storage_key);

        $video->update(['publication_status' => VideoPublicationStatus::Published, 'published_at' => now()]);
        $url = "/student/courses/{$course->id}/lessons/{$lesson->id}/videos/{$video->id}/stream";
        $full = $this->actingAs($student)->get($url);
        $full->assertOk()->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Type', 'video/mp4');
        $bytes = $full->streamedContent();
        $this->assertGreaterThan(1000, strlen($bytes));

        $range = $this->actingAs($student)->withHeader('Range', 'bytes=0-63')->get($url);
        $range->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-63/'.strlen($bytes))->assertHeader('Content-Length', '64');
        $this->assertSame(substr($bytes, 0, 64), $range->streamedContent());
    }
}
