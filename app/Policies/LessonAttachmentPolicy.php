<?php

namespace App\Policies;

use App\Models\LessonAttachment;
use App\Models\User;

class LessonAttachmentPolicy
{
    public function download(User $user, LessonAttachment $attachment): bool
    {
        return $user->can('view', $attachment->lesson);
    }

    public function create(User $user, LessonAttachment $attachment): bool
    {
        return $user->can('update', $attachment->lesson);
    }
}
