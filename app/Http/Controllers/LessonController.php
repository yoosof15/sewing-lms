<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Http\Requests\SaveLessonRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\AuditService;
use App\Services\LessonPrerequisiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LessonController extends Controller
{
    public function create(Course $course): Response
    {
        $this->authorize('create', [Lesson::class, $course]);

        return Inertia::render('Lessons/Form', $this->formData($course));
    }

    public function store(SaveLessonRequest $request, Course $course, AuditService $audit, LessonPrerequisiteService $prerequisites): RedirectResponse
    {
        $data = $request->validated();
        $candidate = new Lesson(['course_id' => $course->id]);
        $ids = $prerequisites->validate($candidate, $data['prerequisite_ids'] ?? []);
        unset($data['prerequisite_ids']);
        $lesson = DB::transaction(function () use ($request, $course, $data, $ids) {
            $order = ((int) $course->lessons()->max('sort_order')) + 1;
            $data['sort_order'] = $order;
            $data['created_by'] = $request->user()->id;
            $data['published_at'] = $data['status'] === LessonStatus::Published->value ? now() : null;

            $lesson = $course->lessons()->create($data);
            $lesson->prerequisites()->sync($ids);

            return $lesson;
        });
        $audit->record('lesson.created', $lesson, ['course_id' => $course->id, 'status' => $lesson->status->value]);

        return redirect()->route($this->prefix($request).'.lessons.edit', [$course, $lesson])->with('success', 'درس ایجاد شد.');
    }

    public function edit(Course $course, Lesson $lesson): Response
    {
        $this->bound($course, $lesson);
        $this->authorize('update', $lesson);

        $lesson->setRelation('attachments', $lesson->attachments()->withTrashed()->get());
        $lesson->setRelation('videos', $lesson->videos()->withTrashed()->with('uploader:id,first_name,last_name')->orderBy('sort_order')->get());

        return Inertia::render('Lessons/Form', array_merge($this->formData($course, $lesson), ['videoConfig' => ['maxBytes' => (int) config('video.max_upload_mb') * 1024 * 1024, 'processingEnabled' => ! in_array(config('video.processor_driver'), [null, 'null'], true), 'maxAttempts' => (int) config('video.processing_max_attempts')]]));
    }

    public function update(SaveLessonRequest $request, Course $course, Lesson $lesson, AuditService $audit, LessonPrerequisiteService $prerequisites): RedirectResponse
    {
        $this->bound($course, $lesson);
        $data = $request->validated();
        $ids = $prerequisites->validate($lesson, $data['prerequisite_ids'] ?? []);
        unset($data['prerequisite_ids']);
        $data['published_at'] = $data['status'] === LessonStatus::Published->value ? ($lesson->published_at ?? now()) : null;
        $before = $lesson->status->value;
        $oldSection = $lesson->section_id;
        $oldAvailable = $lesson->available_at?->toIso8601String();
        $oldPrerequisites = $lesson->prerequisites()->pluck('lessons.id')->map(fn ($id) => (int) $id)->all();
        DB::transaction(function () use ($lesson, $data, $ids) {
            $lesson->update($data);
            $lesson->prerequisites()->sync($ids);
        });
        $audit->record('lesson.updated', $lesson, ['changed_fields' => array_keys($lesson->getChanges())]);
        if ($before !== $lesson->status->value) {
            $audit->record('lesson.status_changed', $lesson, ['from' => $before, 'to' => $lesson->status->value]);
        }
        if ($oldSection !== $lesson->section_id) {
            $audit->record('lesson.section_changed', $lesson, ['from' => $oldSection, 'to' => $lesson->section_id]);
        }
        if ($oldAvailable !== $lesson->available_at?->toIso8601String()) {
            $audit->record('lesson.availability_changed', $lesson, ['from' => $oldAvailable, 'to' => $lesson->available_at?->toIso8601String()]);
        }
        foreach (array_diff($ids, $oldPrerequisites) as $id) {
            $audit->record('lesson.prerequisite_added', $lesson, ['prerequisite_lesson_id' => $id]);
        }
        foreach (array_diff($oldPrerequisites, $ids) as $id) {
            $audit->record('lesson.prerequisite_removed', $lesson, ['prerequisite_lesson_id' => $id]);
        }

        return back()->with('success', 'درس به‌روزرسانی شد.');
    }

    public function destroy(Course $course, Lesson $lesson, AuditService $audit): RedirectResponse
    {
        $this->bound($course, $lesson);
        $this->authorize('delete', $lesson);
        DB::transaction(function () use ($course, $lesson) {
            $lesson->update(['sort_order' => 4000000000 + $lesson->id]);
            $lesson->delete();
            $this->normalize($course);
        });
        $audit->record('lesson.archived', $lesson, ['course_id' => $course->id]);

        return back()->with('success', 'درس بایگانی شد.');
    }

    public function move(Course $course, Lesson $lesson, string $direction, AuditService $audit): RedirectResponse
    {
        $this->bound($course, $lesson);
        $this->authorize('update', $lesson);
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $operator = $direction === 'up' ? '<' : '>';
        $ordering = $direction === 'up' ? 'desc' : 'asc';
        $other = $course->lessons()->where('section_id', $lesson->section_id)->where('sort_order', $operator, $lesson->sort_order)->orderBy('sort_order', $ordering)->first();
        if ($other) {
            DB::transaction(function () use ($lesson, $other) {
                $old = $lesson->sort_order;
                $otherOld = $other->sort_order;
                $lesson->update(['sort_order' => 0]);
                $other->update(['sort_order' => $old]);
                $lesson->update(['sort_order' => $otherOld]);
            });
            $audit->record('lesson.reordered', $lesson, ['direction' => $direction]);
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

    private function formData(Course $course, ?Lesson $lesson = null): array
    {
        if ($lesson) {
            $lesson->load('prerequisites:id');
        }

        return [
            'course' => $course->load('sections'), 'lesson' => $lesson, 'statuses' => $this->statuses(),
            'prerequisiteCandidates' => $course->lessons()->when($lesson, fn ($q) => $q->whereKeyNot($lesson->id))->get(['id', 'title', 'section_id']),
        ];
    }

    private function prefix($request): string
    {
        return str_starts_with($request->route()->getName(), 'admin.') ? 'admin' : 'teacher';
    }
}
