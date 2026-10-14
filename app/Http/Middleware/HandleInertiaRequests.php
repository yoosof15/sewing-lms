<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [...parent::share($request), 'auth' => ['unread_notifications' => $user ? $user->unreadNotifications()->count() : 0, 'user' => $user ? [
            'id' => $user->id, 'name' => $user->name, 'first_name' => $user->first_name,
            'last_name' => $user->last_name, 'email' => $user->email, 'mobile' => $user->mobile,
            'role' => $user->getRoleNames()->first(),
        ] : null], 'flash' => ['success' => fn () => $request->session()->get('success')]];
    }
}
