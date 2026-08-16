<?php

namespace App\Services;

use App\Models\User;

/**
 * Read-side notification operations.
 *
 * Scan notifications are written by the Python worker inside the same
 * transaction that settles the scan, so there is deliberately no creation
 * method here. Adding one would duplicate that rule across two services.
 */
class NotificationService
{
    public function unreadCount(User $user): int
    {
        return $user->inAppNotifications()->whereNull('read_at')->count();
    }

    public function markRead(User $user, string $notificationId): bool
    {
        return $user->inAppNotifications()
            ->whereKey($notificationId)
            ->update(['read_at' => now()]) > 0;
    }

    public function markAllRead(User $user): int
    {
        return $user->inAppNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
