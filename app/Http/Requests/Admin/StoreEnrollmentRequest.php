<?php

namespace App\Http\Requests\Admin;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        return ['student_ids' => ['required', 'array', 'min:1'], 'student_ids.*' => ['integer', 'distinct', function ($attribute, $value, $fail) {
            if (! User::whereKey($value)->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'STUDENT'))->exists()) {
                $fail('هنرجوی انتخاب‌شده معتبر نیست.');
            }
        }], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at']];
    }

    public function after(): array
    {
        return [function ($validator) { /** @var Course $course */ $course = $this->route('course');
            if ($course->enrollments()->whereIn('student_id', $this->input('student_ids', []))->exists()) {
                $validator->errors()->add('student_ids', 'ثبت‌نام تکراری مجاز نیست.');
            }
        }];
    }
}
