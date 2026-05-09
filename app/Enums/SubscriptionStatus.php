<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case ACTIVE          = 'active';
    case CANCELLED       = 'cancelled';
    case EXPIRED         = 'expired';
    case PENDING_PAYMENT = 'pending_payment';
    case TRIAL           = 'trial';

    /**
     * Returns true if this status means the user has access.
     */
    public function hasAccess(): bool
    {
        return in_array($this, [
            self::ACTIVE,
            self::TRIAL,
        ]);
    }
}
