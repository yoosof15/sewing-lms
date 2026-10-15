<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED';
    case RevisionRequested = 'REVISION_REQUESTED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'ارسال‌شده',self::UnderReview => 'در حال بررسی',self::Approved => 'تأیید شده',self::RevisionRequested => 'نیاز به اصلاح',self::Rejected => 'رد شده'
        };
    }
}
