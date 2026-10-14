<?php

namespace App\Services;

use App\Enums\CourseNavigationMode;
use App\Enums\LessonAccessState;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Collection;

class StudentLessonAccess
{
    public function __construct(
        private readonly StudentCourseAccess $courseAccess,
        private readonly CurriculumService $curriculum,
        private readonly LearningProgressService $progress,
    ) {}

    public function decision(User $student, Lesson $lesson, ?array $completion = null, ?Collection $ordered = null): array
    {
        $lesson->loadMissing(['course', 'section' => fn ($q) => $q->withTrashed(), 'prerequisites' => fn ($q) => $q->withTrashed()]);
        $course = $lesson->course;
        if (! $course || $lesson->trashed() || $lesson->status !== LessonStatus::Published || ($lesson->section_id && $lesson->section?->trashed()) || ! $this->courseAccess->allows($student, $course)) {
            return $this->result(LessonAccessState::Unavailable, 'این درس در دسترس نیست.');
        }
        if ($lesson->available_at?->isFuture()) {
            return $this->result(LessonAccessState::Scheduled, 'این درس از زمان تعیین‌شده در دسترس خواهد بود.', $lesson->available_at->toIso8601String());
        }

        $ordered ??= $this->curriculum->lessons($course);
        $completion ??= $this->progress->lessonCompletionMap($student, $ordered->merge($lesson->prerequisites)->unique('id'));
        $blockedPrerequisite = $lesson->prerequisites->contains(fn ($required) => $required->trashed() || $required->course_id !== $course->id || $required->status !== LessonStatus::Published || ! ($completion[$required->id] ?? false));
        if ($blockedPrerequisite) {
            return $this->result(LessonAccessState::LockedPrerequisite, 'برای ورود به این درس ابتدا پیش‌نیازهای آن را تکمیل کنید.');
        }
        if ($course->navigation_mode === CourseNavigationMode::Sequential) {
            $index = $ordered->search(fn ($item) => $item->id === $lesson->id);
            if ($index === false) {
                return $this->result(LessonAccessState::Unavailable, 'این درس در دسترس نیست.');
            }
            if ($index > 0 && ! ($completion[$ordered[$index - 1]->id] ?? false)) {
                return $this->result(LessonAccessState::LockedSequence, 'ابتدا جلسه قبلی را تکمیل کنید.');
            }
        }

        return $this->result(LessonAccessState::Available, null);
    }

    public function allows(User $student, Lesson $lesson): bool
    {
        return $this->decision($student, $lesson)['state'] === LessonAccessState::Available->value;
    }

    public function decisions(User $student, Collection $lessons): array
    {
        $completion = $this->progress->lessonCompletionMap($student, $lessons->flatMap(fn ($lesson) => collect([$lesson])->merge($lesson->prerequisites))->unique('id'));

        return $lessons->mapWithKeys(fn ($lesson) => [$lesson->id => $this->decision($student, $lesson, $completion, $lessons)])->all();
    }

    private function result(LessonAccessState $state, ?string $reason, ?string $availableAt = null): array
    {
        return ['state' => $state->value, 'can_view' => $state === LessonAccessState::Available, 'reason' => $reason, 'available_at' => $availableAt];
    }
}
