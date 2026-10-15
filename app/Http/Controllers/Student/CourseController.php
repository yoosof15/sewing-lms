<?php

namespace App\Http\Controllers\Student;

use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\VideoProgress;
use App\Services\CurriculumService;
use App\Services\LearningProgressService;
use App\Services\StudentCourseAccess;
use App\Services\StudentLessonAccess;
use App\Services\SystemSettings;
use App\Services\VideoPlaybackAccess;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(StudentCourseAccess $access, LearningProgressService $progress): Response
    {
        $courses = $access->coursesFor(request()->user())->with(['category:id,name', 'teachers:id,first_name,last_name'])->latest()->paginate(12);
        $summaries = $progress->courses(request()->user(), $courses->getCollection());
        $courses->getCollection()->transform(function ($course) use ($summaries) {
            $course->setAttribute('progress', $summaries[$course->id]);

            return $course;
        });

        return Inertia::render('Student/Courses/Index', compact('courses'));
    }

    public function show(Course $course, LearningProgressService $progress, CurriculumService $curriculum, StudentLessonAccess $lessonAccess): Response
    {
        $this->authorize('view', $course);
        $course->load(['category:id,name', 'teachers' => fn ($query) => $query->select('users.id', 'first_name', 'last_name')->with('profile:user_id,avatar_path,bio,teacher_specialty')]);
        $course->setRelation('lessons', $curriculum->lessons($course));
        $summary = $progress->course(request()->user(), $course);
        $decisions = $lessonAccess->decisions(request()->user(), $course->lessons);
        $course->lessons->each(function ($lesson) use ($summary, $decisions) {
            $lesson->setAttribute('progress_state', $summary['lessons'][$lesson->id] ?? 'not_started');
            $lesson->setAttribute('access', $decisions[$lesson->id]);
        });

        $teacherProfiles = $course->teachers->map(fn ($teacher) => [
            'id' => $teacher->id, 'name' => $teacher->name,
            'avatar_url' => $teacher->profile?->avatar_path ? Storage::disk('public')->url($teacher->profile->avatar_path) : null,
            'specialty' => $teacher->profile?->teacher_specialty, 'bio' => $teacher->profile?->bio,
        ]);
        $course->unsetRelation('teachers');

        return Inertia::render('Student/Courses/Show', compact('course', 'summary', 'teacherProfiles'));
    }

    public function lesson(Course $course, Lesson $lesson, VideoPlaybackAccess $playbackAccess, LearningProgressService $learning, SystemSettings $settings): Response
    {
        abort_unless($lesson->course_id === $course->id, 404);
        $this->authorize('view', $lesson);
        $lesson->load('attachments');
        $lesson->setRelation('questions', $lesson->questions()->where('student_id', request()->user()->id)->latest('last_activity_at')->limit(20)->get());
        $lesson->setRelation('assignments', $lesson->assignments()->where('status', 'PUBLISHED')->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->with(['submissions' => fn ($q) => $q->where('student_id', request()->user()->id)->with('files')->orderBy('attempt_number')])->get());
        $visibleVideos = $lesson->videos()
            ->where('status', VideoStatus::Ready->value)
            ->where('publication_status', VideoPublicationStatus::Published->value)
            ->orderBy('sort_order')
            ->get()->filter(fn ($video) => $playbackAccess->studentCanPlay(request()->user(), $video));
        $progressByVideo = VideoProgress::where('user_id', request()->user()->id)->whereIn('lesson_video_id', $visibleVideos->pluck('id'))->get()->keyBy('lesson_video_id');
        $videos = $visibleVideos
            ->map(function ($video) use ($course, $lesson, $progressByVideo) {
                $progress = $progressByVideo->get($video->id);
                $percent = $progress && $video->duration_seconds ? (int) min(100, round($progress->watched_seconds / $video->duration_seconds * 100)) : 0;
                $resume = $progress?->last_position_seconds ?? 0;
                if ($progress?->completed_at && $video->duration_seconds && $resume >= $video->duration_seconds - 5) {
                    $resume = 0;
                }

                return ['id' => $video->id, 'title' => $video->title, 'stream_url' => route('student.videos.stream', [$course, $lesson, $video]), 'progress_url' => route('student.videos.progress', [$course, $lesson, $video]), 'resume_position' => $resume, 'progress_percent' => $percent, 'completed' => $progress?->completed_at !== null];
            })->values();
        $lessonProgress = $learning->lesson(request()->user(), $lesson);
        $user = request()->user();
        $localMobile = str_starts_with((string) $user->mobile, '+98') ? '0'.substr($user->mobile, 3) : (string) $user->mobile;
        $masked = strlen($localMobile) >= 8 ? substr($localMobile, 0, 4).'***'.substr($localMobile, -4) : 'شماره محفوظ';
        $watermark = ['enabled' => $settings->boolean('watermark_enabled'), 'text' => trim($user->first_name.' '.$user->last_name), 'mobile' => $masked, 'move_seconds' => $settings->integer('watermark_move_seconds')];
        $progressConfig = ['heartbeat_seconds' => (int) config('learning.progress_heartbeat_seconds')];

        return Inertia::render('Student/Lessons/Show', compact('course', 'lesson', 'videos', 'lessonProgress', 'watermark', 'progressConfig'));
    }
}
