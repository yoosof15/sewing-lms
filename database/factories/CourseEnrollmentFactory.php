<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseEnrollment> */
class CourseEnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'student_id' => User::factory(), 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'starts_at' => null, 'ends_at' => null, 'created_by' => null];
    }
}
