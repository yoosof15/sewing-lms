<?php

namespace App\Policies;

use App\Models\LessonVideo;
use App\Models\User;

class LessonVideoPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('ADMIN') ? true : null;
    }

    public function update(User $user, LessonVideo $video): bool
    {
        return $user->can('update', $video->lesson);
    }

    public function delete(User $user, LessonVideo $video): bool
    {
        return $this->update($user, $video);
    }
}
