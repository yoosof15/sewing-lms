<?php

namespace App\Services;

use App\Contracts\VideoStorage;
use App\Enums\LessonStatus;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonManualCompletion;
use App\Models\LessonVideo;
use App\Models\User;
use App\Models\VideoProgress;
use Illuminate\Support\Collection;

class LearningProgressService
{
    public function __construct(private readonly VideoStorage $storage) {}

    public function courses(User $student, Collection $courses): array
    {
        $courseIds = $courses->pluck('id');
        $lessons = Lesson::query()->leftJoin('course_sections', 'course_sections.id', '=', 'lessons.section_id')
            ->whereIn('lessons.course_id', $courseIds)->where('lessons.status', LessonStatus::Published->value)
            ->where(fn ($query) => $query->whereNull('lessons.available_at')->orWhere('lessons.available_at', '<=', now()))
            ->whereNull('course_sections.deleted_at')->get(['lessons.id', 'lessons.course_id']);
        $videos = LessonVideo::query()->whereIn('lesson_id', $lessons->pluck('id'))->where('status', VideoStatus::Ready->value)
            ->where('publication_status', VideoPublicationStatus::Published->value)->get(['id', 'lesson_id', 'processed_storage_key'])
            ->filter(fn (LessonVideo $video) => $video->processed_storage_key && $this->storage->exists($video->processed_storage_key));
        $progress = VideoProgress::where('user_id', $student->id)->whereIn('lesson_video_id', $videos->pluck('id'))->get(['lesson_video_id', 'watched_seconds', 'completed_at'])->keyBy('lesson_video_id');
        $manual = LessonManualCompletion::where('user_id', $student->id)->whereIn('lesson_id', $lessons->pluck('id'))->pluck('lesson_id')->flip();
        $summaries = [];
        foreach ($courses as $course) {
            $courseLessons = $lessons->where('course_id', $course->id);
            $states = [];
            foreach ($courseLessons as $lesson) {
                $ids = $videos->where('lesson_id', $lesson->id)->pluck('id');
                $completed = $ids->isEmpty() ? $manual->has($lesson->id) : $ids->every(fn ($id) => $progress->get($id)?->completed_at !== null);
                $started = $ids->contains(fn ($id) => ($progress->get($id)?->watched_seconds ?? 0) > 0);
                $states[$lesson->id] = $completed ? 'completed' : ($started ? 'in_progress' : 'not_started');
            }
            $completedCount = collect($states)->filter(fn ($state) => $state === 'completed')->count();
            $total = $courseLessons->count();
            $summaries[$course->id] = ['percent' => $total ? (int) round($completedCount / $total * 100) : 0, 'completed_lessons' => $completedCount, 'total_lessons' => $total, 'lessons' => $states];
        }

        return $summaries;
    }

    public function lesson(User $student, Lesson $lesson): array
    {
        $videoIds = $lesson->videos()->where('status', VideoStatus::Ready->value)
            ->where('publication_status', VideoPublicationStatus::Published->value)->get(['id', 'processed_storage_key'])
            ->filter(fn (LessonVideo $video) => $video->processed_storage_key && $this->storage->exists($video->processed_storage_key))->pluck('id');
        if ($videoIds->isEmpty()) {
            $complete = LessonManualCompletion::where('user_id', $student->id)->where('lesson_id', $lesson->id)->exists();

            return ['state' => $complete ? 'completed' : 'not_started', 'completed' => $complete, 'manual_allowed' => true];
        }
        $progress = VideoProgress::where('user_id', $student->id)->whereIn('lesson_video_id', $videoIds)->get();
        $complete = $progress->whereNotNull('completed_at')->count() === $videoIds->count();

        return ['state' => $complete ? 'completed' : ($progress->where('watched_seconds', '>', 0)->isNotEmpty() ? 'in_progress' : 'not_started'), 'completed' => $complete, 'manual_allowed' => false];
    }

    public function lessonCompletionMap(User $student, Collection $lessons): array
    {
        $lessons = $lessons->unique('id')->values();
        if ($lessons->isEmpty()) {
            return [];
        }
        $videos = LessonVideo::query()->whereIn('lesson_id', $lessons->pluck('id'))
            ->where('status', VideoStatus::Ready->value)->where('publication_status', VideoPublicationStatus::Published->value)
            ->get(['id', 'lesson_id', 'processed_storage_key'])
            ->filter(fn (LessonVideo $video) => $video->processed_storage_key && $this->storage->exists($video->processed_storage_key));
        $progress = VideoProgress::where('user_id', $student->id)->whereIn('lesson_video_id', $videos->pluck('id'))
            ->get(['lesson_video_id', 'completed_at'])->keyBy('lesson_video_id');
        $manual = LessonManualCompletion::where('user_id', $student->id)->whereIn('lesson_id', $lessons->pluck('id'))->pluck('lesson_id')->flip();

        return $lessons->mapWithKeys(function (Lesson $lesson) use ($videos, $progress, $manual) {
            $ids = $videos->where('lesson_id', $lesson->id)->pluck('id');
            $complete = $ids->isEmpty() ? $manual->has($lesson->id) : $ids->every(fn ($id) => $progress->get($id)?->completed_at !== null);

            return [$lesson->id => $complete];
        })->all();
    }

    public function course(User $student, Course $course): array
    {
        return $this->courses($student, collect([$course]))[$course->id];
    }
}
