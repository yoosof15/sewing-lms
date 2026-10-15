<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonQuestionStatus;
use App\Enums\SubmissionStatus;
use App\Enums\VideoStatus;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LessonAssignment;
use App\Models\LessonManualCompletion;
use App\Models\LessonQuestion;
use App\Models\LessonVideo;
use App\Models\User;
use App\Models\VideoProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LearningReportService
{
    public function __construct(
        private readonly LearningProgressService $progress,
        private readonly CurriculumService $curriculum,
        private readonly StudentLessonAccess $lessonAccess,
    ) {}

    public function courses(User $viewer, array $filters = []): Collection
    {
        return Course::query()->with('category:id,name')->withCount([
            'enrollments',
            'enrollments as active_enrollments_count' => fn ($q) => $this->currentEnrollment($q),
        ])->when($viewer->hasRole('TEACHER'), fn ($q) => $q->whereHas('teachers', fn ($q) => $q->whereKey($viewer->id)))
            ->when($filters['course'] ?? null, fn ($q, $term) => $q->where('title', 'like', "%$term%"))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['teacher_id'] ?? null, fn ($q, $id) => $q->whereHas('teachers', fn ($q) => $q->whereKey($id)))
            ->when($filters['student'] ?? null, fn ($q, $term) => $q->whereHas('enrollments.student', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%"))))
            ->when($filters['enrollment_status'] ?? null, fn ($q, $status) => $q->whereHas('enrollments', fn ($q) => $q->where('status', $status)))
            ->orderBy('title')->get();
    }

    public function dashboard(User $viewer): array
    {
        $courseIds = $viewer->hasRole('ADMIN')
            ? Course::query()->pluck('id')
            : $viewer->taughtCourses()->pluck('courses.id');
        $activeEnrollments = CourseEnrollment::query()->whereIn('course_id', $courseIds);
        $this->currentEnrollment($activeEnrollments);

        return [
            'active_students' => (clone $activeEnrollments)->distinct('student_id')->count('student_id'),
            'active_enrollments' => (clone $activeEnrollments)->count(),
            'published_courses' => Course::query()->whereIn('id', $courseIds)->where('status', CourseStatus::Published->value)->count(),
            'pending_submissions' => AssignmentSubmission::query()->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
                ->whereHas('assignment.lesson', fn ($q) => $q->whereIn('course_id', $courseIds))->count(),
            'open_questions' => LessonQuestion::query()->where('status', LessonQuestionStatus::Open->value)
                ->whereHas('lesson', fn ($q) => $q->whereIn('course_id', $courseIds))->count(),
            'failed_videos' => LessonVideo::query()->where('status', VideoStatus::Failed->value)
                ->whereHas('lesson', fn ($q) => $q->whereIn('course_id', $courseIds))->count(),
        ];
    }

    public function course(Course $course, array $filters, int $perPage = 15): array
    {
        $query = CourseEnrollment::query()->where('course_id', $course->id)->with('student:id,first_name,last_name,mobile,is_active');
        $this->applyEnrollmentFilters($query, $filters);
        $derivedFilters = collect(['progress_state', 'min_progress', 'max_progress', 'inactive_days'])->contains(fn ($key) => isset($filters[$key]) && $filters[$key] !== '');
        if ($derivedFilters) {
            $rows = collect();
            $query->orderBy('id')->chunk(100, function ($enrollments) use ($course, $filters, $rows) {
                $rows->push(...$this->studentRows($course, $enrollments, $filters));
            });
            $page = LengthAwarePaginator::resolveCurrentPage();
            $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
        } else {
            $paginator = $query->orderBy('id')->paginate($perPage)->withQueryString();
            $paginator->setCollection($this->studentRows($course, $paginator->getCollection(), $filters));
        }

        $allCurrent = CourseEnrollment::query()->where('course_id', $course->id);
        $this->currentEnrollment($allCurrent);
        $studentIds = $allCurrent->pluck('student_id');
        $totals = ['average_progress' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0];
        $sum = 0;
        User::query()->whereIn('id', $studentIds)->orderBy('id')->chunkById(100, function ($students) use ($course, &$totals, &$sum) {
            foreach ($this->progress->courseStudents($course, $students) as $item) {
                $sum += $item['percent'];
                $totals[$this->bucket($item['percent'])]++;
            }
        });
        $totals['average_progress'] = $studentIds->count() ? (int) round($sum / $studentIds->count()) : 0;

        return ['students' => $paginator, 'summary' => [
            'enrollments' => $course->enrollments()->count(),
            'active_enrollments' => $studentIds->count(),
            'available_lessons' => $this->curriculum->lessons($course)->where(fn ($lesson) => ! $lesson->available_at?->isFuture())->count(),
            ...$totals,
        ]];
    }

    public function exportRows(Course $course, array $filters): Collection
    {
        $query = CourseEnrollment::query()->where('course_id', $course->id)->with('student:id,first_name,last_name,mobile,is_active');
        $this->applyEnrollmentFilters($query, $filters);

        return $query->orderBy('id')->get()->chunk(100)->flatMap(fn ($chunk) => $this->studentRows($course, $chunk, $filters))->values();
    }

    public function student(User $viewer, User $student, ?Course $selected = null): array
    {
        $enrollments = CourseEnrollment::query()->with('course.category:id,name')->where('student_id', $student->id)
            ->when($viewer->hasRole('TEACHER'), fn ($q) => $q->whereHas('course.teachers', fn ($q) => $q->whereKey($viewer->id)))->get();
        $courses = $enrollments->pluck('course');
        $progress = $this->progress->courses($student, $courses);
        $courseRows = $enrollments->map(fn ($enrollment) => [
            'id' => $enrollment->course->id,
            'title' => $enrollment->course->title,
            'enrollment_status' => $enrollment->status->value,
            'starts_at' => $enrollment->starts_at,
            'ends_at' => $enrollment->ends_at,
            ...$progress[$enrollment->course_id],
        ]);

        $detail = null;
        if ($selected && $courses->contains('id', $selected->id)) {
            $lessons = $this->curriculum->lessons($selected);
            $decisions = $this->lessonAccess->decisions($student, $lessons);
            $videos = LessonVideo::query()->whereIn('lesson_id', $lessons->pluck('id'))->with(['progress' => fn ($q) => $q->where('user_id', $student->id)])->orderBy('sort_order')->get();
            $lessonRows = $lessons->map(function ($lesson) use ($progress, $selected, $decisions, $videos) {
                return ['id' => $lesson->id, 'title' => $lesson->title, 'section' => $lesson->section?->title, 'available_at' => $lesson->available_at, 'access' => $decisions[$lesson->id], 'state' => $progress[$selected->id]['lessons'][$lesson->id] ?? 'scheduled', 'videos' => $videos->where('lesson_id', $lesson->id)->map(function ($video) {
                    $item = $video->progress->first();
                    $percent = $video->duration_seconds ? (int) min(100, round(($item?->watched_seconds ?? 0) / $video->duration_seconds * 100)) : 0;

                    return ['id' => $video->id, 'title' => $video->title, 'status' => $video->status->value, 'publication_status' => $video->publication_status->value, 'watched_seconds' => $item?->watched_seconds ?? 0, 'duration_seconds' => $video->duration_seconds, 'percent' => $percent, 'completed' => $item?->completed_at !== null, 'last_viewed_at' => $item?->last_heartbeat_at];
                })->values()];
            });
            $detail = ['course' => $selected->only('id', 'title'), 'lessons' => $lessonRows, 'assignments' => $this->assignmentSummary($selected, collect([$student]))[$student->id], 'questions' => $this->questionSummary($selected, collect([$student]))[$student->id]];
        }

        return ['student' => ['id' => $student->id, 'first_name' => $student->first_name, 'last_name' => $student->last_name, 'mobile' => $this->maskedMobile($student->mobile)], 'courses' => $courseRows, 'detail' => $detail];
    }

    private function studentRows(Course $course, Collection $enrollments, array $filters): Collection
    {
        $students = $enrollments->pluck('student');
        $progress = $this->progress->courseStudents($course, $students);
        $assignments = $this->assignmentSummary($course, $students);
        $questions = $this->questionSummary($course, $students);
        $activity = $this->lastActivity($course, $students);

        return $enrollments->map(function ($enrollment) use ($progress, $assignments, $questions, $activity) {
            $p = $progress[$enrollment->student_id];

            return ['id' => $enrollment->id, 'student' => ['id' => $enrollment->student->id, 'first_name' => $enrollment->student->first_name, 'last_name' => $enrollment->student->last_name, 'mobile' => $this->maskedMobile($enrollment->student->mobile)], 'status' => $enrollment->status->value, 'starts_at' => $enrollment->starts_at, 'ends_at' => $enrollment->ends_at, 'progress' => $p, 'progress_state' => $this->bucket($p['percent']), 'assignments' => $assignments[$enrollment->student_id], 'questions' => $questions[$enrollment->student_id], 'last_activity_at' => $activity[$enrollment->student_id] ?? null];
        })->filter(function ($row) use ($filters) {
            if (($filters['progress_state'] ?? null) && $row['progress_state'] !== $filters['progress_state']) {
                return false;
            }
            if (isset($filters['min_progress']) && $filters['min_progress'] !== '' && $row['progress']['percent'] < (int) $filters['min_progress']) {
                return false;
            }
            if (isset($filters['max_progress']) && $filters['max_progress'] !== '' && $row['progress']['percent'] > (int) $filters['max_progress']) {
                return false;
            }
            if (($filters['inactive_days'] ?? null) && $row['last_activity_at'] !== null && $row['last_activity_at']->gt(now()->subDays((int) $filters['inactive_days']))) {
                return false;
            }

            return true;
        })->values();
    }

    private function assignmentSummary(Course $course, Collection $students): array
    {
        $assignmentIds = LessonAssignment::query()->whereHas('lesson', fn ($q) => $q->where('course_id', $course->id))->where('status', AssignmentStatus::Published->value)->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->pluck('id');
        $submissions = AssignmentSubmission::query()->whereIn('student_id', $students->pluck('id'))->whereIn('assignment_id', $assignmentIds)->orderBy('attempt_number')->get()->groupBy('student_id');

        return $students->mapWithKeys(function ($student) use ($assignmentIds, $submissions) {
            $latest = $submissions->get($student->id, collect())->groupBy('assignment_id')->map->last();

            return [$student->id => ['total' => $assignmentIds->count(), 'submitted' => $latest->count(), 'approved' => $latest->where('status', SubmissionStatus::Approved)->count(), 'revision_requested' => $latest->where('status', SubmissionStatus::RevisionRequested)->count(), 'rejected' => $latest->where('status', SubmissionStatus::Rejected)->count(), 'not_submitted' => max(0, $assignmentIds->count() - $latest->count()), 'late' => $latest->where('is_late', true)->count(), 'latest_submission_id' => $latest->sortByDesc('submitted_at')->first()?->id]];
        })->all();
    }

    private function questionSummary(Course $course, Collection $students): array
    {
        $counts = LessonQuestion::query()->select('student_id', 'status', DB::raw('count(*) as aggregate'))->whereIn('student_id', $students->pluck('id'))->whereHas('lesson', fn ($q) => $q->where('course_id', $course->id))->groupBy('student_id', 'status')->get()->groupBy('student_id');

        return $students->mapWithKeys(function ($student) use ($counts) {
            $items = $counts->get($student->id, collect());
            $value = fn ($status) => (int) ($items->first(fn ($item) => $item->status === $status)?->aggregate ?? 0);

            return [$student->id => ['total' => $items->sum('aggregate'), 'open' => $value(LessonQuestionStatus::Open), 'answered' => $value(LessonQuestionStatus::Answered), 'resolved' => $value(LessonQuestionStatus::Resolved)]];
        })->all();
    }

    private function lastActivity(Course $course, Collection $students): array
    {
        $ids = $students->pluck('id');
        $video = VideoProgress::query()->whereIn('user_id', $ids)->whereHas('video.lesson', fn ($q) => $q->where('course_id', $course->id))->select('user_id', DB::raw('max(last_heartbeat_at) as last_at'))->groupBy('user_id')->pluck('last_at', 'user_id');
        $manual = LessonManualCompletion::query()->whereIn('user_id', $ids)->whereHas('lesson', fn ($q) => $q->where('course_id', $course->id))->select('user_id', DB::raw('max(completed_at) as last_at'))->groupBy('user_id')->pluck('last_at', 'user_id');
        $submission = AssignmentSubmission::query()->whereIn('student_id', $ids)->whereHas('assignment.lesson', fn ($q) => $q->where('course_id', $course->id))->select('student_id', DB::raw('max(submitted_at) as last_at'))->groupBy('student_id')->pluck('last_at', 'student_id');

        return $students->mapWithKeys(function ($student) use ($video, $manual, $submission) {
            $latest = collect([$video[$student->id] ?? null, $manual[$student->id] ?? null, $submission[$student->id] ?? null])->filter()->max();

            return [$student->id => $latest ? Carbon::parse($latest) : null];
        })->all();
    }

    private function applyEnrollmentFilters(Builder $query, array $filters): void
    {
        $query->when($filters['enrollment_status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['student'] ?? null, fn ($q, $term) => $q->whereHas('student', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")->orWhere('mobile', 'like', "%$term%"))));
    }

    private function currentEnrollment(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active->value)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    private function bucket(int $percent): string
    {
        return $percent === 0 ? 'not_started' : ($percent >= 100 ? 'completed' : 'in_progress');
    }

    private function maskedMobile(?string $mobile): string
    {
        if (! $mobile || strlen($mobile) < 8) {
            return '—';
        }

        return substr($mobile, 0, 4).'***'.substr($mobile, -4);
    }
}
