<?php

namespace App\Policies;

use App\Models\LessonQuestionAttachment;
use App\Models\User;

class LessonQuestionAttachmentPolicy
{
    public function view(User $u, LessonQuestionAttachment $a): bool
    {
        return $u->can('view', $a->question);
    }
}
