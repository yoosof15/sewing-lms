<?php

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        return ['category_id' => CourseCategory::factory(), 'title' => fake()->unique()->sentence(3), 'short_description' => fake()->sentence(), 'description' => fake()->paragraph(), 'status' => CourseStatus::Draft, 'published_at' => null];
    }
}
