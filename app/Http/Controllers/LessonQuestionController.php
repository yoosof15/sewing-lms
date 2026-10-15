<?php

namespace App\Http\Controllers;

use App\Enums\LessonQuestionStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\LessonQuestionAttachment;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use App\Services\StudentLessonAccess;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonQuestionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = LessonQuestion::query()->with(['student:id,first_name,last_name', 'lesson:id,course_id,title', 'lesson.course:id,title'])->withCount(['replies as staff_replies_count' => fn ($q) => $q->where('is_staff', true)])->orderByDesc('last_activity_at');
        if ($user->hasRole('TEACHER')) {
            $query->whereHas('lesson.course.teachers', fn ($q) => $q->whereKey($user->id));
        }$query->when($request->status, fn ($q, $v) => $q->where('status', $v))->when($request->course_id, fn ($q, $v) => $q->whereHas('lesson', fn ($q) => $q->where('course_id', $v)));
        $courses = $user->hasRole('ADMIN') ? Course::orderBy('title')->get(['id', 'title']) : $user->taughtCourses()->orderBy('title')->get(['courses.id', 'title']);

        return Inertia::render('Questions/Index', ['questions' => $query->paginate(15)->withQueryString(), 'courses' => $courses, 'filters' => $request->only('status', 'course_id'), 'statuses' => $this->statuses()]);
    }

    public function store(Request $request, Course $course, Lesson $lesson, StudentLessonAccess $access, NotificationEvents $events, SystemSettings $settings): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);
        abort_unless($request->user()->hasRole('STUDENT') && $access->allows($request->user(), $lesson), 403);
        abort_unless($settings->boolean('lesson_qa_enabled'), 403);
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:180'], 'body' => ['required', 'string', 'min:5', 'max:5000'], 'images' => ['nullable', 'array', 'max:'.$settings->qaMaxImages()], 'images.*' => ['file', 'image', 'mimes:'.implode(',', config('q_and_a.allowed_mimes')), 'max:'.$settings->qaMaxImageKb()]]);
        $question = DB::transaction(function () use ($request, $lesson, $data) {
            $q = $lesson->questions()->create(['student_id' => $request->user()->id, 'subject' => $data['subject'] ?? null, 'body' => $data['body'], 'status' => LessonQuestionStatus::Open, 'last_activity_at' => now()]);
            $this->storeFiles($request, $q, null);

            return $q;
        });
        $events->questionCreated($question->load('student', 'lesson.course.teachers'));

        return redirect()->route('questions.show', $question)->with('success', 'پرسش ثبت شد.');
    }

    public function show(Request $request, LessonQuestion $question): Response
    {
        $this->authorize('view', $question);
        $question->load(['student:id,first_name,last_name', 'lesson.course:id,title', 'replies.user:id,first_name,last_name', 'attachments', 'replies.attachments']);

        return Inertia::render('Questions/Show', ['question' => $question, 'canManage' => $request->user()->can('manage', $question), 'statuses' => $this->statuses()]);
    }

    public function reply(Request $request, LessonQuestion $question, NotificationEvents $events, SystemSettings $settings): RedirectResponse
    {
        $this->authorize('reply', $question);
        abort_if($request->user()->hasRole('STUDENT') && ! $settings->boolean('lesson_qa_enabled'), 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000'], 'images' => ['nullable', 'array', 'max:'.$settings->qaMaxImages()], 'images.*' => ['file', 'image', 'mimes:'.implode(',', config('q_and_a.allowed_mimes')), 'max:'.$settings->qaMaxImageKb()]]);
        $staff = $request->user()->hasAnyRole(['ADMIN', 'TEACHER']);
        $reply = DB::transaction(function () use ($request, $question, $data, $staff) {
            $r = $question->replies()->create(['user_id' => $request->user()->id, 'body' => $data['body'], 'is_staff' => $staff]);
            $updates = ['last_activity_at' => now()];
            if ($staff && $question->status === LessonQuestionStatus::Open) {
                $updates['status'] = LessonQuestionStatus::Answered;
            }if (! $staff && $question->status === LessonQuestionStatus::Resolved) {
                $updates['status'] = LessonQuestionStatus::Answered;
                $updates['resolved_at'] = null;
                $updates['resolved_by'] = null;
            }$question->update($updates);
            $this->storeFiles($request, $question, $r->id);

            return $r;
        });
        $events->questionReply($question->load('student', 'lesson.course.teachers'), $reply, $staff);

        return back()->with('success', 'پاسخ ثبت شد.');
    }

    public function status(Request $request, LessonQuestion $question, AuditService $audit): RedirectResponse
    {
        $status = LessonQuestionStatus::from($request->validate(['status' => ['required', Rule::enum(LessonQuestionStatus::class)]])['status']);
        $student = $request->user()->hasRole('STUDENT');
        if ($student) {
            $this->authorize('view', $question);
            abort_unless($status === LessonQuestionStatus::Resolved, 403);
        } else {
            $this->authorize('manage', $question);
        }$before = $question->status;
        $question->update(['status' => $status, 'resolved_at' => $status === LessonQuestionStatus::Resolved ? now() : null, 'resolved_by' => $status === LessonQuestionStatus::Resolved ? $request->user()->id : null]);
        if (in_array($status, [LessonQuestionStatus::Closed, LessonQuestionStatus::Resolved, LessonQuestionStatus::Open, LessonQuestionStatus::Answered], true)) {
            $audit->record('lesson_question.status_changed', $question, ['from' => $before->value, 'to' => $status->value]);
        }

        return back();
    }

    public function destroy(LessonQuestion $question, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $question);
        $question->delete();
        $audit->record('lesson_question.archived', $question);

        return redirect()->route('admin.questions.index');
    }

    public function download(LessonQuestionAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);
        abort_unless(str_starts_with($attachment->storage_path, 'question-attachments/') && ! str_contains($attachment->storage_path, '..'), 404);

        return Storage::disk('local')->download($attachment->storage_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }

    private function storeFiles(Request $request, LessonQuestion $q, ?int $reply): void
    {
        foreach ($request->file('images', []) as $file) {
            $path = $file->store('question-attachments', 'local');
            $q->attachments()->create(['reply_id' => $reply, 'uploaded_by' => $request->user()->id, 'original_name' => $file->getClientOriginalName(), 'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize()]);
        }
    }

    private function statuses(): array
    {
        return collect(LessonQuestionStatus::cases())
            ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])
            ->all();
    }
}
