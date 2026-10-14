<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Http\Requests\SaveLessonRequest;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LessonController extends Controller
{
    public function create(Course $course): Response
    {
        $this->authorize('create', [Lesson::class, $course]);

        return Inertia::render('Lessons/Form', ['course' => $course, 'lesson' => null, 'statuses' => $this->statuses()]);
    }

    public function store(SaveLessonRequest $request, Course $course): RedirectResponse
    {
        $lesson = DB::transaction(function () use ($request, $course) {
            $order = ((int) $course->lessons()->max('sort_order')) + 1;
            $data = $request->validated();
            $data['sort_order'] = $order;
            $data['created_by'] = $request->user()->id;
            $data['published_at'] = $data['status'] === LessonStatus::Published->value ? now() : null;

            return $course->lessons()->create($data);
        });

        return redirect()->route($this->prefix($request).'.lessons.edit', [$course, $lesson])->with('success', 'درس ایجاد شد.');
    }

    public function edit(Course $course, Lesson $lesson): Response
    {
        $this->bound($course, $lesson);
        $this->authorize('update', $lesson);

        return Inertia::render('Lessons/Form', ['course' => $course, 'lesson' => $lesson->load(['attachments', 'videos.uploader:id,first_name,last_name']), 'statuses' => $this->statuses(), 'videoConfig' => ['maxBytes' => (int) config('video.max_upload_mb') * 1024 * 1024]]);
    }

    public function update(SaveLessonRequest $request, Course $course, Lesson $lesson): RedirectResponse
    {
        $this->bound($course, $lesson);
        $data = $request->validated();
        $data['published_at'] = $data['status'] === LessonStatus::Published->value ? ($lesson->published_at ?? now()) : null;
        $lesson->update($data);

        return back()->with('success', 'درس به‌روزرسانی شد.');
    }

    public function destroy(Course $course, Lesson $lesson): RedirectResponse
    {
        $this->bound($course, $lesson);
        $this->authorize('delete', $lesson);
        DB::transaction(function () use ($course, $lesson) {
            $lesson->update(['sort_order' => 4000000000 + $lesson->id]);
            $lesson->delete();
            $this->normalize($course);
        });

        return back()->with('success', 'درس بایگانی شد.');
    }

    public function move(Course $course, Lesson $lesson, string $direction): RedirectResponse
    {
        $this->bound($course, $lesson);
        $this->authorize('update', $lesson);
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $operator = $direction === 'up' ? '<' : '>';
        $ordering = $direction === 'up' ? 'desc' : 'asc';
        $other = $course->lessons()->where('sort_order', $operator, $lesson->sort_order)->orderBy('sort_order', $ordering)->first();
        if ($other) {
            DB::transaction(function () use ($lesson, $other) {
                $old = $lesson->sort_order;
                $otherOld = $other->sort_order;
                $lesson->update(['sort_order' => 0]);
                $other->update(['sort_order' => $old]);
                $lesson->update(['sort_order' => $otherOld]);
            });
        }

        return back();
    }

    private function normalize(Course $course): void
    {
        $lessons = $course->lessons()->orderBy('sort_order')->get();
        $course->lessons()->update(['sort_order' => DB::raw('sort_order + 1000000')]);
        $lessons->each(fn ($lesson, $i) => $lesson->update(['sort_order' => $i + 1]));
    }

    private function bound(Course $course, Lesson $lesson): void
    {
        abort_unless($lesson->course_id === $course->id, 404);
    }

    private function statuses(): array
    {
        return collect(LessonStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all();
    }

    private function prefix($request): string
    {
        return str_starts_with($request->route()->getName(), 'admin.') ? 'admin' : 'teacher';
    }
}
