<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CourseNavigationMode;
use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\UpdateCourseRequest;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use App\Services\SystemSettings;
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

    public function store(StoreCourseRequest $request, AuditService $audit, NotificationEvents $notifications): RedirectResponse
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
        $audit->record('course.created', $course, ['status' => $course->status->value]);
        $course->teachers()->get()->each(fn (User $teacher) => $notifications->teacher($teacher, $course, true));

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

    public function update(UpdateCourseRequest $request, Course $course, AuditService $audit, NotificationEvents $notifications): RedirectResponse
    {
        $beforeStatus = $course->status->value;
        $beforeNavigation = $course->navigation_mode->value;
        $beforeTeachers = $course->teachers()->pluck('users.id')->all();
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
        $course->refresh();
        $audit->record('course.updated', $course, ['changed_fields' => array_keys($course->getChanges())]);
        if ($beforeStatus !== $course->status->value) {
            $audit->record('course.status_changed', $course, ['from' => $beforeStatus, 'to' => $course->status->value]);
        }
        if ($beforeNavigation !== $course->navigation_mode->value) {
            $audit->record('course.navigation_mode_changed', $course, ['from' => $beforeNavigation, 'to' => $course->navigation_mode->value]);
        }
        $afterTeachers = $course->teachers()->pluck('users.id')->all();
        foreach (array_diff($afterTeachers, $beforeTeachers) as $id) {
            $audit->record('course.teacher_assigned', $course, ['teacher_id' => $id]);
            if ($teacher = User::find($id)) {
                $notifications->teacher($teacher, $course, true);
            }
        }
        foreach (array_diff($beforeTeachers, $afterTeachers) as $id) {
            $audit->record('course.teacher_removed', $course, ['teacher_id' => $id]);
            if ($teacher = User::find($id)) {
                $notifications->teacher($teacher, $course, false);
            }
        }

        return redirect()->route('admin.courses.show', $course)->with('success', 'دوره به‌روزرسانی شد.');
    }

    private function options(): array
    {
        $settings = app(SystemSettings::class);

        return ['categories' => CourseCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']), 'teachers' => User::role('TEACHER')->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']), 'statuses' => collect(CourseStatus::cases())->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()]), 'navigationModes' => collect(CourseNavigationMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()]), 'courseDefaults' => ['navigation_mode' => $settings->string('default_navigation_mode'), 'completion_threshold_percent' => $settings->integer('default_completion_threshold_percent')]];
    }

    private function courseData(Course $course): Course
    {
        $course->load(['category:id,name', 'teachers:id,first_name,last_name']);
        $course->setRelation('sections', $course->sections()->withTrashed()->orderBy('sort_order')->get());
        $course->setRelation('lessons', $course->lessons()->withTrashed()->with(['creator:id,first_name,last_name', 'section' => fn ($q) => $q->withTrashed()])->orderBy('sort_order')->get());

        return $course;
    }
}
