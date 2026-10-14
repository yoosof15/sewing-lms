<?php

namespace App\Http\Controllers\Student;

use App\Enums\LessonStatus;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\StudentCourseAccess;
use App\Services\VideoPlaybackAccess;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(StudentCourseAccess $access): Response
    {
        $courses = $access->coursesFor(request()->user())->with(['category:id,name', 'teachers:id,first_name,last_name'])->latest()->paginate(12);

        return Inertia::render('Student/Courses/Index', compact('courses'));
    }

    public function show(Course $course): Response
    {
        $this->authorize('view', $course);
        $course->load(['category:id,name', 'teachers:id,first_name,last_name', 'lessons' => fn ($q) => $q->where('status', LessonStatus::Published->value)->orderBy('sort_order')]);

        return Inertia::render('Student/Courses/Show', compact('course'));
    }

    public function lesson(Course $course, Lesson $lesson, VideoPlaybackAccess $playbackAccess): Response
    {
        abort_unless($lesson->course_id === $course->id, 404);
        $this->authorize('view', $lesson);
        $lesson->load('attachments');
        $videos = $lesson->videos()
            ->where('status', VideoStatus::Ready->value)
            ->where('publication_status', VideoPublicationStatus::Published->value)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($video) => $playbackAccess->studentCanPlay(request()->user(), $video))
            ->map(fn ($video) => [
                'id' => $video->id,
                'title' => $video->title,
                'stream_url' => route('student.videos.stream', [$course, $lesson, $video]),
            ])->values();

        return Inertia::render('Student/Lessons/Show', compact('course', 'lesson', 'videos'));
    }
}
