<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonQuestionStatus;
use App\Enums\LessonStatus;
use App\Enums\SubmissionStatus;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonManualCompletion;
use App\Models\LessonQuestion;
use App\Models\LessonVideo;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\LearningProgressService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseThirteenReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $outsider;

    private Course $course;

    private Lesson $first;

    private Lesson $second;

    private array $students;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('video_local');
        $this->admin = $this->user('ADMIN');
        $this->teacher = $this->user('TEACHER');
        $this->outsider = $this->user('TEACHER');
        $this->course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        $this->course->teachers()->attach($this->teacher);
        $this->first = Lesson::factory()->create(['course_id' => $this->course->id, 'created_by' => $this->teacher->id, 'status' => LessonStatus::Published, 'sort_order' => 1]);
        $this->second = Lesson::factory()->create(['course_id' => $this->course->id, 'created_by' => $this->teacher->id, 'status' => LessonStatus::Published, 'sort_order' => 2]);
        $this->students = [$this->user('STUDENT', ['first_name' => 'بدون']), $this->user('STUDENT', ['first_name' => 'نیمه']), $this->user('STUDENT', ['first_name' => 'کامل'])];
        foreach ($this->students as $student) {
            CourseEnrollment::create(['course_id' => $this->course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'created_by' => $this->admin->id]);
        }
        LessonManualCompletion::create(['user_id' => $this->students[1]->id, 'lesson_id' => $this->first->id, 'completed_at' => now()]);
        foreach ([$this->first, $this->second] as $lesson) {
            LessonManualCompletion::create(['user_id' => $this->students[2]->id, 'lesson_id' => $lesson->id, 'completed_at' => now()]);
        }
    }

    public function test_report_authorization_and_authoritative_progress_buckets(): void
    {
        $expected = app(LearningProgressService::class)->course($this->students[1], $this->course);
        $this->actingAs($this->admin)->get("/admin/reports/courses/{$this->course->id}")->assertOk();
        $this->actingAs($this->teacher)->get("/teacher/reports/courses/{$this->course->id}")->assertInertia(fn (Assert $page) => $page
            ->where('students.data.0.progress_state', 'not_started')
            ->where('students.data.1.progress_state', 'in_progress')
            ->where('students.data.1.progress.percent', $expected['percent'])
            ->where('students.data.2.progress_state', 'completed'));
        $this->actingAs($this->outsider)->get("/teacher/reports/courses/{$this->course->id}")->assertForbidden();
        $this->actingAs($this->students[0])->get("/teacher/reports/courses/{$this->course->id}")->assertForbidden();
    }

    public function test_future_lesson_is_scheduled_and_not_in_progress_denominator(): void
    {
        $future = Lesson::factory()->create(['course_id' => $this->course->id, 'created_by' => $this->teacher->id, 'status' => LessonStatus::Published, 'sort_order' => 3, 'available_at' => now()->addDay()]);
        $this->actingAs($this->teacher)->get("/teacher/reports/students/{$this->students[2]->id}?course={$this->course->id}")->assertInertia(fn (Assert $page) => $page
            ->where('courses.0.percent', 100)
            ->where('detail.lessons.2.id', $future->id)
            ->where('detail.lessons.2.access.state', 'SCHEDULED')
            ->where('detail.lessons.2.state', 'scheduled'));
    }

    public function test_video_report_uses_watched_seconds_not_last_position(): void
    {
        $key = 'processed/'.Str::ulid().'.mp4';
        Storage::disk('video_local')->put($key, 'video');
        $video = LessonVideo::create(['lesson_id' => $this->first->id, 'uploaded_by' => $this->teacher->id, 'title' => 'ویدیو', 'original_filename' => 'v.mp4', 'storage_driver' => 'local', 'storage_key' => 'source', 'original_mime_type' => 'video/mp4', 'size_bytes' => 10, 'processed_storage_key' => $key, 'duration_seconds' => 100, 'sort_order' => 1, 'status' => VideoStatus::Ready, 'publication_status' => VideoPublicationStatus::Published]);
        VideoProgress::create(['user_id' => $this->students[0]->id, 'lesson_video_id' => $video->id, 'last_position_seconds' => 99, 'watched_seconds' => 10, 'last_heartbeat_at' => now()]);
        $this->actingAs($this->admin)->get("/admin/reports/students/{$this->students[0]->id}?course={$this->course->id}")->assertInertia(fn (Assert $page) => $page
            ->where('detail.lessons.0.videos.0.percent', 10)
            ->where('detail.lessons.0.videos.0.completed', false));
    }

    public function test_assignment_qa_and_last_activity_aggregates_are_course_scoped(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $assignment = LessonAssignment::create(['lesson_id' => $this->first->id, 'created_by' => $this->teacher->id, 'title' => 'تمرین', 'status' => AssignmentStatus::Published, 'allow_late_submission' => true, 'allow_resubmission' => true]);
        $assignment->submissions()->create(['student_id' => $this->students[0]->id, 'attempt_number' => 1, 'status' => SubmissionStatus::RevisionRequested, 'text_content' => 'کار', 'is_late' => true, 'submitted_at' => now()->subDays(10)]);
        LessonQuestion::create(['lesson_id' => $this->first->id, 'student_id' => $this->students[0]->id, 'body' => 'پرسش', 'status' => LessonQuestionStatus::Open, 'last_activity_at' => now()]);
        $this->actingAs($this->teacher)->get("/teacher/reports/courses/{$this->course->id}")->assertInertia(fn (Assert $page) => $page
            ->where('students.data.0.assignments.submitted', 1)
            ->where('students.data.0.assignments.revision_requested', 1)
            ->where('students.data.0.assignments.late', 1)
            ->where('students.data.0.questions.open', 1)
            ->where('students.data.0.last_activity_at', now()->subDays(10)->toISOString()));
        Carbon::setTestNow();
    }

    public function test_filters_and_student_idor_are_enforced(): void
    {
        $this->actingAs($this->teacher)->get("/teacher/reports/courses/{$this->course->id}?progress_state=completed&student=کامل")->assertInertia(fn (Assert $page) => $page->has('students.data', 1)->where('students.data.0.student.id', $this->students[2]->id));
        $unrelated = $this->user('STUDENT');
        $this->actingAs($this->teacher)->get("/teacher/reports/students/{$unrelated->id}")->assertForbidden();
        $this->actingAs($this->outsider)->get("/teacher/reports/students/{$this->students[0]->id}?course={$this->course->id}")->assertForbidden();
    }

    public function test_csv_has_bom_filters_masking_and_export_authorization(): void
    {
        $response = $this->actingAs($this->teacher)->get("/teacher/reports/courses/{$this->course->id}/export?progress_state=completed")->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('نام', $content);
        $this->assertStringContainsString($this->students[2]->first_name, $content);
        $this->assertStringNotContainsString($this->students[0]->first_name, $content);
        $this->assertStringNotContainsString((string) $this->students[2]->password, $content);
        $this->actingAs($this->outsider)->get("/teacher/reports/courses/{$this->course->id}/export")->assertForbidden();
    }

    public function test_course_report_uses_bounded_bulk_queries(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->teacher)->get("/teacher/reports/courses/{$this->course->id}")->assertOk();
        $this->assertLessThan(45, count(DB::getQueryLog()));
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
