<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\VideoProgress;
use App\Services\LearningProgressService;
use App\Services\VideoPlaybackAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VideoProgressController extends Controller
{
    public function update(Request $request, Course $course, Lesson $lesson, LessonVideo $video, VideoPlaybackAccess $access, LearningProgressService $learning): JsonResponse
    {
        abort_unless($lesson->course_id === $course->id && $video->lesson_id === $lesson->id, 404);
        abort_unless($access->studentCanPlay($request->user(), $video), 404);
        $data = $request->validate(['position_seconds' => ['required', 'integer', 'min:0']]);
        abort_unless($video->duration_seconds && $video->duration_seconds > 0, 409, 'Video duration is unavailable.');
        $position = min((int) $data['position_seconds'], $video->duration_seconds);
        DB::table('video_progress')->insertOrIgnore([
            'user_id' => $request->user()->id, 'lesson_video_id' => $video->id,
            'last_position_seconds' => 0, 'watched_seconds' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $progress = DB::transaction(function () use ($request, $video, $position) {
            $progress = VideoProgress::where('user_id', $request->user()->id)->where('lesson_video_id', $video->id)->lockForUpdate()->firstOrFail();
            // Whole wall-clock seconds keep the allowance deterministic and
            // avoid request-processing microseconds granting an extra second.
            $elapsed = $progress->last_heartbeat_at ? max(0, now()->timestamp - $progress->last_heartbeat_at->timestamp) : 0;
            $forward = max(0, $position - $progress->last_position_seconds);
            $creditLimit = (int) floor($elapsed * (float) config('learning.max_playback_rate_credit') + (int) config('learning.progress_credit_tolerance_seconds'));
            $credit = $progress->last_heartbeat_at ? min($forward, $creditLimit) : 0;
            $watched = min($video->duration_seconds, $progress->watched_seconds + $credit);
            $threshold = (int) $video->lesson->course->completion_threshold_percent;
            $completedAt = $progress->completed_at;
            if (! $completedAt && ($watched / $video->duration_seconds) * 100 >= $threshold) {
                $completedAt = now();
            }
            $progress->update(['last_position_seconds' => $position, 'watched_seconds' => $watched, 'last_heartbeat_at' => now(), 'completed_at' => $completedAt]);

            return $progress->fresh();
        });
        $percent = (int) min(100, round($progress->watched_seconds / $video->duration_seconds * 100));

        return response()->json(['last_position_seconds' => $progress->last_position_seconds, 'watched_seconds' => $progress->watched_seconds, 'percent' => $percent, 'completed' => $progress->completed_at !== null, 'lesson' => $learning->lesson($request->user(), $lesson)]);
    }
}
