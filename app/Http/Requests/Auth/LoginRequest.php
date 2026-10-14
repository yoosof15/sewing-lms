<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\IranianMobile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['login' => ['required', 'string'], 'password' => ['required', 'string'], 'remember' => ['boolean']];
    }

    public function authenticate(): void
    {
        $key = Str::transliterate(Str::lower($this->string('login')->toString())).'|'.$this->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));
            throw ValidationException::withMessages(['login' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        $login = $this->string('login')->toString();
        $user = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? User::where('email', Str::lower($login))->first()
            : $this->userByMobile($login);

        if (! $user || ! $user->is_active || ! $user->roles()->exists() || ! Auth::attempt(['id' => $user->id, 'password' => $this->string('password')->toString()], $this->boolean('remember'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }
        RateLimiter::clear($key);
    }

    private function userByMobile(string $mobile): ?User
    {
        try {
            return User::where('mobile', IranianMobile::normalize($mobile))->first();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
