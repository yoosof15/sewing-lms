<?php

namespace App\Enums;

enum LessonQuestionStatus: string
{
    case Open = 'OPEN';
    case Answered = 'ANSWERED';
    case Resolved = 'RESOLVED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'باز',self::Answered => 'پاسخ‌داده‌شده',self::Resolved => 'حل‌شده',self::Closed => 'بسته'
        };
    }
}
