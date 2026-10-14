<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Support\UniqueSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['category_id', 'title', 'short_description', 'description', 'cover_image', 'status', 'published_at', 'completion_threshold_percent'];

    protected function casts(): array
    {
        return ['status' => CourseStatus::class, 'published_at' => 'datetime', 'completion_threshold_percent' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Course $course) => $course->slug ??= UniqueSlug::make(self::class, $course->title, 'course'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teacher')->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }
}
