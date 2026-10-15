<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\User;

class LessonAssignmentPolicy
{
    public function before(User $u): ?bool
    {
        return $u->hasRole('ADMIN') ? true : null;
    }

    public function create(User $u, Lesson $l): bool
    {
        return $u->hasRole('TEACHER') && (int) $l->created_by === (int) $u->id && $l->course->teachers()->whereKey($u->id)->exists();
    }

    public function update(User $u, LessonAssignment $a): bool
    {
        return $u->hasRole('TEACHER') && (int) $a->lesson->created_by === (int) $u->id && $a->lesson->course->teachers()->whereKey($u->id)->exists();
    }

    public function view(User $u, LessonAssignment $a): bool
    {
        return $this->update($u, $a);
    }

    public function delete(User $u, LessonAssignment $a): bool
    {
        return $this->update($u, $a);
    }

    public function restore(User $u, LessonAssignment $a): bool
    {
        return $u->hasRole('ADMIN');
    }
}
