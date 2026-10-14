<?php

namespace App\Models;

use App\Enums\LessonQuestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonQuestion extends Model
{
    use SoftDeletes;

    protected $fillable = ['lesson_id', 'student_id', 'subject', 'body', 'status', 'resolved_at', 'resolved_by', 'last_activity_at'];

    protected function casts(): array
    {
        return ['status' => LessonQuestionStatus::class, 'resolved_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function replies()
    {
        return $this->hasMany(LessonQuestionReply::class, 'question_id')->oldest();
    }

    public function attachments()
    {
        return $this->hasMany(LessonQuestionAttachment::class, 'question_id')->whereNull('reply_id');
    }
}
