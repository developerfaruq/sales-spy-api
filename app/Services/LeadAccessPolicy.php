<?php

namespace App\Services;

use App\Models\User;

class LeadAccessPolicy
{
    /**
     * Minimum plan rank each capability requires.
     *
     * Ranks live on `plans.access_rank`, not in a slug map here. A slug map meant
     * any plan an admin created outside free/basic/pro/enterprise silently fell
     * through to free-tier access.
     */
    private const CONTACTS_RANK = 1;

    private const PRODUCTS_RANK = 1;

    private const DEEP_SCAN_RANK = 2;

    public function canViewContacts(User $user): bool
    {
        return $this->rank($user) >= self::CONTACTS_RANK;
    }

    public function canViewProducts(User $user): bool
    {
        return $this->rank($user) >= self::PRODUCTS_RANK;
    }

    public function canRequestDeepScan(User $user): bool
    {
        return $this->rank($user) >= self::DEEP_SCAN_RANK;
    }

    public function plan(User $user): string
    {
        return $user->currentPlanSlug();
    }

    /**
     * A user with no active subscription gets rank 0, the same as the free plan.
     */
    private function rank(User $user): int
    {
        return (int) ($user->activeSubscription?->plan?->access_rank ?? 0);
    }
}
