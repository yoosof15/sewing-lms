<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoProgress extends Model
{
    protected $table = 'video_progress';

    protected $fillable = ['user_id', 'lesson_video_id', 'last_position_seconds', 'watched_seconds', 'completed_at', 'last_heartbeat_at'];

    protected function casts(): array
    {
        return ['last_position_seconds' => 'integer', 'watched_seconds' => 'integer', 'completed_at' => 'datetime', 'last_heartbeat_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(LessonVideo::class, 'lesson_video_id');
    }
}
