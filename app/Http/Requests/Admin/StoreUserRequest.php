<?php

namespace App\Http\Requests\Admin;

use App\Support\IranianMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('mobile')) {
            try {
                $this->merge(['mobile' => IranianMobile::normalize($this->string('mobile'))]);
            } catch (\InvalidArgumentException) {
            }
        }
        if ($this->filled('email')) {
            $this->merge(['email' => strtolower($this->string('email'))]);
        }
    }

    public function rules(): array
    {
        return ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'regex:/^\+989\d{9}$/', 'unique:users,mobile'], 'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['TEACHER', 'STUDENT'])], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()], 'is_active' => ['boolean']];
    }
}
