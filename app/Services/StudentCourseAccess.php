<?php

namespace App\Services;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StudentCourseAccess
{
    public function allows(User $student, Course $course): bool
    {
        if (! $student->hasRole('STUDENT') || $course->status !== CourseStatus::Published || $course->trashed()) {
            return false;
        }

        return $course->enrollments()->where('student_id', $student->id)->where('status', EnrollmentStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->exists();
    }

    public function coursesFor(User $student): Builder
    {
        return Course::query()->where('status', CourseStatus::Published->value)->whereHas('enrollments', fn ($q) => $q
            ->where('student_id', $student->id)->where('status', EnrollmentStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now())));
    }
}
