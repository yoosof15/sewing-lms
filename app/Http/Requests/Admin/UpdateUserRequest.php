<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\IranianMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
        /** @var User $managed */ $managed = $this->route('user');
        $roles = $managed->hasRole('ADMIN') ? ['ADMIN'] : ['TEACHER', 'STUDENT'];

        return ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'regex:/^\+989\d{9}$/', Rule::unique('users')->ignore($managed)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($managed)], 'role' => ['required', Rule::in($roles)],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()], 'is_active' => ['required', 'boolean']];
    }
}
