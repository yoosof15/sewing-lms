<?php

namespace App\Http\Controllers;

use App\Services\ActiveSessionService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request, ActiveSessionService $sessions): Response
    {
        $user = $request->user()->load('profile', 'roles:id,name');

        return Inertia::render('Account/Profile', [
            'profile' => $this->safeProfile($user),
            'sessions' => $sessions->descriptors($user, $request->session()->getId()),
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'bio' => ['nullable', 'string', 'max:2000'],
            'teacher_specialty' => [$user->hasRole('TEACHER') ? 'nullable' : 'prohibited', 'string', 'max:180'],
            'teacher_experience_text' => [$user->hasRole('TEACHER') ? 'nullable' : 'prohibited', 'string', 'max:3000'],
        ]);
        $user->update(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'] ?? null]);
        $profile = $user->profile()->firstOrCreate();
        $profile->update(collect($data)->only(['bio', 'teacher_specialty', 'teacher_experience_text'])->all());
        $audit->record('profile.updated', $user, ['changed_fields' => array_values(array_unique([...array_keys($user->getChanges()), ...array_keys($profile->getChanges())]))]);

        return back()->with('success', 'پروفایل به‌روزرسانی شد.');
    }

    public function avatar(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', Rule::dimensions()->maxWidth(3000)->maxHeight(3000)]]);
        $new = $data['avatar']->store('avatars', 'public');
        $profile = $request->user()->profile()->firstOrCreate();
        $old = $profile->avatar_path;
        try {
            $profile->update(['avatar_path' => $new]);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($new);
            throw $exception;
        }
        if ($old && str_starts_with($old, 'avatars/')) {
            Storage::disk('public')->delete($old);
        }
        $audit->record('profile.avatar_updated', $request->user());

        return back()->with('success', 'تصویر پروفایل به‌روزرسانی شد.');
    }

    public function password(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $request->user()->update(['password' => Hash::make($data['password'])]);
        $audit->record('profile.password_changed', $request->user());

        return back()->with('success', 'رمز عبور تغییر کرد.');
    }

    private function safeProfile($user): array
    {
        return [
            'id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'name' => $user->name, 'email' => $user->email, 'mobile' => $user->mobile,
            'role' => $user->roles->first()?->name, 'is_active' => $user->is_active,
            'avatar_url' => $user->profile?->avatar_path ? Storage::disk('public')->url($user->profile->avatar_path) : null,
            'bio' => $user->profile?->bio, 'teacher_specialty' => $user->profile?->teacher_specialty,
            'teacher_experience_text' => $user->profile?->teacher_experience_text,
        ];
    }
}
