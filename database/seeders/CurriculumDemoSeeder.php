<?php

namespace Database\Seeders;

use App\Enums\CourseNavigationMode;
use App\Enums\CourseStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CurriculumDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('CurriculumDemoSeeder may run only in the local environment.');
        }
        $creator = User::role('ADMIN')->first() ?? User::role('TEACHER')->first();
        if (! $creator) {
            throw new RuntimeException('Create a local admin or teacher before running the curriculum demo seeder.');
        }
        DB::transaction(function () use ($creator) {
            $course = Course::firstOrCreate(['slug' => 'nazok-doozi-demo'], ['title' => 'دوره نازک‌دوزی', 'description' => 'داده نمایشی ساختار برنامه درسی', 'status' => CourseStatus::Published, 'published_at' => now(), 'navigation_mode' => CourseNavigationMode::Sequential]);
            $sections = collect(['مقدمات', 'الگو', 'دوخت'])->mapWithKeys(fn ($title, $index) => [$title => $course->sections()->firstOrCreate(['title' => $title], ['sort_order' => $index + 1])]);
            $specs = [['آشنایی با ابزار', 'مقدمات', null], ['اندازه‌گیری', 'مقدمات', null], ['رسم الگوی پایه', 'الگو', null], ['برش پارچه', 'الگو', null], ['شروع دوخت', 'دوخت', now()->addDays(7)]];
            $lessons = collect($specs)->map(fn ($spec, $index) => $course->lessons()->firstOrCreate(['title' => $spec[0]], ['section_id' => $sections[$spec[1]]->id, 'content' => 'محتوای نمایشی', 'sort_order' => $index + 1, 'status' => LessonStatus::Published, 'published_at' => now(), 'available_at' => $spec[2], 'created_by' => $creator->id]));
            $lessons[3]->prerequisites()->syncWithoutDetaching([$lessons[1]->id]);
        });
    }
}
