<?php

namespace App\Console\Commands;

use App\Contracts\VideoStorage;
use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use App\Models\VideoUploadSession;
use Illuminate\Console\Command;

class VerifyVideoAssets extends Command
{
    protected $signature = 'video:verify-assets';

    protected $description = 'Read-only verification of database video references and known upload artifacts';

    public function handle(VideoStorage $storage): int
    {
        $problems = 0;
        LessonVideo::withTrashed()->orderBy('id')->chunkById(100, function ($videos) use ($storage, &$problems) {
            foreach ($videos as $video) {
                if ($video->storage_key && ! $storage->exists($video->storage_key) && ! $video->source_deleted_at) {
                    $this->warn("Video {$video->id}: source asset is missing.");
                    $problems++;
                }
                if ($video->processed_storage_key && ! $storage->exists($video->processed_storage_key)) {
                    $this->warn("Video {$video->id}: processed asset is missing.");
                    $problems++;
                }
                if ($video->status === VideoStatus::Ready && (! $video->processed_storage_key || ! $storage->exists($video->processed_storage_key))) {
                    $this->error("Video {$video->id}: READY without a usable processed asset.");
                    $problems++;
                }
            }
        });
        VideoUploadSession::query()->where('expires_at', '<', now())->whereNotIn('status', ['COMPLETED', 'EXPIRED', 'CANCELLED'])->each(function ($session) use (&$problems) {
            $this->warn("Upload {$session->public_id}: stale session requires cleanup review.");
            $problems++;
        });
        $this->info($problems ? "Verification completed with {$problems} finding(s); nothing was changed." : 'Verification completed: no obvious asset problems found.');

        return self::SUCCESS;
    }
}
