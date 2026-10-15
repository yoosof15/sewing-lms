<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SubmissionReviewService
{
    public function review(AssignmentSubmission $s, User $reviewer, SubmissionStatus $to, ?string $feedback): void
    {
        $allowed = [SubmissionStatus::Submitted->value => [SubmissionStatus::UnderReview, SubmissionStatus::Approved, SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected], SubmissionStatus::UnderReview->value => [SubmissionStatus::Approved, SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected]];
        if (! in_array($to, $allowed[$s->status->value] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'این تغییر وضعیت مجاز نیست.']);
        }$s->update(['status' => $to, 'reviewer_feedback' => $feedback, 'reviewed_at' => now(), 'reviewed_by' => $reviewer->id]);
    }
}
