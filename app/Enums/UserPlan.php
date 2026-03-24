<?php

namespace App\Enums;

enum UserPlan: string
{
    case FREE       = 'free';
    case BASIC      = 'basic';
    case PRO        = 'pro';
    case ENTERPRISE = 'enterprise';
}
