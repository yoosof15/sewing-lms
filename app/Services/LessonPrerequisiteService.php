<?php

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonPrerequisiteService
{
    public function validate(Lesson $lesson, array $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        if ($lesson->exists && $ids->contains($lesson->id)) {
            $this->fail('یک درس نمی‌تواند پیش‌نیاز خودش باشد.');
        }
        if (Lesson::withTrashed()->whereIn('id', $ids)->where('course_id', '!=', $lesson->course_id)->exists() || Lesson::withTrashed()->whereIn('id', $ids)->count() !== $ids->count()) {
            $this->fail('همه پیش‌نیازها باید از همین دوره باشند.');
        }
        if (! $lesson->exists) {
            return $ids->all();
        }

        $edges = DB::table('lesson_prerequisites')->join('lessons', 'lessons.id', '=', 'lesson_prerequisites.lesson_id')
            ->where('lessons.course_id', $lesson->course_id)->where('lesson_id', '!=', $lesson->id)
            ->get(['lesson_id', 'prerequisite_lesson_id'])->groupBy('lesson_id')
            ->map(fn ($rows) => $rows->pluck('prerequisite_lesson_id')->map(fn ($id) => (int) $id)->all())->all();
        $edges[$lesson->id] = $ids->all();
        foreach ($ids as $id) {
            if ($this->reaches($id, $lesson->id, $edges, [])) {
                $this->fail('این پیش‌نیازها یک چرخه وابستگی ایجاد می‌کنند.');
            }
        }

        return $ids->all();
    }

    private function reaches(int $from, int $target, array $edges, array $seen): bool
    {
        if ($from === $target) {
            return true;
        }
        if (isset($seen[$from])) {
            return false;
        }
        $seen[$from] = true;
        foreach ($edges[$from] ?? [] as $next) {
            if ($this->reaches((int) $next, $target, $edges, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['prerequisite_ids' => $message]);
    }
}
