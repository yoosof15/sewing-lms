<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubmissionFile extends Model
{
    use SoftDeletes;

    protected $fillable = ['submission_id', 'uploaded_by', 'original_name', 'storage_path', 'mime_type', 'file_size'];

    public function submission()
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }
}
