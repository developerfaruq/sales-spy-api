<?php

use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminLeadController;
use App\Http\Controllers\Admin\AdminMetricsController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Lead\EcommerceController;
use App\Http\Controllers\Lead\WebsiteController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Store\PlanController;
use App\Http\Controllers\User\CreditController;
use App\Http\Controllers\User\NotificationController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\StoreScanController;
use Illuminate\Support\Facades\Route;

/*
| API Routes — Sales-Spy
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| We add /v1 here so every endpoint becomes /api/v1/something.
|
*/

Route::prefix('v1')->group(function () {

    // ─── Admin Routes ─────────────────────────────────────────────
    // Requires both a valid token AND the admin role
    Route::middleware(['auth:sanctum', 'active', 'admin', 'throttle:60,1'])
        ->prefix('admin')
        ->group(function () {

            // Users
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{userId}', [AdminUserController::class, 'show']);
            Route::patch('/users/{userId}/toggle-status', [AdminUserController::class, 'toggleStatus']);

            Route::get('/payments', [AdminPaymentController::class, 'index']);
            Route::put('/payments/{orderId}/review', [AdminPaymentController::class, 'review'])
                ->whereNumber('orderId');

            // Dashboard
            Route::get('/metrics', [AdminMetricsController::class, 'summary']);
            Route::get('/metrics/pipeline', [AdminMetricsController::class, 'pipeline']);
            Route::get('/activities', [AdminActivityController::class, 'index']);

            // Lead pipeline control
            Route::post('/leads/enqueue', [AdminLeadController::class, 'enqueue']);
            Route::post('/leads/{domain}/recrawl', [AdminLeadController::class, 'recrawl']);
        });

    // Plans — public
    Route::get('/plans', [PlanController::class, 'index']);
    /**
     * Health Check
     *
     * Check if the API is online. No authentication required.
     * Use this to verify the server is reachable before making other calls.
     *
     * @group General
     *
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

    // Auth Routes (no token required)

    // Email verification. The verify route is opened from a mail client, so it
    // is public and relies on the signature rather than a bearer token. It must
    // be declared before the {provider} wildcards below, which would otherwise
    // swallow /auth/email/verify/... on GET.
    Route::get('/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:10,1'])
        ->name('verification.verify');

    Route::post('/auth/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth:sanctum', 'active', 'throttle:email-verification']);

    // Password reset sends email, so it gets its own limiter rather than
    // sharing the 20/min one with login and register. The named limiter keys on
    // both client IP and submitted email; see AppServiceProvider::boot(). This
    // sits before the {provider} routes below so those wildcards cannot shadow it.
    Route::middleware('throttle:password-reset')->prefix('auth')->group(function () {
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('throttle:20,1')->prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::get('/{provider}/redirect', [AuthController::class, 'oauthRedirect']);
        Route::get('/{provider}/callback', [AuthController::class, 'oauthCallback']);
    });

    // Protected Routes (token required)
    Route::middleware('auth:sanctum', 'active', 'throttle:120,1')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/websites', [WebsiteController::class, 'index']);
        Route::get('/websites/{domain}', [WebsiteController::class, 'show']);

        Route::get('/ecommerce', [EcommerceController::class, 'index']);
        Route::get('/ecommerce/{domain}', [EcommerceController::class, 'show']);
        Route::get('/ecommerce/{domain}/products', [EcommerceController::class, 'products']);
        Route::post('/ecommerce/{domain}/scan', [EcommerceController::class, 'scan']);
        Route::get('/user/scans/{scanRequestId}', [StoreScanController::class, 'show']);

        Route::get('/user/subscription', [PlanController::class, 'currentSubscription']);
        Route::post('/user/subscription/cancel', [PlanController::class, 'cancel']);

        // ─── Profile & Settings ───────────────────────────────────
        Route::prefix('user')->group(function () {

            // Profile
            Route::get('/profile', [ProfileController::class, 'show']);
            Route::patch('/profile', [ProfileController::class, 'update']);
            Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
            Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar']);

            // Password
            Route::put('/password', [ProfileController::class, 'changePassword']);

            // Notifications
            Route::get('/notifications/preferences', [ProfileController::class, 'getNotificationPreferences']);
            Route::put('/notifications/preferences', [ProfileController::class, 'updateNotificationPreferences']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

            Route::get('/credits', [CreditController::class, 'show']);
            Route::get('/credits/history', [CreditController::class, 'history']);

            Route::prefix('payments')->group(function () {
                Route::get('/', [PaymentController::class, 'index']);
                Route::post('/initiate', [PaymentController::class, 'initiate']);
                Route::get('/{orderId}', [PaymentController::class, 'show'])
                    ->whereNumber('orderId');
                Route::post('/{orderId}/proof', [PaymentController::class, 'uploadProof']);
                Route::post('/{orderId}/txid', [PaymentController::class, 'submitTxid']);
            });

            // Sessions
            Route::get('/sessions', [ProfileController::class, 'sessions']);
            Route::delete('/sessions/{sessionId}', [ProfileController::class, 'revokeSession']);
            Route::delete('/sessions', [ProfileController::class, 'revokeAllSessions']);
        });
    });
});
