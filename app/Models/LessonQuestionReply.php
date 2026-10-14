<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonQuestionReply extends Model
{
    use SoftDeletes;

    protected $fillable = ['question_id', 'user_id', 'body', 'is_staff'];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean'];
    }

    public function question()
    {
        return $this->belongsTo(LessonQuestion::class, 'question_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(LessonQuestionAttachment::class, 'reply_id');
    }
}
