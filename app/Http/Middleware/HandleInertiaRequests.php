<?php

namespace App\Http\Middleware;

use App\Models\LessonQuestion;
use App\Services\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $settings = app(SystemSettings::class);

        $qaUnanswered = $user?->hasRole('TEACHER') ? LessonQuestion::where('status', 'OPEN')->whereHas('lesson.course.teachers', fn ($query) => $query->whereKey($user->id))->count() : 0;

        return [...parent::share($request), 'branding' => ['institute_name' => $settings->string('institute_name'), 'short_title' => $settings->string('site_short_title'), 'footer_text' => $settings->string('footer_text'), 'logo_url' => $settings->string('branding_logo_path') ? Storage::disk('public')->url($settings->string('branding_logo_path')) : null], 'features' => ['lesson_qa_enabled' => $settings->boolean('lesson_qa_enabled'), 'qa_max_images' => $settings->qaMaxImages(), 'qa_max_image_kb' => $settings->qaMaxImageKb()], 'auth' => ['qa_unanswered' => $qaUnanswered, 'unread_notifications' => $user ? $user->unreadNotifications()->count() : 0, 'user' => $user ? [
            'id' => $user->id, 'name' => $user->name, 'first_name' => $user->first_name,
            'last_name' => $user->last_name, 'email' => $user->email, 'mobile' => $user->mobile,
            'role' => $user->getRoleNames()->first(),
        ] : null], 'flash' => [
            'success' => fn () => $request->session()->get('success'),
            'warning' => fn () => $request->session()->get('warning'),
            'error' => fn () => $request->session()->get('error'),
        ]];
    }
}
