<?php

namespace App\Http\Requests;

use App\Enums\LessonStatus;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson ? ($this->user()?->can('update', $lesson) ?? false) : Gate::forUser($this->user())->allows('create', [Lesson::class, $this->route('course')]);
    }

    public function rules(): array
    {
        $course = $this->route('course');
        $lesson = $this->route('lesson');

        return [
            'title' => ['required', 'string', 'max:180'],
            'content' => ['nullable', 'string', 'max:100000'],
            'status' => ['required', Rule::enum(LessonStatus::class)],
            'section_id' => ['nullable', 'integer', Rule::exists(CourseSection::class, 'id')->where(fn ($q) => $q->where('course_id', $course->id)->whereNull('deleted_at'))],
            'available_at' => ['nullable', 'date'],
            'prerequisite_ids' => ['nullable', 'array'],
            'prerequisite_ids.*' => ['integer', 'distinct', Rule::exists('lessons', 'id')->where(fn ($q) => $q->where('course_id', $course->id)->whereNull('deleted_at')), Rule::notIn(array_filter([$lesson?->id]))],
        ];
    }
}
