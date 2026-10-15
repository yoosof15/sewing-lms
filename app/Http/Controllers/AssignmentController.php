<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    public function store(Request $r, Course $course, Lesson $lesson, AuditService $audit, NotificationEvents $events): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);
        $this->authorize('create', [LessonAssignment::class, $lesson]);
        $d = $this->validated($r);
        $a = $lesson->assignments()->create([...$d, 'created_by' => $r->user()->id]);
        $audit->record('assignment.created', $a, ['lesson_id' => $lesson->id]);
        if ($a->status === AssignmentStatus::Published && ! $a->available_at?->isFuture()) {
            $events->assignmentPublished($a->load('lesson.course'));
        }

        return back()->with('success', 'تکلیف ایجاد شد.');
    }

    public function update(Request $r, LessonAssignment $a, AuditService $audit, NotificationEvents $events): RedirectResponse
    {
        $this->authorize('update', $a);
        $before = $a->status;
        $a->update($this->validated($r));
        $audit->record('assignment.updated', $a, ['changed_fields' => array_keys($a->getChanges())]);
        if ($before !== AssignmentStatus::Published && $a->status === AssignmentStatus::Published && ! $a->available_at?->isFuture()) {
            $events->assignmentPublished($a->load('lesson.course'));
        }

        return back();
    }

    public function destroy(LessonAssignment $a, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $a);
        $a->delete();
        $audit->record('assignment.archived', $a);

        return back();
    }

    public function restore(int $assignment, AuditService $audit): RedirectResponse
    {
        $a = LessonAssignment::withTrashed()->findOrFail($assignment);
        $this->authorize('restore', $a);
        abort_if($a->lesson()->withTrashed()->first()?->trashed(), 409);
        $a->restore();
        $audit->record('assignment.restored', $a);

        return back();
    }

    private function validated(Request $r): array
    {
        return $r->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'], 'status' => ['required', Rule::enum(AssignmentStatus::class)], 'available_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date', 'after_or_equal:available_at'], 'allow_late_submission' => ['required', 'boolean'], 'allow_resubmission' => ['required', 'boolean'], 'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100']]);
    }
}
