<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Models\LessonAssignment;
use App\Models\User;

class StudentAssignmentAccess
{
    public function __construct(private StudentLessonAccess $lessons) {}

    public function allows(User $u, LessonAssignment $a, bool $submitting = false): bool
    {
        if (! $u->hasRole('STUDENT') || $a->trashed() || $a->status !== AssignmentStatus::Published || $a->available_at?->isFuture() || ! $this->lessons->allows($u, $a->lesson)) {
            return false;
        }if ($submitting && $a->due_at?->isPast() && ! $a->allow_late_submission) {
            return false;
        }

        return true;
    }
}
