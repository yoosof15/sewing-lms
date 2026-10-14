<?php

namespace Database\Seeders;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Database\Seeder;
use RuntimeException;

class NotificationDemoSeeder extends Seeder
{
    public function run(InAppNotificationService $notifications): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('NotificationDemoSeeder may run only locally.');
        }
        User::query()->where('is_active', true)->limit(5)->get()->each(fn ($user) => $notifications->send($user, 'demo:welcome', NotificationCategory::CourseAccess, ['title' => 'خوش آمدید', 'message' => 'مرکز اعلان‌های داخلی آموزشگاه فعال است.', 'action_url' => route('notifications.index')]));
    }
}
