<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonVideo;
use App\Models\StorageCleanupTask;
use App\Models\VideoUploadSession;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OperationsController extends Controller
{
    public function index(): Response
    {
        DB::select('select 1');

        return Inertia::render('Admin/Operations/Index', [
            'health' => [
                'database' => true,
                'video_storage' => (string) config('video.storage_driver'),
                'video_processor' => (string) config('video.processor_driver'),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'failed_videos' => LessonVideo::query()->where('status', VideoStatus::Failed)->count(),
                'pending_videos' => LessonVideo::query()->whereIn('status', [VideoStatus::Stored, VideoStatus::Processing])->count(),
                'stale_uploads' => VideoUploadSession::query()->where('expires_at', '<', now())->whereNotIn('status', ['COMPLETED', 'EXPIRED', 'CANCELLED'])->count(),
                'failed_cleanups' => StorageCleanupTask::query()->where('status', 'FAILED')->count(),
            ],
        ]);
    }
}
