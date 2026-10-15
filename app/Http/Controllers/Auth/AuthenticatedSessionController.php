<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\ActiveSessionService;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, ActiveSessionService $sessions, SystemSettings $settings): RedirectResponse
    {
        $request->authenticate();
        $limit = $settings->integer('max_active_sessions');
        if ($limit > 0 && $sessions->count($request->user(), $request->session()->getId()) >= $limit) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['login' => 'تعداد نشست‌های فعال حساب شما به حد مجاز رسیده است.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route(strtolower($request->user()->getRoleNames()->first()).'.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
