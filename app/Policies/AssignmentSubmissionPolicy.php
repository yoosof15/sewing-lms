<?php

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;

class AssignmentSubmissionPolicy
{
    public function before(User $u): ?bool
    {
        return $u->hasRole('ADMIN') ? true : null;
    }

    public function view(User $u, AssignmentSubmission $s): bool
    {
        if ($u->hasRole('STUDENT')) {
            return $s->student_id === $u->id;
        }

        return $u->hasRole('TEACHER') && $s->assignment->lesson->course->teachers()->whereKey($u->id)->exists();
    }

    public function review(User $u, AssignmentSubmission $s): bool
    {
        return $u->hasRole('TEACHER') && $s->assignment->lesson->course->teachers()->whereKey($u->id)->exists();
    }
}
