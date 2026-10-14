<?php

namespace Tests\Feature;

use App\Enums\CourseNavigationMode;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonAccessState;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonManualCompletion;
use App\Models\User;
use App\Services\CurriculumService;
use App\Services\LearningProgressService;
use App\Services\StudentLessonAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhaseNineCurriculumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function context(string $mode = 'FREE'): array
    {
        $student = $this->user('STUDENT');
        $course = Course::factory()->create(['status' => CourseStatus::Published, 'published_at' => now(), 'navigation_mode' => $mode]);
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now()]);

        return [$student, $course];
    }

    private function lesson(Course $course, int $order, array $attributes = []): Lesson
    {
        return Lesson::factory()->create(array_merge(['course_id' => $course->id, 'sort_order' => $order, 'status' => LessonStatus::Published, 'published_at' => now()], $attributes));
    }

    public function test_section_permissions_order_and_safe_archive(): void
    {
        $course = Course::factory()->create();
        $admin = $this->user('ADMIN');
        $teacher = $this->user('TEACHER');
        $other = $this->user('TEACHER');
        $course->teachers()->attach($teacher);
        $this->actingAs($teacher)->post("/teacher/courses/$course->id/sections", ['title' => 'فصل یک'])->assertRedirect();
        $this->actingAs($other)->post("/teacher/courses/$course->id/sections", ['title' => 'غیرمجاز'])->assertForbidden();
        $second = $course->sections()->create(['title' => 'فصل دو', 'sort_order' => 2]);
        $first = $course->sections()->first();
        $this->actingAs($admin)->post("/admin/courses/$course->id/sections/$second->id/move/up")->assertRedirect();
        $this->assertSame(1, $second->fresh()->sort_order);
        $this->lesson($course, 1, ['section_id' => $first->id]);
        $this->actingAs($admin)->delete("/admin/courses/$course->id/sections/$first->id")->assertStatus(422);
    }

    public function test_free_schedule_and_sequential_access_are_server_enforced(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        [$student, $course] = $this->context();
        $open = $this->lesson($course, 1);
        $future = $this->lesson($course, 2, ['available_at' => now()->addDay()]);
        $access = app(StudentLessonAccess::class);
        $this->assertSame(LessonAccessState::Available->value, $access->decision($student, $open)['state']);
        $this->assertSame(LessonAccessState::Scheduled->value, $access->decision($student, $future)['state']);
        $this->actingAs($student)->get("/student/courses/$course->id/lessons/$future->id")->assertForbidden();
        $course->update(['navigation_mode' => CourseNavigationMode::Sequential]);
        $future->update(['available_at' => null]);
        $this->assertSame(LessonAccessState::LockedSequence->value, $access->decision($student, $future->fresh())['state']);
        LessonManualCompletion::create(['user_id' => $student->id, 'lesson_id' => $open->id, 'completed_at' => now()]);
        $this->assertTrue($access->allows($student, $future->fresh()));
        Carbon::setTestNow();
    }

    public function test_prerequisites_validate_course_self_cycles_and_completion(): void
    {
        [$student, $course] = $this->context();
        $admin = $this->user('ADMIN');
        $first = $this->lesson($course, 1);
        $second = $this->lesson($course, 2);
        $third = $this->lesson($course, 3);
        $payload = fn (Lesson $lesson, array $ids) => ['title' => $lesson->title, 'content' => $lesson->content, 'status' => 'PUBLISHED', 'prerequisite_ids' => $ids];
        $this->actingAs($admin)->put("/admin/courses/$course->id/lessons/$second->id", $payload($second, [$first->id]))->assertRedirect();
        $this->assertSame(LessonAccessState::LockedPrerequisite->value, app(StudentLessonAccess::class)->decision($student, $second->fresh())['state']);
        LessonManualCompletion::create(['user_id' => $student->id, 'lesson_id' => $first->id, 'completed_at' => now()]);
        $this->assertTrue(app(StudentLessonAccess::class)->allows($student, $second->fresh()));
        $this->actingAs($admin)->put("/admin/courses/$course->id/lessons/$first->id", $payload($first, [$first->id]))->assertSessionHasErrors('prerequisite_ids.0');
        $this->actingAs($admin)->put("/admin/courses/$course->id/lessons/$third->id", $payload($third, [$second->id]))->assertRedirect();
        $this->actingAs($admin)->put("/admin/courses/$course->id/lessons/$first->id", $payload($first, [$third->id]))->assertSessionHasErrors('prerequisite_ids');
        $other = Course::factory()->create();
        $foreign = Lesson::factory()->create(['course_id' => $other->id]);
        $this->actingAs($admin)->put("/admin/courses/$course->id/lessons/$second->id", $payload($second, [$foreign->id]))->assertSessionHasErrors('prerequisite_ids.0');
    }

    public function test_unsectioned_lessons_sort_before_sections_and_future_is_excluded_from_progress(): void
    {
        [$student, $course] = $this->context();
        $section = CourseSection::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);
        $sectioned = $this->lesson($course, 1, ['section_id' => $section->id]);
        $plain = $this->lesson($course, 2);
        $this->lesson($course, 3, ['available_at' => now()->addDay()]);
        $this->assertSame($plain->id, app(CurriculumService::class)->lessons($course)->first()->id);
        $this->assertSame(2, app(LearningProgressService::class)->course($student, $course)['total_lessons']);
    }
}
