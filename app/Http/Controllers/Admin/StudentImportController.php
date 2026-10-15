<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StudentImportStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\StudentImportBatch;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use App\Services\StudentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentImportController extends Controller
{
    public function index(): Response
    {
        $batches = StudentImportBatch::with('creator:id,first_name,last_name')->latest()->paginate(15);

        return Inertia::render('Admin/Imports/Index', ['batches' => $batches, 'courses' => Course::orderBy('title')->get(['id', 'title']), 'limits' => config('student_imports')]);
    }

    public function store(Request $request, StudentImportService $service): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('student_imports.max_file_kb')], 'course_mode' => ['required', 'in:selected,slug'], 'course_id' => ['nullable', 'required_if:course_mode,selected', 'exists:courses,id'], 'existing_policy' => ['required', 'in:SKIP_EXISTING,ENROLL_EXISTING_ONLY']]);
        try {
            $batch = $service->preview($data['file'], $request->user(), collect($data)->except('file')->all());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return redirect()->route('admin.student-imports.show', $batch);
    }

    public function show(StudentImportBatch $batch): Response
    {
        return Inertia::render('Admin/Imports/Show', ['batch' => $batch->load('creator:id,first_name,last_name'), 'rows' => $batch->rows()->orderBy('row_number')->paginate(50)]);
    }

    public function execute(StudentImportBatch $batch, Request $request, StudentImportService $service, AuditService $audit, NotificationEvents $notifications): RedirectResponse
    {
        abort_unless($batch->status === StudentImportStatus::Validated, 409);
        $service->execute($batch, $request->user(), $audit, $notifications);

        return back()->with('success', 'ورود گروهی اجرا شد.');
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['first_name', 'last_name', 'mobile', 'email', 'course_slug', 'enrollment_starts_at', 'enrollment_ends_at']);
            fputcsv($out, ['علی', 'محمدی', '09121234567', 'ali@example.test', '', '2026-01-01', '2026-12-31']);
            fclose($out);
        }, 'student-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function errors(StudentImportBatch $batch): StreamedResponse
    {
        return response()->streamDownload(function () use ($batch) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['row_number', 'mobile', 'name', 'error_code', 'error_message']);
            $batch->rows()->whereNotNull('error_codes')->orderBy('row_number')->each(function ($row) use ($out) {
                fputcsv($out, [$row->row_number, $row->data['mobile'] ?? '', trim(($row->data['first_name'] ?? '').' '.($row->data['last_name'] ?? '')), implode('|', $row->error_codes ?? []), $row->error_message]);
            });
            fclose($out);
        }, "student-import-errors-{$batch->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
