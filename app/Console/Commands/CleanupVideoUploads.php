<?php

namespace App\Console\Commands;

use App\Contracts\VideoStorage;
use App\Enums\VideoUploadStatus;
use App\Models\VideoUploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupVideoUploads extends Command
{
    protected $signature = 'video:cleanup-uploads';

    protected $description = 'Remove expired incomplete video uploads';

    public function handle(VideoStorage $storage): int
    {
        VideoUploadSession::where('status', VideoUploadStatus::Uploading->value)->where('expires_at', '<', now())->orderBy('id')->chunkById(100, function ($sessions) use ($storage) {
            foreach ($sessions as $session) {
                try {
                    $storage->deleteTemporary($session->temporary_key);
                    $session->update(['status' => VideoUploadStatus::Expired, 'failure_reason' => 'مهلت بارگذاری پایان یافت.']);
                } catch (\Throwable $e) {
                    Log::error('Expired video upload cleanup failed', ['upload_session' => $session->public_id, 'user_id' => $session->user_id, 'lesson_id' => $session->lesson_id, 'stage' => 'cleanup', 'category' => get_class($e)]);
                    $this->error("Could not clean upload {$session->public_id}");
                }
            }
        });
        $this->info('Expired video uploads cleaned.');

        return self::SUCCESS;
    }
}
