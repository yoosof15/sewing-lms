<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['course_id', 'title', 'content', 'sort_order', 'status', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return ['status' => LessonStatus::class, 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Lesson $lesson) {
            $base = Str::slug($lesson->title) ?: 'lesson-'.Str::lower((string) Str::ulid());
            $slug = $base;
            $i = 2;
            while (self::withTrashed()->where('course_id', $lesson->course_id)->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }
            $lesson->slug = $slug;
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(LessonVideo::class)->orderBy('sort_order');
    }
}
