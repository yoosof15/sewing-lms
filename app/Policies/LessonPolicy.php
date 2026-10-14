<?php

namespace App\Policies;

use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\StudentCourseAccess;

class LessonPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('ADMIN') ? true : null;
    }

    public function create(User $user, Course $course): bool
    {
        return $user->hasRole('TEACHER') && $course->teachers()->whereKey($user->id)->exists();
    }

    public function view(User $user, Lesson $lesson): bool
    {
        if ($user->hasRole('TEACHER')) {
            return $lesson->course->teachers()->whereKey($user->id)->exists();
        }

        return $user->hasRole('STUDENT') && $lesson->status === LessonStatus::Published && app(StudentCourseAccess::class)->allows($user, $lesson->course);
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $user->hasRole('TEACHER') && $lesson->created_by === $user->id && $lesson->course->teachers()->whereKey($user->id)->exists();
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->update($user, $lesson);
    }
}
