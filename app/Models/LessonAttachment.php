<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = ['lesson_id', 'uploaded_by', 'original_name', 'storage_path', 'mime_type', 'file_size'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
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
