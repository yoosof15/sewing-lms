<?php

namespace App\Http\Requests\Admin;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Course::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['category_id' => $this->input('category_id') ?: null, 'teacher_ids' => $this->input('teacher_ids', [])]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'category_id' => ['nullable', Rule::exists('course_categories', 'id')->whereNull('deleted_at')],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:30000'],
            'status' => ['required', Rule::enum(CourseStatus::class)],
            'teacher_ids' => ['array'],
            'teacher_ids.*' => ['integer', 'distinct', function (string $attribute, mixed $value, \Closure $fail) {
                if (! User::query()->whereKey($value)->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'TEACHER'))->exists()) {
                    $fail('مدرس انتخاب‌شده معتبر نیست.');
                }
            }],
            'cover_image' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(2 * 1024)->dimensions(Rule::dimensions()->maxWidth(4000)->maxHeight(4000))],
        ];
    }
}
