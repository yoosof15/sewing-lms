<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use App\Services\LearningReportAccess;
use App\Services\LearningReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LearningReportController extends Controller
{
    public function dashboard(Request $request, LearningReportService $reports): Response
    {
        return Inertia::render('Dashboard', ['title' => $request->user()->hasRole('ADMIN') ? 'داشبورد مدیر' : 'داشبورد مدرس', 'metrics' => $reports->dashboard($request->user())]);
    }

    public function index(Request $request, LearningReportService $reports): Response
    {
        $filters = $request->validate(['course' => ['nullable', 'string', 'max:100'], 'teacher_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'], 'student' => ['nullable', 'string', 'max:100'], 'enrollment_status' => ['nullable', 'in:ACTIVE,INACTIVE']]);
        $viewer = $request->user();

        return Inertia::render('Reports/Index', [
            'courses' => $reports->courses($viewer, $filters),
            'role' => $viewer->getRoleNames()->first(),
            'filters' => $filters,
            'categories' => $viewer->hasRole('ADMIN') ? CourseCategory::query()->orderBy('name')->get(['id', 'name']) : [],
            'teachers' => $viewer->hasRole('ADMIN') ? User::role('TEACHER')->where('is_active', true)->orderBy('last_name')->get(['id', 'first_name', 'last_name']) : [],
        ]);
    }

    public function course(Request $request, Course $course, LearningReportAccess $access, LearningReportService $reports): Response
    {
        abort_unless($access->course($request->user(), $course), 403);
        $filters = $this->filters($request);

        return Inertia::render('Reports/Course', ['course' => $course->load('category:id,name'), ...$reports->course($course, $filters), 'filters' => $filters, 'enrollmentStatuses' => collect(EnrollmentStatus::cases())->map(fn ($status) => $status->value)]);
    }

    public function student(Request $request, User $student, LearningReportAccess $access, LearningReportService $reports): Response
    {
        abort_unless($student->hasRole('STUDENT') && $access->student($request->user(), $student), 403);
        $course = $request->integer('course') ? Course::findOrFail($request->integer('course')) : null;
        if ($course) {
            abort_unless($access->course($request->user(), $course) && $student->enrollments()->where('course_id', $course->id)->exists(), 403);
        }

        return Inertia::render('Reports/Student', $reports->student($request->user(), $student, $course));
    }

    public function export(Request $request, Course $course, LearningReportAccess $access, LearningReportService $reports): StreamedResponse
    {
        abort_unless($access->course($request->user(), $course), 403);
        $rows = $reports->exportRows($course, $this->filters($request));

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['نام', 'نام خانوادگی', 'موبایل ماسک‌شده', 'وضعیت ثبت‌نام', 'درصد پیشرفت', 'درس تکمیل‌شده', 'درس در دسترس', 'وضعیت پیشرفت', 'تکلیف ارسال‌شده', 'تکلیف تأییدشده', 'تکلیف ارسال‌نشده', 'آخرین فعالیت']);
            foreach ($rows as $row) {
                fputcsv($output, [$row['student']['first_name'], $row['student']['last_name'], $row['student']['mobile'], $row['status'], $row['progress']['percent'], $row['progress']['completed_lessons'], $row['progress']['total_lessons'], $row['progress_state'], $row['assignments']['submitted'], $row['assignments']['approved'], $row['assignments']['not_submitted'], $row['last_activity_at']?->toIso8601String()]);
            }
            fclose($output);
        }, "course-{$course->id}-progress.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'student' => ['nullable', 'string', 'max:100'],
            'enrollment_status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'progress_state' => ['nullable', 'string', 'in:not_started,in_progress,completed'],
            'min_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'inactive_days' => ['nullable', 'integer', 'in:7,14,30'],
        ]);
    }
}
