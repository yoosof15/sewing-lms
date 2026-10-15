<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentImportRow extends Model
{
    protected $fillable = ['row_number', 'data', 'status', 'user_id', 'error_codes', 'error_message'];

    protected function casts(): array
    {
        return ['data' => 'array', 'error_codes' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StudentImportBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
