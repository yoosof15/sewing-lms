<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class PhaseTwoDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('PhaseTwoDemoSeeder may run only in the local environment.');
        }

        $categories = collect([
            ['name' => 'مقدماتی', 'sort_order' => 10],
            ['name' => 'دوخت لباس', 'sort_order' => 20],
            ['name' => 'الگوسازی', 'sort_order' => 30],
        ])->map(fn ($data) => CourseCategory::firstOrCreate(['name' => $data['name']], $data + ['is_active' => true]));

        $teachers = User::role('TEACHER')->where('is_active', true)->get();
        foreach (['آشنایی با خیاطی', 'دوخت مانتوی ساده', 'اصول اولیه الگوسازی'] as $index => $title) {
            $course = Course::firstOrCreate(['title' => $title], ['category_id' => $categories[$index]->id, 'short_description' => 'داده نمونه محلی برای بررسی رابط کاربری.', 'status' => $index === 0 ? CourseStatus::Published : CourseStatus::Draft, 'published_at' => $index === 0 ? now() : null]);
            if ($teachers->isNotEmpty()) {
                $course->teachers()->syncWithoutDetaching([$teachers[$index % $teachers->count()]->id]);
            }
        }
        $this->command?->info('Local Phase 2 demo catalog is ready; existing users were not changed.');
    }
}
