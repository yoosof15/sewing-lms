<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\UpdateCourseRequest;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Course::class);
        $courses = Course::query()->with(['category:id,name', 'teachers:id,first_name,last_name'])->latest('updated_at')->paginate(15);

        return Inertia::render('Admin/Courses/Index', ['courses' => $courses]);
    }

    public function create(): Response
    {
        $this->authorize('create', Course::class);

        return Inertia::render('Admin/Courses/Form', [...$this->options(), 'course' => null]);
    }

    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $path = $request->file('cover_image')?->store('course-covers', 'public');
        try {
            $course = DB::transaction(function () use ($request, $path) {
                $data = $request->safe()->except(['teacher_ids', 'cover_image']);
                $data['cover_image'] = $path;
                $data['published_at'] = $data['status'] === CourseStatus::Published->value ? now() : null;
                $course = Course::create($data);
                $course->teachers()->sync($request->validated('teacher_ids', []));

                return $course;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        return redirect()->route('admin.courses.show', $course)->with('success', 'دوره ایجاد شد.');
    }

    public function show(Course $course): Response
    {
        $this->authorize('view', $course);

        return Inertia::render('Admin/Courses/Show', ['course' => $this->courseData($course)]);
    }

    public function edit(Course $course): Response
    {
        $this->authorize('update', $course);

        return Inertia::render('Admin/Courses/Form', [...$this->options(), 'course' => $this->courseData($course)]);
    }

    public function update(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $newPath = $request->file('cover_image')?->store('course-covers', 'public');
        $oldPath = $course->cover_image;
        try {
            DB::transaction(function () use ($request, $course, $newPath) {
                $data = $request->safe()->except(['teacher_ids', 'cover_image']);
                if ($newPath) {
                    $data['cover_image'] = $newPath;
                }
                $data['published_at'] = $data['status'] === CourseStatus::Published->value ? ($course->published_at ?? now()) : null;
                $course->update($data);
                $course->teachers()->sync($request->validated('teacher_ids', []));
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }
        if ($newPath && $oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return redirect()->route('admin.courses.show', $course)->with('success', 'دوره به‌روزرسانی شد.');
    }

    private function options(): array
    {
        return ['categories' => CourseCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']), 'teachers' => User::role('TEACHER')->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']), 'statuses' => collect(CourseStatus::cases())->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()])];
    }

    private function courseData(Course $course): Course
    {
        return $course->load(['category:id,name', 'teachers:id,first_name,last_name']);
    }
}
