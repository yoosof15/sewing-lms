<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_videos', function (Blueprint $table) {
            $table->string('processed_storage_key')->nullable()->unique()->after('storage_key');
            $table->unsignedBigInteger('processed_size_bytes')->nullable()->after('size_bytes');
            $table->string('processed_mime_type', 120)->nullable()->after('processed_size_bytes');
            $table->unsignedInteger('width')->nullable()->after('duration_seconds');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->decimal('frame_rate', 8, 3)->nullable()->after('height');
            $table->string('source_codec', 50)->nullable()->after('frame_rate');
            $table->string('processed_codec', 50)->nullable()->after('source_codec');
            $table->string('audio_codec', 50)->nullable()->after('processed_codec');
            $table->string('container_format', 120)->nullable()->after('audio_codec');
            $table->unsignedBigInteger('source_bitrate')->nullable()->after('container_format');
            $table->timestamp('processing_started_at')->nullable()->after('failure_reason');
            $table->timestamp('processed_at')->nullable()->after('processing_started_at');
            $table->unsignedSmallInteger('processing_attempts')->default(0)->after('processed_at');
            $table->timestamp('source_deleted_at')->nullable()->after('processing_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_videos', function (Blueprint $table) {
            $table->dropUnique(['processed_storage_key']);
            $table->dropColumn(['processed_storage_key', 'processed_size_bytes', 'processed_mime_type', 'width', 'height', 'frame_rate', 'source_codec', 'processed_codec', 'audio_codec', 'container_format', 'source_bitrate', 'processing_started_at', 'processed_at', 'processing_attempts', 'source_deleted_at']);
        });
    }
};
