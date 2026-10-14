<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ArchiveController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Archive/Index', [
            'courses' => Course::onlyTrashed()->latest('deleted_at')->paginate(15, ['*'], 'courses'),
            'categories' => CourseCategory::onlyTrashed()->latest('deleted_at')->paginate(15, ['*'], 'categories'),
        ]);
    }

    public function course(Course $course, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $course);
        $course->delete();
        $audit->record('course.archived', $course);

        return redirect()->route('admin.archive.index')->with('success', 'دوره بایگانی شد.');
    }

    public function restoreCourse(int $course, AuditService $audit): RedirectResponse
    {
        $model = Course::onlyTrashed()->findOrFail($course);
        $model->restore();
        $audit->record('course.restored', $model);

        return back()->with('success', 'دوره بازیابی شد.');
    }

    public function category(CourseCategory $category, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $category);
        abort_if($category->courses()->exists(), 409, 'دسته‌بندی دارای دوره است و قابل بایگانی نیست.');
        $category->delete();
        $audit->record('category.archived', $category);

        return back()->with('success', 'دسته‌بندی بایگانی شد.');
    }

    public function restoreCategory(int $category, AuditService $audit): RedirectResponse
    {
        $model = CourseCategory::onlyTrashed()->findOrFail($category);
        $model->restore();
        $audit->record('category.restored', $model);

        return back()->with('success', 'دسته‌بندی بازیابی شد.');
    }

    public function restoreLesson(Course $course, int $lesson, AuditService $audit): RedirectResponse
    {
        abort_if($course->trashed(), 409, 'ابتدا دوره را بازیابی کنید.');
        $model = Lesson::onlyTrashed()->where('course_id', $course->id)->findOrFail($lesson);
        $model->restore();
        $audit->record('lesson.restored', $model);

        return back()->with('success', 'درس بازیابی شد.');
    }

    public function restoreVideo(Lesson $lesson, int $video, AuditService $audit): RedirectResponse
    {
        abort_if($lesson->trashed() || ! $lesson->course, 409, 'ابتدا والد بایگانی‌شده را بازیابی کنید.');
        $model = LessonVideo::onlyTrashed()->where('lesson_id', $lesson->id)->findOrFail($video);
        $model->restore();
        $audit->record('video.restored', $model);

        return back()->with('success', 'ویدئو بازیابی شد.');
    }
}
