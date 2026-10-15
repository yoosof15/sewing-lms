<?php

namespace App\Policies;

use App\Models\SubmissionFile;
use App\Models\User;

class SubmissionFilePolicy
{
    public function view(User $u, SubmissionFile $f): bool
    {
        return $u->can('view', $f->submission);
    }
}
