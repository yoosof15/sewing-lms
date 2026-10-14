<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActiveSessionController extends Controller
{
    public function index(Request $request, ActiveSessionService $sessions): Response
    {
        return Inertia::render('Account/Sessions', ['sessions' => $sessions->descriptors($request->user(), $request->session()->getId()), 'subject' => null]);
    }

    public function destroy(Request $request, string $token, ActiveSessionService $sessions, AuditService $audit): RedirectResponse
    {
        abort_unless($sessions->revoke($request->user(), $token, $request->session()->getId()), 422, 'Current or unknown session cannot be revoked.');
        $audit->record('session.revoked_self', $request->user());

        return back()->with('success', 'نشست انتخاب‌شده لغو شد.');
    }

    public function adminIndex(Request $request, User $user, ActiveSessionService $sessions): Response
    {
        return Inertia::render('Account/Sessions', ['sessions' => $sessions->descriptors($user, $request->session()->getId()), 'subject' => ['id' => $user->id, 'name' => $user->name]]);
    }

    public function adminDestroy(User $user, string $token, ActiveSessionService $sessions, AuditService $audit): RedirectResponse
    {
        abort_unless($sessions->revoke($user, $token), 404);
        $audit->record('session.revoked_by_admin', $user);

        return back()->with('success', 'نشست کاربر لغو شد.');
    }
}
