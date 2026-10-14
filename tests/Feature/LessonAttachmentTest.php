<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function user(string $r): User
    {
        $u = User::factory()->create();
        $u->assignRole($r);

        return $u;
    }

    private function setupLesson(User $creator, LessonStatus $status = LessonStatus::Published): array
    {
        $c = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now()]);
        $l = Lesson::factory()->create(['course_id' => $c->id, 'created_by' => $creator->id, 'status' => $status, 'published_at' => $status === LessonStatus::Published ? now() : null]);

        return [$c, $l];
    }

    private function attachment(Lesson $l, User $u, string $path = 'lesson-attachments/file.pdf'): LessonAttachment
    {
        Storage::disk('local')->put($path, 'pdf');

        return LessonAttachment::create(['lesson_id' => $l->id, 'uploaded_by' => $u->id, 'original_name' => 'file.pdf', 'storage_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => 3]);
    }

    public function test_admin_and_assigned_teacher_can_upload_allowed_attachment(): void
    {
        $a = $this->user('ADMIN');
        [$c,$l] = $this->setupLesson($a);
        $this->actingAs($a)->post("/lessons/$l->id/attachments", ['attachment' => UploadedFile::fake()->create('guide.pdf', 10, 'application/pdf')])->assertRedirect();
        $t = $this->user('TEACHER');
        $c->teachers()->attach($t);
        $own = Lesson::factory()->create(['course_id' => $c->id, 'created_by' => $t->id, 'sort_order' => 2]);
        $this->actingAs($t)->post("/lessons/$own->id/attachments", ['attachment' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain')])->assertRedirect();
        $this->assertDatabaseCount('lesson_attachments', 2);
    }

    public function test_video_and_invalid_mime_are_rejected(): void
    {
        $a = $this->user('ADMIN');
        [, $l] = $this->setupLesson($a);
        $this->actingAs($a)->post("/lessons/$l->id/attachments", ['attachment' => UploadedFile::fake()->create('video.mp4', 50, 'video/mp4')])->assertSessionHasErrors('attachment');
        $this->actingAs($a)->post("/lessons/$l->id/attachments", ['attachment' => UploadedFile::fake()->create('script.exe', 2, 'application/x-msdownload')])->assertSessionHasErrors('attachment');
    }

    public function test_valid_student_can_download_but_unenrolled_expired_and_draft_access_are_blocked(): void
    {
        $a = $this->user('ADMIN');
        [$c,$l] = $this->setupLesson($a);
        $file = $this->attachment($l, $a);
        $valid = $this->user('STUDENT');
        CourseEnrollment::create(['course_id' => $c->id, 'student_id' => $valid->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $this->actingAs($valid)->get("/attachments/$file->id/download")->assertDownload('file.pdf');
        $none = $this->user('STUDENT');
        $this->actingAs($none)->get("/attachments/$file->id/download")->assertForbidden();
        $expired = $this->user('STUDENT');
        CourseEnrollment::create(['course_id' => $c->id, 'student_id' => $expired->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'ends_at' => now()->subDay()]);
        $this->actingAs($expired)->get("/attachments/$file->id/download")->assertForbidden();
        $draft = Lesson::factory()->create(['course_id' => $c->id, 'created_by' => $a->id, 'sort_order' => 2, 'status' => LessonStatus::Draft]);
        $draftFile = $this->attachment($draft, $a, 'lesson-attachments/draft.pdf');
        $this->actingAs($valid)->get("/attachments/$draftFile->id/download")->assertForbidden();
    }

    public function test_path_manipulation_is_not_served(): void
    {
        $a = $this->user('ADMIN');
        [, $l] = $this->setupLesson($a);
        $file = LessonAttachment::create(['lesson_id' => $l->id, 'uploaded_by' => $a->id, 'original_name' => '.env', 'storage_path' => '../.env', 'mime_type' => 'text/plain', 'file_size' => 1]);
        $this->actingAs($a)->get("/attachments/$file->id/download")->assertNotFound();
    }
}
