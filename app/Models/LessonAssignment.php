<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = ['lesson_id', 'created_by', 'title', 'description', 'status', 'available_at', 'due_at', 'allow_late_submission', 'allow_resubmission', 'max_attempts', 'availability_notification_dispatched_at'];

    protected function casts(): array
    {
        return ['status' => AssignmentStatus::class, 'available_at' => 'datetime', 'due_at' => 'datetime', 'allow_late_submission' => 'boolean', 'allow_resubmission' => 'boolean', 'max_attempts' => 'integer', 'availability_notification_dispatched_at' => 'datetime'];
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions()
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }
}
