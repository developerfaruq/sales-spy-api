<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING               = 'pending';
    case AWAITING_VERIFICATION = 'awaiting_verification';
    case APPROVED              = 'approved';
    case REJECTED              = 'rejected';
    case EXPIRED               = 'expired';

    /**
     * Statuses where the user can still take action.
     */
    public function isActionable(): bool
    {
        return in_array($this, [self::PENDING]);
    }

    /**
     * Statuses where the order is fully resolved.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::REJECTED,
            self::EXPIRED,
        ]);
    }
}
