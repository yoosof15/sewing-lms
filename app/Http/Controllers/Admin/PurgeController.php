<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeLessonVideo;
use App\Models\Course;
use App\Models\LessonAttachment;
use App\Models\LessonVideo;
use App\Models\StorageCleanupTask;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PurgeController extends Controller
{
    public function course(Request $request, int $course): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:PURGE']]);
        $model = Course::onlyTrashed()->findOrFail($course);
        abort_if($model->enrollments()->exists() || $model->lessons()->withTrashed()->whereHas('videos.progress')->exists(), 409, 'دوره دارای سابقه یادگیری است و فقط می‌تواند بایگانی بماند.');
        abort_if($model->lessons()->withTrashed()->exists(), 409, 'ابتدا محتوای دوره را بررسی و پاک‌سازی کنید.');
        $cover = $model->cover_image;
        if ($cover && Storage::disk('public')->exists($cover) && ! Storage::disk('public')->delete($cover)) {
            abort(503, 'حذف فایل دوره ناموفق بود؛ دوباره تلاش کنید.');
        }
        app(AuditService::class)->record('course.purged', $model);
        $model->forceDelete();

        return redirect()->route('admin.archive.index')->with('success', 'دوره برای همیشه حذف شد.');
    }

    public function attachment(Request $request, int $attachment): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:PURGE']]);
        $model = LessonAttachment::onlyTrashed()->findOrFail($attachment);
        if (Storage::disk('local')->exists($model->storage_path) && ! Storage::disk('local')->delete($model->storage_path)) {
            abort(503, 'حذف فایل ضمیمه ناموفق بود؛ اطلاعات نگهداری شد.');
        }
        app(AuditService::class)->record('attachment.purged', $model);
        $model->forceDelete();

        return back()->with('success', 'فایل برای همیشه حذف شد.');
    }

    public function video(Request $request, int $video): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:PURGE']]);
        $model = LessonVideo::onlyTrashed()->findOrFail($video);
        $task = StorageCleanupTask::query()->firstOrCreate(['resource_type' => 'lesson_video', 'resource_id' => $model->id]);
        PurgeLessonVideo::dispatch($task->id);
        app(AuditService::class)->record('video.purge_requested', $model);

        return back()->with('success', 'پاک‌سازی امن ویدئو در صف قرار گرفت.');
    }
}
