<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssignmentSubmission extends Model
{
    use SoftDeletes;

    protected $fillable = ['assignment_id', 'student_id', 'attempt_number', 'text_content', 'status', 'is_late', 'submitted_at', 'reviewed_at', 'reviewed_by', 'reviewer_feedback'];

    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'is_late' => 'boolean', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function assignment()
    {
        return $this->belongsTo(LessonAssignment::class, 'assignment_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class, 'submission_id');
    }
}
