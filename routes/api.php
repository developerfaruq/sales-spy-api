<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\User\ProfileController;



/*
|--------------------------------------------------------------------------
| API Routes — Sales-Spy
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| We add /v1 here so every endpoint becomes /api/v1/something.
|
*/

Route::prefix('v1')->group(function () {

    // Health check — no auth required
    /**
     * Health Check
     *
     * Check if the API is online. No authentication required.
     * Use this to verify the server is reachable before making other calls.
     *
     * @group General
     * @unauthenticated
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Sales-Spy API v1 is live",
     *   "data": {
     *     "version": "1.0.0",
     *     "environment": "production"
     *   }
     * }
     */
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'Sales-Spy API v1 is live',
            'data' => [
                'version' => '1.0.0',
                'environment' => app()->environment(),
            ],
        ]);
    });

    //Auth Routes (no token required)

    Route::prefix('auth')->group(function () {
        Route::post('/register',            [AuthController::class, 'register']);
        Route::post('/login',               [AuthController::class, 'login']);
        Route::get('/{provider}/redirect',  [AuthController::class, 'oauthRedirect']);
        Route::get('/{provider}/callback',  [AuthController::class, 'oauthCallback']);
    });

    // Protected Routes (token required)
    Route::middleware('auth:sanctum', 'throttle:120,1')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);



        // ─── Profile & Settings ───────────────────────────────────
        Route::prefix('user')->group(function () {

            // Profile
            Route::get('/profile',            [ProfileController::class, 'show']);
            Route::patch('/profile',          [ProfileController::class, 'update']);
            Route::post('/profile/avatar',    [ProfileController::class, 'uploadAvatar']);
            Route::delete('/profile/avatar',  [ProfileController::class, 'deleteAvatar']);

            // Password
            Route::put('/password',           [ProfileController::class, 'changePassword']);

            // Notifications
            Route::get('/notifications/preferences',  [ProfileController::class, 'getNotificationPreferences']);
            Route::put('/notifications/preferences',  [ProfileController::class, 'updateNotificationPreferences']);

            // Sessions
            Route::get('/sessions',           [ProfileController::class, 'sessions']);
            Route::delete('/sessions/{sessionId}', [ProfileController::class, 'revokeSession']);
            Route::delete('/sessions',        [ProfileController::class, 'revokeAllSessions']);
        });
    });
});
