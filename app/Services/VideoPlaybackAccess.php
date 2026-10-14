<?php

namespace App\Services;

use App\Contracts\VideoStorage;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class VideoPlaybackAccess
{
    public function __construct(
        private readonly StudentLessonAccess $lessonAccess,
        private readonly VideoStorage $storage,
    ) {}

    public function studentCanPlay(User $student, LessonVideo $video): bool
    {
        $lesson = $video->lesson;

        return $student->hasRole('STUDENT')
            && ! $video->trashed()
            && $video->status === VideoStatus::Ready
            && $video->publication_status === VideoPublicationStatus::Published
            && $lesson !== null
            && ! $lesson->trashed()
            && $this->lessonAccess->allows($student, $lesson)
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
