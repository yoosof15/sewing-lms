<?php

namespace App\Services;

use App\Contracts\VideoStorage;
use App\Enums\LessonStatus;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class VideoPlaybackAccess
{
    public function __construct(
        private readonly StudentCourseAccess $courseAccess,
        private readonly VideoStorage $storage,
    ) {}

    public function studentCanPlay(User $student, LessonVideo $video): bool
    {
        $lesson = $video->lesson;
        $course = $lesson?->course;

        return $student->hasRole('STUDENT')
            && ! $video->trashed()
            && $video->status === VideoStatus::Ready
            && $video->publication_status === VideoPublicationStatus::Published
            && $lesson !== null
            && ! $lesson->trashed()
            && $lesson->status === LessonStatus::Published
            && $course !== null
            && ! $course->trashed()
            && $this->courseAccess->allows($student, $course)
            && $this->processedAssetExists($video);
    }

    public function managerCanPreview(User $user, LessonVideo $video): bool
    {
        return ! $video->trashed()
            && $video->status === VideoStatus::Ready
            && ($user->hasRole('ADMIN') || ($user->hasRole('TEACHER') && $user->can('update', $video)))
            && $this->processedAssetExists($video);
    }

    public function processedAssetExists(LessonVideo $video): bool
    {
        if (! $video->processed_storage_key || ! $this->storage->exists($video->processed_storage_key)) {
            Log::warning('Processed video asset is missing', ['lesson_video_id' => $video->id, 'stage' => 'playback']);

            return false;
        }

        return true;
    }
}
