<?php

namespace App\Http\Middleware;

use App\Models\LessonQuestion;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        $qaUnanswered = $user?->hasRole('TEACHER') ? LessonQuestion::where('status', 'OPEN')->whereHas('lesson.course.teachers', fn ($query) => $query->whereKey($user->id))->count() : 0;

        return [...parent::share($request), 'auth' => ['qa_unanswered' => $qaUnanswered, 'unread_notifications' => $user ? $user->unreadNotifications()->count() : 0, 'user' => $user ? [
            'id' => $user->id, 'name' => $user->name, 'first_name' => $user->first_name,
            'last_name' => $user->last_name, 'email' => $user->email, 'mobile' => $user->mobile,
            'role' => $user->getRoleNames()->first(),
        ] : null], 'flash' => ['success' => fn () => $request->session()->get('success')]];
    }
}
