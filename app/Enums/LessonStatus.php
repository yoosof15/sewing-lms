<?php

namespace App\Enums;

enum LessonStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Inactive = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس', self::Published => 'منتشرشده', self::Inactive => 'غیرفعال'
        };
    }
}
