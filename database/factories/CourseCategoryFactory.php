<?php

namespace Database\Factories;

use App\Models\CourseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseCategory> */
class CourseCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'description' => fake()->optional()->sentence(), 'is_active' => true, 'sort_order' => 0];
    }
}
