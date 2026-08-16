<?php

namespace App\Enums;

enum WebsiteStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PARKED = 'parked';
    case UNREACHABLE = 'unreachable';
    case UNKNOWN = 'unknown';
}
