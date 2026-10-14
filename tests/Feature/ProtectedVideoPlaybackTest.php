<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProtectedVideoPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
        $this->admin = $this->user('ADMIN');
        $this->student = $this->user('STUDENT');
        $this->course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        $this->lesson = Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Published, 'published_at' => now(), 'created_by' => $this->admin->id]);
        $this->enroll($this->student);
    }

    public function test_new_ready_video_defaults_to_draft_and_only_ready_asset_can_be_published(): void
    {
        $video = $this->video(VideoStatus::Ready);
        $this->assertSame(VideoPublicationStatus::Draft, $video->fresh()->publication_status);
        $this->actingAs($this->admin)->patch($this->publicationUrl($video), ['publication_status' => 'PUBLISHED'])->assertRedirect();
        $this->assertSame(VideoPublicationStatus::Published, $video->fresh()->publication_status);
        $this->assertNotNull($video->fresh()->published_at);

        $stored = $this->video(VideoStatus::Stored, 2);
        $this->actingAs($this->admin)->patch($this->publicationUrl($stored), ['publication_status' => 'PUBLISHED'])->assertUnprocessable();
        $this->assertSame(VideoPublicationStatus::Draft, $stored->fresh()->publication_status);
    }

    public function test_publication_requires_authorized_manager_and_student_cannot_publish(): void
    {
        $teacher = $this->user('TEACHER');
        $this->course->teachers()->attach($teacher);
        $this->lesson->update(['created_by' => $teacher->id]);
        $video = $this->video(VideoStatus::Ready);
        $this->actingAs($teacher)->patch($this->publicationUrl($video), ['publication_status' => 'PUBLISHED'])->assertRedirect();

        $video->update(['publication_status' => VideoPublicationStatus::Draft]);
        $other = $this->user('TEACHER');
        $this->course->teachers()->attach($other);
        $this->actingAs($other)->patch($this->publicationUrl($video), ['publication_status' => 'PUBLISHED'])->assertForbidden();
        $this->actingAs($this->student)->patch($this->publicationUrl($video), ['publication_status' => 'PUBLISHED'])->assertForbidden();
    }

    public function test_student_full_and_range_delivery_is_private_and_byte_accurate(): void
    {
        $video = $this->video(publication: VideoPublicationStatus::Published, processed: 'abcdefghijklmnopqrstuvwxyz');
        $url = $this->streamUrl($video);
        $full = $this->actingAs($this->student)->get($url);
        $full->assertOk()->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', '26')->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame('abcdefghijklmnopqrstuvwxyz', $full->streamedContent());
        $this->assertStringContainsString('private', (string) $full->headers->get('Cache-Control'));

        $range = $this->actingAs($this->student)->withHeader('Range', 'bytes=5-9')->get($url);
        $range->assertStatus(206)->assertHeader('Content-Range', 'bytes 5-9/26')->assertHeader('Content-Length', '5');
        $this->assertSame('fghij', $range->streamedContent());

        $this->actingAs($this->student)->withHeader('Range', 'bytes=100-200')->get($url)
            ->assertStatus(416)->assertHeader('Content-Range', 'bytes */26');
    }

    public function test_student_access_rechecks_enrollment_and_parent_states_on_every_request(): void
    {
        $video = $this->video(publication: VideoPublicationStatus::Published);
        $url = $this->streamUrl($video);
        $this->actingAs($this->student)->get($url)->assertOk();

        $enrollment = CourseEnrollment::where('student_id', $this->student->id)->first();
        $enrollment->update(['status' => EnrollmentStatus::Inactive]);
        $this->actingAs($this->student)->get($url)->assertNotFound();
        $enrollment->update(['status' => EnrollmentStatus::Active, 'starts_at' => now()->addDay()]);
        $this->actingAs($this->student)->get($url)->assertNotFound();
        $enrollment->update(['starts_at' => null, 'ends_at' => now()->subDay()]);
        $this->actingAs($this->student)->get($url)->assertNotFound();
        $enrollment->update(['ends_at' => null]);

        foreach ([CourseStatus::Draft, CourseStatus::Inactive] as $status) {
            $this->course->update(['status' => $status]);
            $this->actingAs($this->student)->get($url)->assertNotFound();
        }
        $this->course->update(['status' => CourseStatus::Published]);
        foreach ([LessonStatus::Draft, LessonStatus::Inactive] as $status) {
            $this->lesson->update(['status' => $status]);
            $this->actingAs($this->student)->get($url)->assertNotFound();
        }
        $this->lesson->update(['status' => LessonStatus::Published]);
        foreach ([VideoPublicationStatus::Draft, VideoPublicationStatus::Inactive] as $publication) {
            $video->update(['publication_status' => $publication]);
            $this->actingAs($this->student)->get($url)->assertNotFound();
        }
    }

    public function test_unenrolled_student_guest_and_nested_idor_are_denied(): void
    {
        $video = $this->video(publication: VideoPublicationStatus::Published);
        $url = $this->streamUrl($video);
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($this->user('STUDENT'))->get($url)->assertNotFound();
        $otherCourse = Course::factory()->create(['status' => CourseStatus::Published]);
        $this->actingAs($this->student)->get("/student/courses/{$otherCourse->id}/lessons/{$this->lesson->id}/videos/{$video->id}/stream")->assertNotFound();
        $otherLesson = Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Published, 'sort_order' => 2]);
        $this->actingAs($this->student)->get("/student/courses/{$this->course->id}/lessons/{$otherLesson->id}/videos/{$video->id}/stream")->assertNotFound();
    }

    public function test_soft_deleted_resources_and_missing_processed_asset_are_denied(): void
    {
        $video = $this->video(publication: VideoPublicationStatus::Published);
        $url = $this->streamUrl($video);
        Storage::disk('video_local')->delete($video->processed_storage_key);
        $this->actingAs($this->student)->get($url)->assertNotFound();

        Storage::disk('video_local')->put($video->processed_storage_key, 'processed');
        $video->delete();
        $this->actingAs($this->student)->get($url)->assertNotFound();
    }

    public function test_soft_deleted_course_or_lesson_immediately_blocks_playback(): void
    {
        $video = $this->video(publication: VideoPublicationStatus::Published);
        $url = $this->streamUrl($video);
        $this->course->delete();
        $this->actingAs($this->student)->get($url)->assertNotFound();
        $this->course->restore();
        $this->lesson->delete();
        $this->actingAs($this->student)->get($url)->assertNotFound();
    }

    public function test_admin_and_owner_teacher_can_preview_ready_draft_but_others_cannot(): void
    {
        $video = $this->video(VideoStatus::Ready, publication: VideoPublicationStatus::Draft);
        $this->actingAs($this->admin)->get("/videos/{$video->id}/preview")->assertOk();
        $teacher = $this->user('TEACHER');
        $this->course->teachers()->attach($teacher);
        $this->lesson->update(['created_by' => $teacher->id]);
        $this->actingAs($teacher)->get("/videos/{$video->id}/preview")->assertOk();

        $other = $this->user('TEACHER');
        $this->course->teachers()->attach($other);
        $this->actingAs($other)->get("/videos/{$video->id}/preview")->assertForbidden();
        $this->actingAs($this->student)->get("/videos/{$video->id}/preview")->assertForbidden();
        $video->update(['status' => VideoStatus::Failed]);
        $this->actingAs($this->admin)->get("/videos/{$video->id}/preview")->assertForbidden();
    }

    public function test_student_page_contains_only_safe_published_ready_descriptors_in_order(): void
    {
        $second = $this->video(sort: 2, publication: VideoPublicationStatus::Published);
        $first = $this->video(sort: 1, publication: VideoPublicationStatus::Published);
        $draft = $this->video(sort: 3, publication: VideoPublicationStatus::Draft);

        $response = $this->actingAs($this->student)->get("/student/courses/{$this->course->id}/lessons/{$this->lesson->id}");
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('videos', 2)
            ->where('videos.0.id', $first->id)
            ->where('videos.1.id', $second->id)
            ->missing('videos.0.processed_storage_key')
            ->missing('videos.0.storage_key'));
        $this->assertStringNotContainsString($first->storage_key, $response->getContent());
        $this->assertStringNotContainsString($first->processed_storage_key, $response->getContent());
        $this->assertStringNotContainsString($draft->processed_storage_key, $response->getContent());
    }

    public function test_video_model_never_serializes_source_or_processed_storage_keys(): void
    {
        $video = $this->video(publication: VideoPublicationStatus::Published);
        $serialized = $video->toArray();
        $this->assertArrayNotHasKey('storage_key', $serialized);
        $this->assertArrayNotHasKey('processed_storage_key', $serialized);
    }

    private function video(VideoStatus $status = VideoStatus::Ready, int $sort = 1, VideoPublicationStatus $publication = VideoPublicationStatus::Draft, string $processed = 'processed-video-bytes'): LessonVideo
    {
        $sourceKey = 'videos/'.$this->lesson->id.'/'.Str::ulid();
        $processedKey = 'processed/'.$this->lesson->id.'/'.Str::ulid().'.mp4';
        Storage::disk('video_local')->put($sourceKey, 'original-secret-source');
        Storage::disk('video_local')->put($processedKey, $processed);

        return LessonVideo::create([
            'lesson_id' => $this->lesson->id,
            'uploaded_by' => $this->admin->id,
            'title' => 'Video '.$sort,
            'original_filename' => 'private-phone.mov',
            'storage_driver' => 'local',
            'storage_key' => $sourceKey,
            'original_mime_type' => 'video/quicktime',
            'size_bytes' => 100,
            'processed_storage_key' => $processedKey,
            'processed_size_bytes' => strlen($processed),
            'processed_mime_type' => 'video/mp4',
            'sort_order' => $sort,
            'status' => $status,
            'publication_status' => $publication,
            'published_at' => $publication === VideoPublicationStatus::Published ? now() : null,
        ]);
    }

    private function enroll(User $student): void
    {
        CourseEnrollment::create(['course_id' => $this->course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $this->admin->id]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function publicationUrl(LessonVideo $video): string
    {
        return "/lessons/{$this->lesson->id}/videos/{$video->id}/publication";
    }

    private function streamUrl(LessonVideo $video): string
    {
        return "/student/courses/{$this->course->id}/lessons/{$this->lesson->id}/videos/{$video->id}/stream";
    }
}
