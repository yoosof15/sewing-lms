<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Database\QueryException;

class InAppNotificationService
{
    public function send(User $user, string $eventKey, NotificationCategory $category, array $payload): void
    {
        if (! $user->is_active || (! $category->mandatory() && NotificationPreference::where('user_id', $user->id)->where('category', $category->value)->where('enabled', false)->exists())) {
            return;
        }
        $hash = md5($eventKey.':'.$user->id);
        $id = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
        $safe = ['category' => $category->value, 'title' => mb_substr((string) $payload['title'], 0, 180), 'message' => mb_substr((string) $payload['message'], 0, 500), 'action_url' => isset($payload['action_url']) ? mb_substr((string) $payload['action_url'], 0, 500) : null, 'entity_type' => $payload['entity_type'] ?? null, 'entity_id' => $payload['entity_id'] ?? null];
        try {
            if (! $user->notifications()->whereKey($id)->exists()) {
                $user->notifyNow(new InAppNotification($safe, $id));
            }
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
        }
    }
}
