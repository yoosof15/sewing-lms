<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'category', 'enabled'];

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'enabled' => 'boolean'];
    }
}
