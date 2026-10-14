<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;

class CourseSectionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('ADMIN') ? true : null;
    }

    public function create(User $user, Course $course): bool
    {
        return $user->hasRole('TEACHER') && $course->teachers()->whereKey($user->id)->exists();
    }

    public function update(User $user, CourseSection $section): bool
    {
        return $user->hasRole('TEACHER') && $section->course->teachers()->whereKey($user->id)->exists();
    }

    public function delete(User $user, CourseSection $section): bool
    {
        return $this->update($user, $section);
    }
}
