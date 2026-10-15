<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BulkUserController extends Controller
{
    public function status(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['user_ids' => ['required', 'array', 'max:100'], 'user_ids.*' => ['integer', 'distinct', 'exists:users,id'], 'active' => ['required', 'boolean']]);
        $users = User::whereIn('id', $data['user_ids'])->get();
        if (! $data['active'] && $users->contains(fn ($user) => $user->hasRole('ADMIN'))) {
            abort(422, 'غیرفعال‌سازی گروهی مدیر مجاز نیست.');
        }
        User::whereIn('id', $users->pluck('id'))->update(['is_active' => $data['active']]);
        $audit->record($data['active'] ? 'users.bulk_activated' : 'users.bulk_deactivated', null, ['count' => $users->count(), 'user_ids' => $users->pluck('id')->all()]);

        return back()->with('success', 'عملیات گروهی انجام شد.');
    }

    public function enroll(Request $request, AuditService $audit, NotificationEvents $notifications): RedirectResponse
    {
        $data = $request->validate(['user_ids' => ['required', 'array', 'max:100'], 'user_ids.*' => ['integer', 'distinct', 'exists:users,id'], 'course_id' => ['required', 'exists:courses,id'], 'starts_at' => ['nullable', 'date_format:Y-m-d'], 'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at']]);
        $students = User::role('STUDENT')->whereIn('id', $data['user_ids'])->get();
        abort_unless($students->count() === count($data['user_ids']), 422, 'فقط هنرجویان قابل ثبت‌نام گروهی هستند.');
        $course = Course::findOrFail($data['course_id']);
        $created = 0;
        foreach ($students as $student) {
            if (CourseEnrollment::whereBelongsTo($course)->where('student_id', $student->id)->exists()) {
                continue;
            }
            $enrollment = CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'created_by' => $request->user()->id]);
            $notifications->enrollment($student, $course, true, $enrollment->id);
            $created++;
        }
        $audit->record('users.bulk_enrolled', null, ['course_id' => $course->id, 'requested' => count($data['user_ids']), 'created' => $created]);

        return back()->with('success', 'ثبت‌نام گروهی انجام شد.');
    }
}
