<?php

namespace App\Models;

use App\Enums\VideoPublicationStatus;
use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonVideo extends Model
{
    use SoftDeletes;

    protected $hidden = ['storage_key', 'processed_storage_key'];

    protected $fillable = ['lesson_id', 'uploaded_by', 'title', 'original_filename', 'storage_driver', 'storage_key', 'original_mime_type', 'size_bytes', 'duration_seconds', 'sort_order', 'status', 'publication_status', 'published_at', 'failure_reason', 'processed_storage_key', 'processed_size_bytes', 'processed_mime_type', 'width', 'height', 'frame_rate', 'source_codec', 'processed_codec', 'audio_codec', 'container_format', 'source_bitrate', 'processing_started_at', 'processed_at', 'processing_attempts', 'source_deleted_at'];

    protected function casts(): array
    {
        return ['status' => VideoStatus::class, 'publication_status' => VideoPublicationStatus::class, 'published_at' => 'datetime', 'size_bytes' => 'integer', 'processed_size_bytes' => 'integer', 'duration_seconds' => 'integer', 'width' => 'integer', 'height' => 'integer', 'frame_rate' => 'float', 'source_bitrate' => 'integer', 'processing_attempts' => 'integer', 'processing_started_at' => 'datetime', 'processed_at' => 'datetime', 'source_deleted_at' => 'datetime'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
