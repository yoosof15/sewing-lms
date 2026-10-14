<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lesson> */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'title' => fake()->unique()->sentence(3), 'content' => fake()->paragraph(), 'sort_order' => 1, 'status' => LessonStatus::Draft, 'published_at' => null, 'created_by' => User::factory()];
    }
}
