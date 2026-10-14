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
use App\Models\VideoProgress;
use App\Services\LearningProgressService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LearningProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Course $course;

    private Lesson $lesson;

    private LessonVideo $video;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
        $this->admin = $this->user('ADMIN');
        $this->student = $this->user('STUDENT', ['first_name' => 'علی', 'last_name' => 'محمدی', 'mobile' => '+989121234567']);
        $this->course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now(), 'completion_threshold_percent' => 80]);
        $this->lesson = Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Published, 'published_at' => now()]);
        CourseEnrollment::create(['course_id' => $this->course->id, 'student_id' => $this->student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $this->admin->id]);
        $this->video = $this->makeVideo($this->lesson, 1);
    }

    public function test_heartbeat_credits_elapsed_playback_not_seek_distance_and_never_decreases(): void
    {
        $url = $this->progressUrl($this->video);
        $this->actingAs($this->student)->postJson($url, ['position_seconds' => 10])->assertOk()->assertJsonPath('watched_seconds', 0);
        $this->travel(10)->seconds();
        $this->postJson($url, ['position_seconds' => 20])->assertJsonPath('watched_seconds', 10);
        $this->travel(1)->seconds();
        $this->postJson($url, ['position_seconds' => 100])->assertJsonPath('watched_seconds', 15);
        $this->travel(10)->seconds();
        $this->postJson($url, ['position_seconds' => 5])->assertJsonPath('watched_seconds', 15);
        $this->assertSame(5, $this->videoProgress()->last_position_seconds);
    }

    public function test_negative_position_is_rejected_and_position_over_duration_is_clamped(): void
    {
        $this->actingAs($this->student)->postJson($this->progressUrl($this->video), ['position_seconds' => -1])->assertUnprocessable();
        $this->postJson($this->progressUrl($this->video), ['position_seconds' => 999])->assertOk()->assertJsonPath('last_position_seconds', 100);
        $this->assertSame(0, $this->videoProgress()->watched_seconds);
    }

    public function test_completion_is_server_calculated_from_watched_time_not_end_seek(): void
    {
        $this->actingAs($this->student)->postJson($this->progressUrl($this->video), ['position_seconds' => 100])->assertJsonPath('completed', false);
        $progress = $this->videoProgress();
        $progress->update(['last_position_seconds' => 0, 'watched_seconds' => 79, 'last_heartbeat_at' => now()->subSeconds(10)]);
        $this->postJson($this->progressUrl($this->video), ['position_seconds' => 10])->assertJsonPath('completed', true);
        $completedAt = $progress->fresh()->completed_at;
        $this->travel(5)->seconds();
        $this->postJson($this->progressUrl($this->video), ['position_seconds' => 2]);
        $this->assertTrue($progress->fresh()->completed_at->equalTo($completedAt));
    }

    public function test_progress_requires_current_playback_access_and_correct_nesting(): void
    {
        $other = $this->user('STUDENT');
        $this->actingAs($other)->postJson($this->progressUrl($this->video), ['position_seconds' => 1])->assertNotFound();
        $enrollment = CourseEnrollment::first();
        $enrollment->update(['status' => EnrollmentStatus::Inactive]);
        $this->actingAs($this->student)->postJson($this->progressUrl($this->video), ['position_seconds' => 1])->assertNotFound();
        $enrollment->update(['status' => EnrollmentStatus::Active, 'ends_at' => now()->subMinute()]);
        $this->postJson($this->progressUrl($this->video), ['position_seconds' => 1])->assertNotFound();
        $enrollment->update(['ends_at' => null]);
        $this->video->update(['publication_status' => VideoPublicationStatus::Draft]);
        $this->postJson($this->progressUrl($this->video), ['position_seconds' => 1])->assertNotFound();
        $this->video->update(['publication_status' => VideoPublicationStatus::Published]);
        $this->lesson->update(['status' => LessonStatus::Draft]);
        $this->postJson($this->progressUrl($this->video), ['position_seconds' => 1])->assertNotFound();
        $otherLesson = Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Published, 'sort_order' => 2]);
        $this->postJson("/student/courses/{$this->course->id}/lessons/{$otherLesson->id}/videos/{$this->video->id}/progress", ['position_seconds' => 1])->assertNotFound();
    }

    public function test_lesson_and_course_completion_are_derived_from_current_curriculum(): void
    {
        $service = app(LearningProgressService::class);
        $this->assertFalse($service->lesson($this->student, $this->lesson)['completed']);
        VideoProgress::create(['user_id' => $this->student->id, 'lesson_video_id' => $this->video->id, 'watched_seconds' => 80, 'last_position_seconds' => 80, 'completed_at' => now()]);
        $this->assertTrue($service->lesson($this->student, $this->lesson)['completed']);
        $this->assertSame(100, $service->course($this->student, $this->course)['percent']);
        $second = $this->makeVideo($this->lesson, 2);
        $this->assertFalse($service->lesson($this->student, $this->lesson)['completed']);
        $second->update(['publication_status' => VideoPublicationStatus::Draft]);
        $this->assertTrue($service->lesson($this->student, $this->lesson)['completed']);
        $second->update(['publication_status' => VideoPublicationStatus::Inactive]);
        $this->assertTrue($service->lesson($this->student, $this->lesson)['completed']);
        $second->delete();
        $this->assertTrue($service->lesson($this->student, $this->lesson)['completed']);
    }

    public function test_text_lesson_can_be_manual_but_video_lesson_cannot_bypass(): void
    {
        $textLesson = Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Published, 'sort_order' => 2]);
        $this->actingAs($this->student)->post("/student/courses/{$this->course->id}/lessons/{$textLesson->id}/complete")->assertRedirect();
        $this->assertTrue(app(LearningProgressService::class)->lesson($this->student, $textLesson)['completed']);
        $this->post("/student/courses/{$this->course->id}/lessons/{$this->lesson->id}/complete")->assertStatus(409);
    }

    public function test_resume_and_watermark_are_private_to_current_student(): void
    {
        VideoProgress::create(['user_id' => $this->student->id, 'lesson_video_id' => $this->video->id, 'last_position_seconds' => 42, 'watched_seconds' => 30]);
        $response = $this->actingAs($this->student)->get("/student/courses/{$this->course->id}/lessons/{$this->lesson->id}");
        $response->assertInertia(fn (Assert $page) => $page->where('videos.0.resume_position', 42)->where('watermark.text', 'علی محمدی')->where('watermark.mobile', '0912***4567'));
        $this->assertStringNotContainsString('09121234567', $response->getContent());
        $other = $this->user('STUDENT');
        CourseEnrollment::create(['course_id' => $this->course->id, 'student_id' => $other->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $this->admin->id]);
        $this->actingAs($other)->get("/student/courses/{$this->course->id}/lessons/{$this->lesson->id}")->assertInertia(fn (Assert $page) => $page->where('videos.0.resume_position', 0));
    }

    public function test_course_progress_excludes_non_published_lessons_and_handles_zero_lessons(): void
    {
        Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Draft, 'sort_order' => 2]);
        Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Inactive, 'sort_order' => 3]);
        $deleted = Lesson::factory()->create(['course_id' => $this->course->id, 'status' => LessonStatus::Published, 'sort_order' => 4]);
        $deleted->delete();
        $summary = app(LearningProgressService::class)->course($this->student, $this->course);
        $this->assertSame(1, $summary['total_lessons']);
        $empty = Course::factory()->create(['status' => CourseStatus::Published]);
        $this->assertSame(['percent' => 0, 'completed_lessons' => 0, 'total_lessons' => 0, 'lessons' => []], app(LearningProgressService::class)->course($this->student, $empty));
    }

    public function test_manager_previews_never_create_student_progress(): void
    {
        $this->actingAs($this->admin)->get("/videos/{$this->video->id}/preview")->assertOk();
        $teacher = $this->user('TEACHER');
        $this->course->teachers()->attach($teacher);
        $this->lesson->update(['created_by' => $teacher->id]);
        $this->actingAs($teacher)->get("/videos/{$this->video->id}/preview")->assertOk();
        $this->assertDatabaseCount('video_progress', 0);
    }

    private function makeVideo(Lesson $lesson, int $sort): LessonVideo
    {
        $source = 'videos/'.$lesson->id.'/'.Str::ulid();
        $processed = 'processed/'.$lesson->id.'/'.Str::ulid().'.mp4';
        Storage::disk('video_local')->put($source, 'source');
        Storage::disk('video_local')->put($processed, 'processed');

        return LessonVideo::create(['lesson_id' => $lesson->id, 'uploaded_by' => $this->admin->id, 'title' => 'Video', 'original_filename' => 'v.mp4', 'storage_driver' => 'local', 'storage_key' => $source, 'original_mime_type' => 'video/mp4', 'size_bytes' => 100, 'processed_storage_key' => $processed, 'processed_size_bytes' => 9, 'processed_mime_type' => 'video/mp4', 'duration_seconds' => 100, 'sort_order' => $sort, 'status' => VideoStatus::Ready, 'publication_status' => VideoPublicationStatus::Published]);
    }

    private function progressUrl(LessonVideo $video): string
    {
        return "/student/courses/{$this->course->id}/lessons/{$this->lesson->id}/videos/{$video->id}/progress";
    }

    private function videoProgress(): VideoProgress
    {
        return VideoProgress::where('user_id', $this->student->id)->where('lesson_video_id', $this->video->id)->firstOrFail();
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
