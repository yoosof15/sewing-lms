<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\NotificationPreference;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, SystemSettings $settings): Response
    {
        $items = $request->user()->notifications()->latest()->paginate(15);
        $preferences = collect(NotificationCategory::cases())->reject->mandatory()->map(function ($category) use ($request, $settings) {
            $explicit = NotificationPreference::where('user_id', $request->user()->id)->where('category', $category->value)->first();
            $defaultKey = ['NEW_LESSON' => 'default_notify_new_lesson', 'NEW_VIDEO' => 'default_notify_new_video', 'ASSIGNMENT' => 'default_notify_assignment', 'LESSON_QA' => 'default_notify_lesson_qa'][$category->value];

            return ['category' => $category->value, 'label' => $category->label(), 'enabled' => $explicit?->enabled ?? $settings->boolean($defaultKey)];
        })->values();

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
        $data = $request->validate(['category' => ['required', 'in:NEW_LESSON,NEW_VIDEO,LESSON_QA,ASSIGNMENT'], 'enabled' => ['required', 'boolean']]);
        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id, 'category' => $data['category']], ['enabled' => $data['enabled']]);

        return back();
    }
}
