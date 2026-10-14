<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Services\StudentCourseAccess;

class CoursePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('ADMIN') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('TEACHER');
    }

    public function view(User $user, Course $course): bool
    {
        if ($user->hasRole('TEACHER')) {
            return $course->teachers()->whereKey($user->id)->exists();
        }

        return $user->hasRole('STUDENT') && app(StudentCourseAccess::class)->allows($user, $course);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Course $course): bool
    {
        return false;
    }

    public function delete(User $user, Course $course): bool
    {
        return false;
    }
}
