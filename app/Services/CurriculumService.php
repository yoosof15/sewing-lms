<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Course;
use Illuminate\Support\Collection;

class CurriculumService
{
    public function lessons(Course $course, bool $publishedOnly = true): Collection
    {
        $query = $course->lessons()->with([
            'section' => fn ($query) => $query->withTrashed(),
            'prerequisites' => fn ($query) => $query->withTrashed(),
        ]);

        if ($publishedOnly) {
            $query->where('status', LessonStatus::Published->value);
        }

        return $query->get()->filter(fn ($lesson) => $lesson->section_id === null || ! $lesson->section?->trashed())
            ->sort(function ($left, $right) {
                $leftKey = [$left->section_id === null ? 0 : 1, $left->section?->sort_order ?? 0, $left->sort_order];
                $rightKey = [$right->section_id === null ? 0 : 1, $right->section?->sort_order ?? 0, $right->sort_order];

                return $leftKey <=> $rightKey;
            })->values();
    }
}
