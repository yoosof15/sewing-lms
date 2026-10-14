<?php

namespace App\Http\Controllers;

use App\Contracts\VideoStorage;
use App\Enums\VideoStatus;
use App\Enums\VideoUploadStatus;
use App\Exceptions\VideoOffsetMismatch;
use App\Http\Requests\InitiateVideoUploadRequest;
use App\Jobs\ProcessLessonVideo;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\VideoUploadSession;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoUploadController extends Controller
{
    public function initiate(InitiateVideoUploadRequest $request, Lesson $lesson, VideoStorage $storage): JsonResponse
    {
        $id = (string) Str::ulid();
        $temp = "uploads/$id.part";
        $storage->createTemporary($temp);
        try {
            $session = VideoUploadSession::create(['public_id' => $id, 'user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'title' => $request->validated('title'), 'original_filename' => basename(str_replace('\\', '/', $request->validated('original_filename'))), 'declared_mime_type' => $request->validated('declared_mime_type'), 'total_bytes' => $request->integer('total_bytes'), 'uploaded_bytes' => 0, 'temporary_key' => $temp, 'status' => VideoUploadStatus::Uploading, 'expires_at' => now()->addHours((int) config('video.session_ttl_hours'))]);
        } catch (\Throwable $e) {
            $storage->deleteTemporary($temp);
            throw $e;
        }

        return response()->json($this->payload($session), 201);
    }

    public function show(Request $request, VideoUploadSession $uploadSession): JsonResponse
    {
        $this->owner($request, $uploadSession);

        return response()->json($this->payload($uploadSession));
    }

    public function chunk(Request $request, VideoUploadSession $uploadSession, VideoStorage $storage): JsonResponse
    {
        $this->owner($request, $uploadSession);
        $offset = (int) $request->header('Upload-Offset', -1);
        $bytes = $request->getContent();
        $max = (int) config('video.chunk_mb') * 1024 * 1024;
        if ($bytes === '' || strlen($bytes) > $max) {
            return response()->json(['message' => 'اندازه قطعه معتبر نیست.', 'expected_offset' => $uploadSession->uploaded_bytes], 422);
        }
        try {
            $result = DB::transaction(function () use ($uploadSession, $storage, $offset, $bytes) {
                $session = VideoUploadSession::whereKey($uploadSession->id)->lockForUpdate()->firstOrFail();
                $this->assertOpen($session);
                if ($offset !== $session->uploaded_bytes) {
                    throw new VideoOffsetMismatch($session->uploaded_bytes);
                }if ($session->uploaded_bytes + strlen($bytes) > $session->total_bytes) {
                    abort(422, 'Chunk exceeds declared size.');
                }$new = $storage->append($session->temporary_key, $bytes, $session->uploaded_bytes);
                $session->update(['uploaded_bytes' => $new, 'expires_at' => now()->addHours((int) config('video.session_ttl_hours'))]);

                return $session;
            });
        } catch (VideoOffsetMismatch $e) {
            return response()->json(['message' => 'offset نامعتبر است.', 'expected_offset' => $e->expectedOffset], 409);
        }

        return response()->json($this->payload($result));
    }

    public function finalize(Request $request, VideoUploadSession $uploadSession, VideoStorage $storage, AuditService $audit): JsonResponse
    {
        $this->owner($request, $uploadSession);
        $session = VideoUploadSession::with('video')->findOrFail($uploadSession->id);
        if ($session->status === VideoUploadStatus::Completed && $session->video) {
            return response()->json(['video' => $session->video, 'upload' => $this->payload($session)]);
        }
        $this->assertOpen($session);
        if ($session->uploaded_bytes !== $session->total_bytes || ! $storage->temporaryExists($session->temporary_key) || $storage->temporarySize($session->temporary_key) !== $session->total_bytes) {
            return response()->json(['message' => 'بارگذاری کامل نشده است.', 'expected_offset' => $session->uploaded_bytes], 409);
        }
        $mime = $storage->detectedMimeType($session->temporary_key);
        if (! in_array($mime, ['video/mp4', 'video/quicktime', 'application/mp4'], true)) {
            $storage->deleteTemporary($session->temporary_key);
            $session->update(['status' => VideoUploadStatus::Failed, 'failure_reason' => 'نوع محتوای فایل ویدئویی معتبر نیست.']);
            Log::warning('Video upload validation failed', ['upload_session' => $session->public_id, 'user_id' => $session->user_id, 'lesson_id' => $session->lesson_id, 'stage' => 'finalize', 'category' => 'invalid_mime']);

            return response()->json(['message' => 'محتوای فایل، ویدئوی پشتیبانی‌شده نیست.'], 422);
        }
        $key = 'videos/'.$session->lesson_id.'/'.Str::lower((string) Str::ulid());
        $storage->promote($session->temporary_key, $key);
        try {
            $video = DB::transaction(function () use ($session, $storage, $key, $mime) {
                $locked = VideoUploadSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($locked->lesson_video_id) {
                    return LessonVideo::findOrFail($locked->lesson_video_id);
                }$order = ((int) LessonVideo::where('lesson_id', $locked->lesson_id)->max('sort_order')) + 1;
                $video = LessonVideo::create(['lesson_id' => $locked->lesson_id, 'uploaded_by' => $locked->user_id, 'title' => $locked->title, 'original_filename' => $locked->original_filename, 'storage_driver' => $storage->driver(), 'storage_key' => $key, 'original_mime_type' => $mime, 'size_bytes' => $locked->total_bytes, 'sort_order' => $order, 'status' => VideoStatus::Stored]);
                $locked->update(['lesson_video_id' => $video->id, 'status' => VideoUploadStatus::Completed, 'failure_reason' => null]);

                return $video;
            });
        } catch (\Throwable $e) {
            $storage->delete($key);
            $session->update(['status' => VideoUploadStatus::Failed, 'failure_reason' => 'ثبت نهایی ویدئو ناموفق بود.']);
            Log::error('Video upload persistence failed', ['upload_session' => $session->public_id, 'user_id' => $session->user_id, 'lesson_id' => $session->lesson_id, 'stage' => 'persist', 'category' => get_class($e)]);
            throw $e;
        }

        if (config('video.processor_driver') !== null && config('video.processor_driver') !== 'null') {
            ProcessLessonVideo::dispatch($video->id)->afterCommit();
        }
        $audit->record('video.upload_completed', $video, ['lesson_id' => $video->lesson_id, 'size' => $video->size_bytes, 'mime_type' => $video->original_mime_type]);

        return response()->json(['video' => $video, 'upload' => $this->payload($session->fresh())], 201);
    }

    public function cancel(Request $request, VideoUploadSession $uploadSession, VideoStorage $storage): JsonResponse
    {
        $this->owner($request, $uploadSession);
        if ($uploadSession->status === VideoUploadStatus::Uploading) {
            $storage->deleteTemporary($uploadSession->temporary_key);
            $uploadSession->update(['status' => VideoUploadStatus::Cancelled, 'failure_reason' => 'بارگذاری توسط کاربر لغو شد.']);
        }

        return response()->json($this->payload($uploadSession->fresh()));
    }

    private function owner(Request $request, VideoUploadSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 403);
        abort_unless($request->user()->can('update', $session->lesson), 403);
    }

    private function assertOpen(VideoUploadSession $session): void
    {
        abort_unless($session->status === VideoUploadStatus::Uploading, 409);
        if ($session->expires_at->isPast()) {
            abort(410, 'Upload session expired.');
        }
    }

    private function payload(VideoUploadSession $s): array
    {
        return ['id' => $s->public_id, 'status' => $s->status->value, 'title' => $s->title, 'original_filename' => $s->original_filename, 'total_bytes' => $s->total_bytes, 'uploaded_bytes' => $s->uploaded_bytes, 'expected_offset' => $s->uploaded_bytes, 'chunk_size' => (int) config('video.chunk_mb') * 1024 * 1024, 'expires_at' => $s->expires_at];
    }
}
