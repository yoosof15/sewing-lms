<?php

namespace App\Policies;

use App\Enums\LessonQuestionStatus;
use App\Models\LessonQuestion;
use App\Models\User;
use App\Services\StudentLessonAccess;

class LessonQuestionPolicy
{
    public function before(User $u): ?bool
    {
        return $u->hasRole('ADMIN') ? true : null;
    }

    public function view(User $u, LessonQuestion $q): bool
    {
        if ($u->hasRole('STUDENT')) {
            return $q->student_id === $u->id && app(StudentLessonAccess::class)->allows($u, $q->lesson);
        }

return $u->hasRole('TEACHER') && $q->lesson->course->teachers()->whereKey($u->id)->exists();
    }

    public function reply(User $u, LessonQuestion $q): bool
    {
        return $q->status !== LessonQuestionStatus::Closed && $this->view($u, $q);
    }

    public function manage(User $u, LessonQuestion $q): bool
    {
        return $u->hasRole('TEACHER') && $q->lesson->course->teachers()->whereKey($u->id)->exists();
    }

    public function delete(User $u, LessonQuestion $q): bool
    {
        return false;
    }
}
