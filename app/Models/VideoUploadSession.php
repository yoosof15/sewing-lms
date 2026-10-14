<?php

namespace App\Models;

use App\Enums\VideoUploadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoUploadSession extends Model
{
    protected $fillable = ['public_id', 'user_id', 'lesson_id', 'lesson_video_id', 'title', 'original_filename', 'declared_mime_type', 'total_bytes', 'uploaded_bytes', 'temporary_key', 'status', 'failure_reason', 'expires_at'];

    protected function casts(): array
    {
        return ['status' => VideoUploadStatus::class, 'total_bytes' => 'integer', 'uploaded_bytes' => 'integer', 'expires_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(LessonVideo::class, 'lesson_video_id');
    }
}
