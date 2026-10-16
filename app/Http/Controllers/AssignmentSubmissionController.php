<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmission;
use App\Models\LessonAssignment;
use App\Models\SubmissionFile;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use App\Services\StudentAssignmentAccess;
use App\Services\SubmissionReviewService;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentSubmissionController extends Controller
{
    public function assignment(Request $r, LessonAssignment $a, StudentAssignmentAccess $access, SystemSettings $settings): Response
    {
        abort_unless($access->allows($r->user(), $a), 403);
        $a->load(['lesson.course', 'submissions' => fn ($q) => $q->where('student_id', $r->user()->id)->with('files')->orderBy('attempt_number')]);
        $last = $a->submissions->last();
        $canSubmit = $access->allows($r->user(), $a, true) && (! $last || ($a->allow_resubmission && in_array($last->status, [SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected], true) && (! $a->max_attempts || $last->attempt_number < $a->max_attempts)));
        $reason = $canSubmit ? null : ($a->due_at?->isPast() && ! $a->allow_late_submission ? 'مهلت ارسال گذشته است.' : ($last && ! $a->allow_resubmission ? 'ارسال مجدد برای این تکلیف مجاز نیست.' : 'در حال حاضر امکان ارسال تلاش جدید وجود ندارد.'));

        return Inertia::render('Student/Assignments/Show', ['assignment' => $a, 'canSubmit' => $canSubmit, 'blockedReason' => $reason, 'fileConfig' => ['max_files' => $settings->assignmentMaxFiles(), 'max_file_kb' => $settings->assignmentMaxFileKb(), 'allowed_mimes' => config('assignments.allowed_mimes')]]);
    }

    public function index(Request $r): Response
    {
        $u = $r->user();
        $q = AssignmentSubmission::with(['student:id,first_name,last_name', 'assignment:id,lesson_id,title', 'assignment.lesson:id,course_id,title', 'assignment.lesson.course:id,title'])->latest('submitted_at');
        if ($u->hasRole('TEACHER')) {
            $q->whereHas('assignment.lesson.course.teachers', fn ($q) => $q->whereKey($u->id));
        }$q->when($r->status, fn ($q, $v) => $q->where('status', $v))->when($r->late !== null && $r->late !== '', fn ($q) => $q->where('is_late', (bool) $r->late));

        return Inertia::render('Submissions/Index', ['submissions' => $q->paginate(15)->withQueryString(), 'statuses' => $this->statuses(), 'filters' => $r->only('status', 'late')]);
    }

    public function store(Request $r, LessonAssignment $a, StudentAssignmentAccess $access, NotificationEvents $events, SystemSettings $settings): RedirectResponse
    {
        abort_unless($access->allows($r->user(), $a, true), 403);
        $d = $r->validate(['text_content' => ['nullable', 'string', 'max:20000'], 'files' => ['nullable', 'array', 'max:'.$settings->assignmentMaxFiles()], 'files.*' => ['file', 'mimes:'.implode(',', config('assignments.allowed_mimes')), 'max:'.$settings->assignmentMaxFileKb()]]);
        abort_if(blank($d['text_content'] ?? null) && empty($r->file('files')), 422, 'متن یا فایل لازم است.');
        $last = $a->submissions()->where('student_id', $r->user()->id)->latest('attempt_number')->first();
        abort_if($last && ! $a->allow_resubmission, 409, 'ارسال مجدد مجاز نیست.');
        abort_if($last && ! in_array($last->status, [SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected], true), 409, 'نتیجه تلاش قبلی هنوز اجازه ارسال مجدد نمی‌دهد.');
        $attempt = ($last?->attempt_number ?? 0) + 1;
        abort_if($a->max_attempts && $attempt > $a->max_attempts, 409, 'حداکثر تعداد تلاش تمام شده است.');
        $s = DB::transaction(function () use ($r, $a, $d, $attempt) {
            $s = $a->submissions()->create(['student_id' => $r->user()->id, 'attempt_number' => $attempt, 'text_content' => $d['text_content'] ?? null, 'status' => SubmissionStatus::Submitted, 'is_late' => $a->due_at?->isPast() ?? false, 'submitted_at' => now()]);
            foreach ($r->file('files', []) as $f) {
                $p = $f->store('assignment-submissions', 'local');
                $s->files()->create(['uploaded_by' => $r->user()->id, 'original_name' => $f->getClientOriginalName(), 'storage_path' => $p, 'mime_type' => $f->getMimeType(), 'file_size' => $f->getSize()]);
            }

            return $s;
        });
        $events->submissionReceived($s->load('student', 'assignment.lesson.course.teachers'));

        return redirect()->route('submissions.show', $s);
    }

    public function show(Request $r, AssignmentSubmission $s): Response
    {
        $this->authorize('view', $s);
        $s->load(['student:id,first_name,last_name', 'assignment.lesson.course', 'files', 'reviewer:id,first_name,last_name']);
        $history = $s->assignment->submissions()->where('student_id', $s->student_id)->with('files')->orderBy('attempt_number')->get();

        return Inertia::render('Submissions/Show', ['submission' => $s, 'history' => $history, 'canReview' => $r->user()->can('review', $s), 'statuses' => $this->statuses()]);
    }

    public function review(Request $r, AssignmentSubmission $s, AuditService $audit, NotificationEvents $events, SubmissionReviewService $reviews): RedirectResponse
    {
        $this->authorize('review', $s);
        $d = $r->validate(['status' => ['required', Rule::enum(SubmissionStatus::class), Rule::notIn([SubmissionStatus::Submitted->value])], 'reviewer_feedback' => ['nullable', 'string', 'max:10000']]);
        $before = $s->status;
        $reviews->review($s, $r->user(), SubmissionStatus::from($d['status']), $d['reviewer_feedback'] ?? null);
        $audit->record('submission.reviewed', $s, ['from' => $before->value, 'to' => $s->status->value]);
        if (in_array($s->status, [SubmissionStatus::Approved, SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected], true)) {
            $events->submissionReviewed($s->load('student', 'assignment'));
        }

        return back()->with('success', 'نتیجه بررسی تکلیف ذخیره شد.');
    }

    public function download(SubmissionFile $f): StreamedResponse
    {
        $this->authorize('view', $f);
        abort_unless(str_starts_with($f->storage_path, 'assignment-submissions/') && ! str_contains($f->storage_path, '..') && Storage::disk('local')->exists($f->storage_path), 404);

        return Storage::disk('local')->download($f->storage_path, $f->original_name, ['Content-Type' => $f->mime_type]);
    }

    private function statuses(): array
    {
        return collect(SubmissionStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all();
    }
}
