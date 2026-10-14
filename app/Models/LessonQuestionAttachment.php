<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonQuestionAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = ['question_id', 'reply_id', 'uploaded_by', 'original_name', 'storage_path', 'mime_type', 'file_size'];

    public function question()
    {
        return $this->belongsTo(LessonQuestion::class, 'question_id');
    }
}
