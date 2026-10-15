<?php

namespace App\Models;

use App\Enums\StudentImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentImportBatch extends Model
{
    protected $fillable = ['created_by', 'original_filename', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'created_users', 'matched_existing_users', 'created_enrollments', 'failed_rows', 'options', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['status' => StudentImportStatus::class, 'options' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(StudentImportRow::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
