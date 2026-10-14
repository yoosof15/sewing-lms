<?php

namespace App\Http\Controllers;

use App\Contracts\VideoDelivery;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Services\VideoPlaybackAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VideoPlaybackController extends Controller
{
    public function student(Request $request, Course $course, Lesson $lesson, LessonVideo $video, VideoPlaybackAccess $access, VideoDelivery $delivery): Response
    {
        abort_unless($lesson->course_id === $course->id && $video->lesson_id === $lesson->id, 404);
        abort_unless($access->studentCanPlay($request->user(), $video), 404);

        return $delivery->deliver($video, $request->header('Range'));
    }

    public function preview(Request $request, LessonVideo $video, VideoPlaybackAccess $access, VideoDelivery $delivery): Response
    {
        abort_unless($access->managerCanPreview($request->user(), $video), 403);

        return $delivery->deliver($video, $request->header('Range'));
    }
}
