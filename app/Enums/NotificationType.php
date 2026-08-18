<?php

namespace App\Enums;

enum NotificationType: string
{
    case SCAN_COMPLETED = 'scan_completed';
    case SCAN_FAILED = 'scan_failed';
}
