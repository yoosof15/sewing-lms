<?php

namespace App\Models;

use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonVideo extends Model
{
    use SoftDeletes;

    protected $fillable = ['lesson_id', 'uploaded_by', 'title', 'original_filename', 'storage_driver', 'storage_key', 'original_mime_type', 'size_bytes', 'duration_seconds', 'sort_order', 'status', 'failure_reason'];

    protected function casts(): array
    {
        return ['status' => VideoStatus::class, 'size_bytes' => 'integer', 'duration_seconds' => 'integer'];
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
