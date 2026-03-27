<?php

namespace App\Services;

use App\Models\UserActivity;
use Illuminate\Http\Request;

class ActivityService
{
    /**
     * Log a user activity.
     *
     * @param int    $userId
     * @param string $type        The activity type (login, logout, profile_update, etc.)
     * @param string $description Human readable description
     * @param array  $metadata    Any extra data to store
     * @param Request|null $request  The HTTP request (for IP and user agent)
     */
    public function log(
        int $userId,
        string $type,
        string $description,
        array $metadata = [],
        ?Request $request = null
    ): UserActivity {
        return UserActivity::create([
            'user_id'     => $userId,
            'type'        => $type,
            'description' => $description,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'metadata'    => !empty($metadata) ? $metadata : null,
        ]);
    }

    /**
     * Parse a user agent string into a human-readable device name.
     * Used for the Security tab sessions list.
     */
    public function parseDevice(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);

        if (str_contains($userAgent, 'mobile') || str_contains($userAgent, 'android')) {
            return 'Mobile Device';
        }

        if (str_contains($userAgent, 'tablet') || str_contains($userAgent, 'ipad')) {
            return 'Tablet';
        }

        if (str_contains($userAgent, 'postman')) {
            return 'Postman';
        }

        if (str_contains($userAgent, 'windows')) {
            return 'Windows PC';
        }

        if (str_contains($userAgent, 'mac')) {
            return 'Mac';
        }

        if (str_contains($userAgent, 'linux')) {
            return 'Linux PC';
        }

        return 'Unknown Device';
    }
}
