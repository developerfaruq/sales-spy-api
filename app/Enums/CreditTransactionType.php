<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case OPENING_BALANCE = 'opening_balance';
    case SUBSCRIPTION_GRANT = 'subscription_grant';
    case MONTHLY_RESET = 'monthly_reset';
    case SPEND = 'spend';
    case REFUND = 'refund';
    case ADMIN_ADJUSTMENT = 'admin_adjustment';
}
