<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\SubmissionFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseTwelveAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function u($r)
    {
        $u = User::factory()->create();
        $u->assignRole($r);

        return $u;
    }

    private function ctx()
    {
        $s = $this->u('STUDENT');
        $t = $this->u('TEACHER');
        $c = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        $c->teachers()->attach($t);
        CourseEnrollment::create(['course_id' => $c->id, 'student_id' => $s->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $l = Lesson::factory()->create(['course_id' => $c->id, 'created_by' => $t->id, 'status' => LessonStatus::Published, 'published_at' => now()]);

        return [$s, $t, $c, $l];
    }

    private function payload($x = [])
    {
        return array_merge(['title' => 'تمرین', 'status' => 'PUBLISHED', 'allow_late_submission' => false, 'allow_resubmission' => true, 'max_attempts' => 2], $x);
    }

    public function test_authoring_permissions_and_validation(): void
    {
        [$s,$t,$c,$l] = $this->ctx();
        $o = $this->u('TEACHER');
        $this->actingAs($t)->post("/teacher/courses/$c->id/lessons/$l->id/assignments", $this->payload())->assertRedirect();
        $this->actingAs($o)->post("/teacher/courses/$c->id/lessons/$l->id/assignments", $this->payload())->assertForbidden();
        $this->actingAs($s)->post("/teacher/courses/$c->id/lessons/$l->id/assignments", $this->payload())->assertForbidden();
        $this->actingAs($this->u('ADMIN'))->post("/admin/courses/$c->id/lessons/$l->id/assignments", $this->payload(['available_at' => now()->addDay(), 'due_at' => now()]))->assertSessionHasErrors('due_at');
    }

    public function test_attempts_late_and_review_are_server_controlled(): void
    {
        Carbon::setTestNow('2026-10-15 12:00');
        [$s,$t,$c,$l] = $this->ctx();
        $a = LessonAssignment::create([...$this->payload(['lesson_id' => $l->id, 'created_by' => $t->id, 'due_at' => now()->subHour(), 'allow_late_submission' => true])]);
        $this->actingAs($s)->post("/assignments/$a->id/submissions", ['text_content' => 'اول', 'attempt_number' => 9, 'student_id' => $t->id, 'status' => 'APPROVED', 'is_late' => false])->assertRedirect();
        $one = AssignmentSubmission::first();
        $this->assertSame(1, $one->attempt_number);
        $this->assertSame($s->id, $one->student_id);
        $this->assertTrue($one->is_late);
        $this->assertSame(SubmissionStatus::Submitted, $one->status);
        $this->actingAs($t)->patch("/submissions/$one->id/review", ['status' => 'REVISION_REQUESTED', 'reviewer_feedback' => 'اصلاح شود', 'reviewed_by' => $s->id])->assertRedirect();
        $this->actingAs($s)->post("/assignments/$a->id/submissions", ['text_content' => 'دوم'])->assertRedirect();
        $this->assertDatabaseHas('assignment_submissions', ['assignment_id' => $a->id, 'attempt_number' => 2]);
        $this->actingAs($s)->post("/assignments/$a->id/submissions", ['text_content' => 'سوم'])->assertStatus(409);
        Carbon::setTestNow();
    }

    public function test_locked_future_and_deadline_block_submission(): void
    {
        [$s,$t,$c,$l] = $this->ctx();
        $a = LessonAssignment::create([...$this->payload(['lesson_id' => $l->id, 'created_by' => $t->id, 'available_at' => now()->addDay()])]);
        $this->actingAs($s)->post("/assignments/$a->id/submissions", ['text_content' => 'نه'])->assertForbidden();
        $a->update(['available_at' => null, 'due_at' => now()->subHour(), 'allow_late_submission' => false]);
        $this->actingAs($s)->post("/assignments/$a->id/submissions", ['text_content' => 'نه'])->assertForbidden();
    }

    public function test_private_files_are_validated_and_download_is_idor_safe(): void
    {
        Storage::fake('local');
        [$student, $teacher, $course, $lesson] = $this->ctx();
        $other = $this->u('STUDENT');
        $assignment = LessonAssignment::create([...$this->payload(['lesson_id' => $lesson->id, 'created_by' => $teacher->id])]);
        $this->actingAs($student)->post("/assignments/$assignment->id/submissions", ['files' => [UploadedFile::fake()->image('pattern.webp')]])->assertRedirect();
        $file = SubmissionFile::firstOrFail();
        Storage::disk('local')->assertExists($file->storage_path);
        $this->actingAs($student)->get("/submission-files/$file->id")->assertOk();
        $this->actingAs($teacher)->get("/submission-files/$file->id")->assertOk();
        $this->actingAs($other)->get("/submission-files/$file->id")->assertForbidden();
        $second = LessonAssignment::create([...$this->payload(['lesson_id' => $lesson->id, 'created_by' => $teacher->id, 'title' => 'bad file'])]);
        $this->actingAs($student)->post("/assignments/$second->id/submissions", ['files' => [UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')]])->assertSessionHasErrors('files.0');
    }

    public function test_review_is_teacher_scoped_and_transition_rules_are_enforced(): void
    {
        [$student, $teacher, $course, $lesson] = $this->ctx();
        $outsider = $this->u('TEACHER');
        $assignment = LessonAssignment::create([...$this->payload(['lesson_id' => $lesson->id, 'created_by' => $teacher->id])]);
        $submission = $assignment->submissions()->create(['student_id' => $student->id, 'attempt_number' => 1, 'text_content' => 'work', 'status' => SubmissionStatus::Submitted, 'is_late' => false, 'submitted_at' => now()]);
        $this->actingAs($outsider)->patch("/submissions/$submission->id/review", ['status' => 'APPROVED'])->assertForbidden();
        $this->actingAs($teacher)->patch("/submissions/$submission->id/review", ['status' => 'APPROVED', 'reviewer_feedback' => 'ok'])->assertRedirect();
        $this->assertSame($teacher->id, $submission->fresh()->reviewed_by);
        $this->actingAs($teacher)->patch("/submissions/$submission->id/review", ['status' => 'REJECTED'])->assertSessionHasErrors('status');
    }

    public function test_archive_preserves_history_and_admin_can_restore(): void
    {
        [$student, $teacher, $course, $lesson] = $this->ctx();
        $admin = $this->u('ADMIN');
        $assignment = LessonAssignment::create([...$this->payload(['lesson_id' => $lesson->id, 'created_by' => $teacher->id])]);
        $submission = $assignment->submissions()->create(['student_id' => $student->id, 'attempt_number' => 1, 'text_content' => 'kept', 'status' => SubmissionStatus::Submitted, 'is_late' => false, 'submitted_at' => now()]);
        $this->actingAs($teacher)->delete("/assignments/$assignment->id")->assertRedirect();
        $this->assertSoftDeleted('lesson_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('assignment_submissions', ['id' => $submission->id]);
        $this->actingAs($student)->get("/student/assignments/$assignment->id")->assertNotFound();
        $this->actingAs($admin)->post("/admin/assignments/$assignment->id/restore")->assertRedirect();
        $this->assertNotSoftDeleted('lesson_assignments', ['id' => $assignment->id]);
    }

    public function test_scheduled_assignment_notification_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        [$student, $teacher, $course, $lesson] = $this->ctx();
        LessonAssignment::create([...$this->payload(['lesson_id' => $lesson->id, 'created_by' => $teacher->id, 'available_at' => now()])]);
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertCount(1, $student->fresh()->notifications);
        Carbon::setTestNow();
    }
}
