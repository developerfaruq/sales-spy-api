<?php

namespace App\Services;

use App\Models\User;

class LeadAccessPolicy
{
    private const RANKS = [
        'free' => 0,
        'basic' => 1,
        'pro' => 2,
        'enterprise' => 3,
    ];

    public function canViewContacts(User $user): bool
    {
        return $this->rank($user) >= self::RANKS['basic'];
    }

    public function canViewProducts(User $user): bool
    {
        return $this->rank($user) >= self::RANKS['basic'];
    }

    public function canRequestDeepScan(User $user): bool
    {
        return $this->rank($user) >= self::RANKS['pro'];
    }

    public function plan(User $user): string
    {
        return $user->currentPlanSlug();
    }

    private function rank(User $user): int
    {
        return self::RANKS[$this->plan($user)] ?? self::RANKS['free'];
    }
}
