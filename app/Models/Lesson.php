<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['course_id', 'section_id', 'title', 'content', 'sort_order', 'status', 'published_at', 'available_at', 'created_by'];

    protected function casts(): array
    {
        return ['status' => LessonStatus::class, 'published_at' => 'datetime', 'available_at' => 'datetime'];
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'lesson_prerequisites', 'lesson_id', 'prerequisite_lesson_id')->withTimestamps();
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'lesson_prerequisites', 'prerequisite_lesson_id', 'lesson_id')->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(LessonVideo::class)->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LessonQuestion::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LessonAssignment::class);
    }
}
