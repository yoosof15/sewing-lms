<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\NotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $items = $request->user()->notifications()->latest()->paginate(15);
        $preferences = collect(NotificationCategory::cases())->reject->mandatory()->map(fn ($category) => ['category' => $category->value, 'label' => $category->label(), 'enabled' => ! NotificationPreference::where('user_id', $request->user()->id)->where('category', $category->value)->where('enabled', false)->exists()])->values();

        return Inertia::render('Notifications/Index', ['notifications' => $items, 'preferences' => $preferences]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'همه اعلان‌ها خوانده شدند.');
    }

    public function preference(Request $request): RedirectResponse
    {
        $data = $request->validate(['category' => ['required', 'in:NEW_LESSON,NEW_VIDEO'], 'enabled' => ['required', 'boolean']]);
        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id, 'category' => $data['category']], ['enabled' => $data['enabled']]);

        return back();
    }
}
