<?php

namespace Tests\Feature;

use App\Enums\CourseNavigationMode;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonQuestionStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $r): User
    {
        $u = User::factory()->create();
        $u->assignRole($r);

        return $u;
    }

    private function context(): array
    {
        $student = $this->user('STUDENT');
        $teacher = $this->user('TEACHER');
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now(), 'navigation_mode' => CourseNavigationMode::Free]);
        $course->teachers()->attach($teacher);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'status' => LessonStatus::Published, 'published_at' => now()]);

        return [$student, $teacher, $course, $lesson];
    }

    public function test_student_can_ask_only_on_accessible_lesson_and_teacher_is_notified(): void
    {
        [$s,$t,$c,$l] = $this->context();
        $this->actingAs($s)->post("/student/courses/$c->id/lessons/$l->id/questions", ['subject' => 'کمک', 'body' => 'این بخش را متوجه نشدم'])->assertRedirect();
        $q = LessonQuestion::first();
        $this->assertSame($s->id, $q->student_id);
        $this->assertSame(LessonQuestionStatus::Open, $q->status);
        $this->assertCount(1, $t->fresh()->notifications);
        $other = $this->user('STUDENT');
        $this->actingAs($other)->post("/student/courses/$c->id/lessons/$l->id/questions", ['body' => 'سؤال غیرمجاز'])->assertForbidden();
        $l->update(['available_at' => now()->addDay()]);
        $this->actingAs($s)->post("/student/courses/$c->id/lessons/$l->id/questions", ['body' => 'سؤال قفل‌شده'])->assertForbidden();
    }

    public function test_private_thread_teacher_answer_status_and_notifications(): void
    {
        [$s,$t,$c,$l] = $this->context();
        $other = $this->user('STUDENT');
        $unassigned = $this->user('TEACHER');
        $q = LessonQuestion::create(['lesson_id' => $l->id, 'student_id' => $s->id, 'body' => 'پرسش خصوصی', 'status' => LessonQuestionStatus::Open, 'last_activity_at' => now()]);
        $this->actingAs($other)->get("/questions/$q->id")->assertForbidden();
        $this->actingAs($unassigned)->get("/questions/$q->id")->assertForbidden();
        $this->actingAs($t)->post("/questions/$q->id/replies", ['body' => 'پاسخ مدرس'])->assertRedirect();
        $this->assertSame(LessonQuestionStatus::Answered, $q->fresh()->status);
        $this->assertCount(1, $s->fresh()->notifications);
        $this->actingAs($t)->patch("/questions/$q->id/status", ['status' => 'RESOLVED'])->assertRedirect();
        $this->actingAs($s)->post("/questions/$q->id/replies", ['body' => 'پیگیری مجدد'])->assertRedirect();
        $this->assertSame(LessonQuestionStatus::Answered, $q->fresh()->status);
        $this->actingAs($t)->patch("/questions/$q->id/status", ['status' => 'CLOSED']);
        $this->actingAs($s)->post("/questions/$q->id/replies", ['body' => 'نباید ثبت شود'])->assertForbidden();
    }

    public function test_client_cannot_spoof_question_owner_or_status(): void
    {
        [$s,$t,$c,$l] = $this->context();
        $other = $this->user('STUDENT');
        $this->actingAs($s)->post("/student/courses/$c->id/lessons/$l->id/questions", ['body' => 'متن معتبر', 'student_id' => $other->id, 'status' => 'CLOSED'])->assertRedirect();
        $q = LessonQuestion::first();
        $this->assertSame($s->id, $q->student_id);
        $this->assertSame(LessonQuestionStatus::Open, $q->status);
    }

    public function test_question_images_are_private_and_validated(): void
    {
        Storage::fake('local');
        [$student, $teacher, $course, $lesson] = $this->context();
        $other = $this->user('STUDENT');
        $this->actingAs($student)->post("/student/courses/$course->id/lessons/$lesson->id/questions", ['body' => 'تصویر مشکل دوخت', 'images' => [UploadedFile::fake()->image('sewing.jpg')]])->assertRedirect();
        $attachment = LessonQuestion::first()->attachments()->first();
        $this->actingAs($student)->get("/question-attachments/$attachment->id")->assertOk();
        $this->actingAs($teacher)->get("/question-attachments/$attachment->id")->assertOk();
        $this->actingAs($other)->get("/question-attachments/$attachment->id")->assertForbidden();
        $this->actingAs($student)->post("/student/courses/$course->id/lessons/$lesson->id/questions", ['body' => 'فایل نامعتبر', 'images' => [UploadedFile::fake()->create('bad.exe', 10)]])->assertSessionHasErrors('images.0');
    }

    public function test_question_creation_is_rate_limited(): void
    {
        [$student, , $course, $lesson] = $this->context();
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($student)->post("/student/courses/$course->id/lessons/$lesson->id/questions", ['body' => "پرسش شماره $i"])->assertRedirect();
        }
        $this->actingAs($student)->post("/student/courses/$course->id/lessons/$lesson->id/questions", ['body' => 'پرسش اضافی'])->assertTooManyRequests();
    }
}
