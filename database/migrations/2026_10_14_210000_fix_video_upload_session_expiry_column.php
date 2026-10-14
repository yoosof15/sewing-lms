<?php

use App\Enums\VideoUploadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_upload_sessions', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });

        DB::table('video_upload_sessions')
            ->where('status', VideoUploadStatus::Uploading->value)
            ->update([
                'expires_at' => now()->addHours((int) config('video.session_ttl_hours')),
            ]);
    }

    public function down(): void
    {
        Schema::table('video_upload_sessions', function (Blueprint $table) {
            $table->timestamp('expires_at')->change();
        });
    }
};
