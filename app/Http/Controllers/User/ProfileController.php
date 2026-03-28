<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\UpdateNotificationPreferencesRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Services\ActivityService;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService  $profileService,
        protected ActivityService $activityService
    ) {}

    // ─── Profile ──────────────────────────────────────────────

    /**
     * Get the authenticated user's profile.
     *
     * @authenticated
     * @group Profile
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Profile retrieved successfully",
     *   "data": {
     *     "id": 1,
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "plan": "free",
     *     "credits_balance": 500,
     *     "profile_image": "https://res.cloudinary.com/...",
     *     "email_verified": true,
     *     "is_active": true,
     *     "created_at": "2026-03-01T00:00:00.000000Z"
     *   }
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse(
            data: $this->formatUser($user),
            message: 'Profile retrieved successfully'
        );
    }

    /**
     * Update profile
     *
     * Update your name and/or email address. Only send the fields you want to change.
     *
     * @authenticated
     * @group Profile
     *
     * @bodyParam name string optional Your display name. Example: John Doe
     * @bodyParam email string optional A valid unique email address. Example: john@example.com
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Profile updated successfully",
     *   "data": {
     *     "id": 1,
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "plan": "free",
     *     "credits_balance": 500,
     *     "profile_image": null,
     *     "email_verified": true,
     *     "is_active": true,
     *     "created_at": "2026-03-01T00:00:00.000000Z"
     *   }
     * }
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profileService->updateProfile(
            $request->user(),
            $request->validated()
        );

        $this->activityService->log(
            userId: $user->id,
            type: 'profile_update',
            description: 'Updated profile information',
            request: $request
        );

        return $this->successResponse(
            data: $this->formatUser($user),
            message: 'Profile updated successfully'
        );
    }

    /**
     * Upload a new avatar image.
     *
     * @authenticated
     * @group Profile
     *
     * @bodyParam avatar file required The image file. Max 2MB. Accepted: jpg, jpeg, png, webp.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Avatar updated successfully",
     *   "data": { "profile_image": "https://res.cloudinary.com/..." }
     * }
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $user = $this->profileService->updateAvatar(
            $request->user(),
            $request->file('avatar')
        );

        $this->activityService->log(
            userId: $user->id,
            type: 'profile_update',
            description: 'Updated profile avatar',
            request: $request
        );

        return $this->successResponse(
            data: ['profile_image' => $user->profile_image_url],
            message: 'Avatar updated successfully'
        );
    }

    /**
     * Delete the user's avatar.
     *
     * @authenticated
     * @group Profile
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Avatar removed successfully",
     *   "data": null
     * }
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $this->profileService->deleteAvatar($request->user());

        return $this->successResponse(
            message: 'Avatar removed successfully'
        );
    }

    // ─── Password ─────────────────────────────────────────────

    /**
     * Change the authenticated user's password.
     *
     * @authenticated
     * @group Profile
     *
     * @bodyParam current_password string required Your current password. Example: oldpassword123
     * @bodyParam new_password string required Min 8 characters. Example: newpassword123
     * @bodyParam new_password_confirmation string required Must match new_password. Example: newpassword123
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Password changed successfully",
     *   "data": null
     * }
     *
     * @response 422 {
     *   "success": false,
     *   "message": "Current password is incorrect",
     *   "errors": null
     * }
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $changed = $this->profileService->changePassword(
            $request->user(),
            $request->current_password,
            $request->new_password
        );

        if (!$changed) {
            return $this->errorResponse(
                message: 'Current password is incorrect',
                statusCode: 422
            );
        }

        // Revoke all other tokens after password change
        // Forces other devices to log in again
        $currentToken = $request->user()->currentAccessToken();
        $request->user()->tokens()
            ->where('id', '!=', $currentToken->id)
            ->delete();

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'password_change',
            description: 'Changed account password',
            request: $request
        );

        return $this->successResponse(
            message: 'Password changed successfully'
        );
    }

    // ─── Notifications ────────────────────────────────────────

    /**
     * Get the user's notification preferences.
     *
     * @authenticated
     * @group Notifications
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Notification preferences retrieved",
     *   "data": {
     *     "email_on_export_complete": true,
     *     "email_on_billing": true,
     *     "email_on_new_features": true,
     *     "email_on_security_alerts": true,
     *     "inapp_on_export_complete": true,
     *     "inapp_on_low_credits": true,
     *     "inapp_on_scan_complete": true
     *   }
     * }
     */
    public function getNotificationPreferences(Request $request): JsonResponse
    {
        $preferences = $this->profileService->getNotificationPreferences(
            $request->user()
        );

        return $this->successResponse(
            data: $this->formatPreferences($preferences),
            message: 'Notification preferences retrieved'
        );
    }

    /**
     * Update the user's notification preferences.
     *
     * @authenticated
     * @group Notifications
     *
     * @bodyParam email_on_export_complete boolean optional Example: true
     * @bodyParam email_on_billing boolean optional Example: false
     * @bodyParam email_on_new_features boolean optional Example: true
     * @bodyParam email_on_security_alerts boolean optional Example: true
     * @bodyParam inapp_on_export_complete boolean optional Example: true
     * @bodyParam inapp_on_low_credits boolean optional Example: true
     * @bodyParam inapp_on_scan_complete boolean optional Example: false
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Notification preferences updated",
     *   "data": { "email_on_billing": false }
     * }
     */
    public function updateNotificationPreferences(
        UpdateNotificationPreferencesRequest $request
    ): JsonResponse {
        $preferences = $this->profileService->updateNotificationPreferences(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse(
            data: $this->formatPreferences($preferences),
            message: 'Notification preferences updated'
        );
    }

    // ─── Sessions ─────────────────────────────────────────────

    /**
     * List all active sessions for the authenticated user.
     *
     * @authenticated
     * @group Security
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Sessions retrieved successfully",
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "auth-token",
     *       "last_used_at": "2026-03-25T09:00:00.000000Z",
     *       "created_at": "2026-03-24T08:00:00.000000Z",
     *       "is_current": true
     *     }
     *   ]
     * }
     */
    public function sessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $sessions = $request->user()->tokens()
            ->get()
            ->map(function ($token) use ($currentTokenId) {
                return [
                    'id'           => $token->id,
                    'name'         => $token->name,
                    'last_used_at' => $token->last_used_at,
                    'created_at'   => $token->created_at,
                    'is_current'   => $token->id === $currentTokenId,
                ];
            });

        return $this->successResponse(
            data: $sessions,
            message: 'Sessions retrieved successfully'
        );
    }

    /**
     * Revoke a specific session by token ID.
     *
     * @authenticated
     * @group Security
     *
     * @urlParam sessionId integer required The token ID to revoke. Example: 3
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Session revoked successfully",
     *   "data": null
     * }
     *
     * @response 404 {
     *   "success": false,
     *   "message": "Session not found",
     *   "errors": null
     * }
     */
    public function revokeSession(Request $request, int $sessionId): JsonResponse
    {
        $token = $request->user()->tokens()->find($sessionId);

        if (!$token) {
            return $this->errorResponse(
                message: 'Session not found',
                statusCode: 404
            );
        }

        $token->delete();

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'session_revoked',
            description: 'Revoked a session',
            request: $request
        );

        return $this->successResponse(
            message: 'Session revoked successfully'
        );
    }

    /**
     * Revoke all sessions except the current one.
     *
     * @authenticated
     * @group Security
     *
     * @response 200 {
     *   "success": true,
     *   "message": "All other sessions revoked",
     *   "data": null
     * }
     */
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $request->user()->tokens()
            ->where('id', '!=', $currentTokenId)
            ->delete();

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'session_revoked',
            description: 'Revoked all other sessions',
            request: $request
        );

        return $this->successResponse(
            message: 'All other sessions revoked'
        );
    }

    // ─── Private Helpers ──────────────────────────────────────

    private function formatUser($user): array
    {
        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'plan'            => $user->plan,
            'credits_balance' => $user->credits_balance,
            'profile_image'   => $user->profile_image_url,
            'email_verified'  => !is_null($user->email_verified_at),
            'is_active'       => $user->is_active,
            'created_at'      => $user->created_at,
        ];
    }

    private function formatPreferences($preferences): array
    {
        return [
            'email_on_export_complete' => $preferences->email_on_export_complete,
            'email_on_billing'         => $preferences->email_on_billing,
            'email_on_new_features'    => $preferences->email_on_new_features,
            'email_on_security_alerts' => $preferences->email_on_security_alerts,
            'inapp_on_export_complete' => $preferences->inapp_on_export_complete,
            'inapp_on_low_credits'     => $preferences->inapp_on_low_credits,
            'inapp_on_scan_complete'   => $preferences->inapp_on_scan_complete,
        ];
    }
}
