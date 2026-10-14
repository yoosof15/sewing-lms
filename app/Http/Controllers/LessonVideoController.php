<?php

namespace App\Http\Controllers;

use App\Contracts\VideoStorage;
use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use App\Jobs\ProcessLessonVideo;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Services\AuditService;
use App\Services\NotificationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LessonVideoController extends Controller
{
    public function publication(Request $request, Lesson $lesson, LessonVideo $video, VideoStorage $storage, AuditService $audit, NotificationEvents $notifications): RedirectResponse
    {
        $this->bound($lesson, $video);
        $this->authorize('update', $video);
        $data = $request->validate(['publication_status' => ['required', Rule::enum(VideoPublicationStatus::class)]]);
        $status = VideoPublicationStatus::from($data['publication_status']);
        if ($status === VideoPublicationStatus::Published) {
            abort_unless($video->status === VideoStatus::Ready, 422, 'Only READY videos can be published.');
            abort_unless($video->processed_storage_key && $storage->exists($video->processed_storage_key), 422, 'Processed video asset is unavailable.');
            abort_unless($video->lesson && $video->lesson->course, 422, 'Video parents are unavailable.');
        }
        $before = $video->publication_status->value;
        $video->update([
            'publication_status' => $status,
            'published_at' => $status === VideoPublicationStatus::Published ? ($video->published_at ?? now()) : null,
        ]);
        $audit->record('video.publication_changed', $video, ['from' => $before, 'to' => $status->value]);
        if ($before !== VideoPublicationStatus::Published->value && $status === VideoPublicationStatus::Published) {
            $notifications->video($video->load('lesson.course'));
        }

        return back()->with('success', 'وضعیت انتشار ویدئو به‌روزرسانی شد.');
    }

    public function retry(Lesson $lesson, LessonVideo $video, AuditService $audit): RedirectResponse
    {
        $this->bound($lesson, $video);
        $this->authorize('update', $video);
        abort_if(config('video.processor_driver') === null || config('video.processor_driver') === 'null', 409, 'Video processing is disabled.');
        abort_unless($video->status === VideoStatus::Failed, 409, 'Only failed videos can be retried.');
        abort_if($video->processing_attempts >= (int) config('video.processing_max_attempts'), 409, 'Maximum processing attempts reached.');
        ProcessLessonVideo::dispatch($video->id);
        $audit->record('video.processing_retried', $video, ['attempts' => $video->processing_attempts]);

        return back()->with('success', 'پردازش مجدد ویدئو در صف قرار گرفت.');
    }

    public function destroy(Lesson $lesson, LessonVideo $video, AuditService $audit): RedirectResponse
    {
        $this->bound($lesson, $video);
        $this->authorize('delete', $video);
        DB::transaction(function () use ($lesson, $video) {
            $video->update(['sort_order' => 4000000000 + $video->id]);
            $video->delete();
            $this->normalize($lesson);
        });
        $audit->record('video.archived', $video, ['lesson_id' => $lesson->id]);

        return back()->with('success', 'ویدئو بایگانی شد.');
    }

    public function move(Lesson $lesson, LessonVideo $video, string $direction, AuditService $audit): RedirectResponse
    {
        $this->bound($lesson, $video);
        $this->authorize('update', $video);
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $op = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';
        $other = $lesson->videos()->where('sort_order', $op, $video->sort_order)->orderBy('sort_order', $order)->first();
        if ($other) {
            DB::transaction(function () use ($video, $other) {
                $a = $video->sort_order;
                $b = $other->sort_order;
                $video->update(['sort_order' => 0]);
                $other->update(['sort_order' => $a]);
                $video->update(['sort_order' => $b]);
            });
            $audit->record('video.reordered', $video, ['direction' => $direction]);
        }

        return back();
    }

    private function bound(Lesson $lesson, LessonVideo $video): void
    {
        abort_unless($video->lesson_id === $lesson->id, 404);
    }

    private function normalize(Lesson $lesson): void
    {
        $videos = $lesson->videos()->orderBy('sort_order')->get();
        $lesson->videos()->update(['sort_order' => DB::raw('sort_order + 1000000')]);
        $videos->each(fn ($v, $i) => $v->update(['sort_order' => $i + 1]));
    }
}
