<?php

namespace App\Enums;

enum CourseNavigationMode: string
{
    case Free = 'FREE';
    case Sequential = 'SEQUENTIAL';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'آزاد',
            self::Sequential => 'ترتیبی',
        };
    }
}
