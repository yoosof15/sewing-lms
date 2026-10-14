<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageCleanupTask extends Model
{
    protected $fillable = ['resource_type', 'resource_id', 'status', 'attempts', 'last_error', 'completed_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'completed_at' => 'datetime'];
    }
}
