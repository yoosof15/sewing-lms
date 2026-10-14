<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSection;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseSectionController extends Controller
{
    public function store(Request $request, Course $course, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', [CourseSection::class, $course]);
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:2000']]);
        $data['sort_order'] = ((int) $course->sections()->max('sort_order')) + 1;
        $section = $course->sections()->create($data);
        $audit->record('course_section.created', $section, ['course_id' => $course->id]);

        return back()->with('success', 'فصل ایجاد شد.');
    }

    public function update(Request $request, Course $course, CourseSection $section, AuditService $audit): RedirectResponse
    {
        $this->bound($course, $section);
        $this->authorize('update', $section);
        $section->update($request->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:2000']]));
        $audit->record('course_section.updated', $section, ['changed_fields' => array_keys($section->getChanges())]);

        return back()->with('success', 'فصل به‌روزرسانی شد.');
    }

    public function move(Course $course, CourseSection $section, string $direction, AuditService $audit): RedirectResponse
    {
        $this->bound($course, $section);
        $this->authorize('update', $section);
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';
        $other = $course->sections()->where('sort_order', $operator, $section->sort_order)->orderBy('sort_order', $order)->first();
        if ($other) {
            DB::transaction(function () use ($section, $other) {
                $old = $section->sort_order;
                $otherOld = $other->sort_order;
                $section->update(['sort_order' => 0]);
                $other->update(['sort_order' => $old]);
                $section->update(['sort_order' => $otherOld]);
            });
        }
        if ($other) {
            $audit->record('course_section.reordered', $section, ['direction' => $direction]);
        }

        return back();
    }

    public function destroy(Course $course, CourseSection $section, AuditService $audit): RedirectResponse
    {
        $this->bound($course, $section);
        $this->authorize('delete', $section);
        abort_if($section->lessons()->withTrashed()->exists(), 422, 'برای بایگانی فصل، ابتدا درس‌های آن را منتقل یا بدون فصل کنید.');
        $section->update(['sort_order' => 4000000000 + $section->id]);
        $section->delete();
        $this->normalize($course);
        $audit->record('course_section.archived', $section, ['course_id' => $course->id]);

        return back()->with('success', 'فصل بایگانی شد.');
    }

    public function restore(Course $course, int $section, AuditService $audit): RedirectResponse
    {
        $model = $course->sections()->withTrashed()->findOrFail($section);
        $this->authorize('restore', $model);
        $model->restore();
        $model->update(['sort_order' => ((int) $course->sections()->max('sort_order')) + 1]);
        $audit->record('course_section.restored', $model, ['course_id' => $course->id]);

        return back()->with('success', 'فصل بازیابی شد.');
    }

    private function bound(Course $course, CourseSection $section): void
    {
        abort_unless($section->course_id === $course->id, 404);
    }

    private function normalize(Course $course): void
    {
        $items = $course->sections()->orderBy('sort_order')->get();
        $course->sections()->update(['sort_order' => DB::raw('sort_order + 1000000')]);
        $items->each(fn ($item, $i) => $item->update(['sort_order' => $i + 1]));
    }
}
