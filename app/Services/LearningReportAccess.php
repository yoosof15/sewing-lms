<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;

class LearningReportAccess
{
    public function course(User $user, Course $course): bool
    {
        return $user->hasRole('ADMIN')
            || ($user->hasRole('TEACHER') && $course->teachers()->whereKey($user->id)->exists());
    }

    public function student(User $user, User $student): bool
    {
        if ($user->hasRole('ADMIN')) {
            return true;
        }

        return $user->hasRole('TEACHER') && $student->enrollments()
            ->whereHas('course.teachers', fn ($query) => $query->whereKey($user->id))->exists();
    }
}
