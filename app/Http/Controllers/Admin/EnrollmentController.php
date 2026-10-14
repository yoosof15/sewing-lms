<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEnrollmentRequest;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    public function index(Request $request, Course $course): Response
    {
        $this->authorize('update', $course);
        $search = $request->string('search')->trim()->toString();
        $enrollments = $course->enrollments()->with('student:id,first_name,last_name,mobile')->latest()->paginate(15)->withQueryString();
        $students = User::role('STUDENT')->where('is_active', true)->whereDoesntHave('enrollments', fn ($q) => $q->where('course_id', $course->id))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%")->orWhere('mobile', 'like', "%$search%")))
            ->limit(30)->get(['id', 'first_name', 'last_name', 'mobile']);

        return Inertia::render('Admin/Enrollments/Index', compact('course', 'enrollments', 'students', 'search'));
    }

    public function store(StoreEnrollmentRequest $request, Course $course, AuditService $audit): RedirectResponse
    {
        $created = DB::transaction(function () use ($request, $course) {
            $items = collect();
            foreach ($request->validated('student_ids') as $id) {
                $items->push($course->enrollments()->create(['student_id' => $id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'starts_at' => $request->validated('starts_at'), 'ends_at' => $request->validated('ends_at'), 'created_by' => $request->user()->id]));
            }

            return $items;
        });
        $created->each(fn ($enrollment) => $audit->record('enrollment.created', $enrollment, ['course_id' => $course->id, 'student_id' => $enrollment->student_id]));

        return back()->with('success', 'ثبت‌نام انجام شد.');
    }

    public function update(Request $request, Course $course, CourseEnrollment $enrollment, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $course);
        abort_unless($enrollment->course_id === $course->id, 404);
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,INACTIVE'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at']]);
        $beforeStatus = $enrollment->status;
        $enrollment->update($data);
        $action = $beforeStatus !== $enrollment->status ? ($enrollment->status === EnrollmentStatus::Active ? 'enrollment.activated' : 'enrollment.deactivated') : 'enrollment.dates_changed';
        $audit->record($action, $enrollment, ['course_id' => $course->id, 'changed_fields' => array_keys($enrollment->getChanges())]);

        return back()->with('success', 'دسترسی هنرجو به‌روزرسانی شد.');
    }
}
