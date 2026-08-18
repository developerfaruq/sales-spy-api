<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustCreditsRequest;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(
        protected AdminUserService $userService
    ) {}

    /**
     * List all registered users
     *
     * Returns a paginated list of all users with their
     * subscription status and plan details.
     * Admin access required.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @queryParam page integer Page number. Example: 1
     * @queryParam per_page integer Results per page, max 100. Example: 25
     * @queryParam search string Search by name or email. Example: john
     * @queryParam plan string Filter by plan slug. Example: pro
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Users retrieved successfully",
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "John Doe",
     *       "email": "john@example.com",
     *       "plan": "pro",
     *       "credits_balance": 2000,
     *       "is_active": true,
     *       "email_verified": true,
     *       "subscription_status": "active",
     *       "subscription_ends": "2026-04-25T00:00:00.000000Z",
     *       "registered_at": "2026-03-01T00:00:00.000000Z"
     *     }
     *   ],
     *   "meta": {
     *     "current_page": 1,
     *     "last_page": 5,
     *     "per_page": 25,
     *     "total": 120
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 25), 100);

        $query = User::with(['activeSubscription.plan', 'roles'])
            ->latest();

        // Search by name or email
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by plan
        if ($plan = $request->get('plan')) {
            $query->whereHas('activeSubscription.plan', function ($q) use ($plan) {
                $q->where('slug', $plan);
            });
        }

        $users = $query->paginate($perPage);

        return $this->successResponse(
            data: $users->map(fn ($user) => $this->formatUser($user)),
            message: 'Users retrieved successfully',
            meta: [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        );
    }

    /**
     * Get a single user's details
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "message": "User retrieved successfully",
     *   "data": {
     *     "id": 1,
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "plan": "pro",
     *     "credits_balance": 2000,
     *     "is_active": true,
     *     "subscription_status": "active",
     *     "subscription_ends": "2026-04-25T00:00:00.000000Z",
     *     "registered_at": "2026-03-01T00:00:00.000000Z"
     *   }
     * }
     */
    public function show(Request $request, int $userId): JsonResponse
    {
        $user = User::with(['activeSubscription.plan', 'roles'])
            ->find($userId);

        if (! $user) {
            return $this->errorResponse(
                message: 'User not found.',
                statusCode: 404
            );
        }

        return $this->successResponse(
            data: $this->formatUser($user),
            message: 'User retrieved successfully'
        );
    }

    /**
     * Toggle a user's active status
     *
     * Activate or deactivate a user account.
     * Deactivated users cannot log in.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "message": "User deactivated successfully",
     *   "data": { "is_active": false }
     * }
     */
    public function toggleStatus(Request $request, int $userId): JsonResponse
    {
        // Prevent admin from deactivating their own account
        if ($userId === $request->user()->id) {
            return $this->errorResponse(
                message: 'You cannot deactivate your own account.',
                statusCode: 400
            );
        }

        $user = User::find($userId);

        if (! $user) {
            return $this->errorResponse(
                message: 'User not found.',
                statusCode: 404
            );
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        $status = $user->is_active ? 'activated' : 'deactivated';

        return $this->successResponse(
            data: ['is_active' => $user->is_active],
            message: "User {$status} successfully"
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Private Helpers
    // ─────────────────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        $subscription = $user->activeSubscription;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values(),
            'plan' => $user->currentPlanSlug(),
            'credits_balance' => $user->credits_balance,
            'is_active' => $user->is_active,
            'email_verified' => ! is_null($user->email_verified_at),
            'subscription_status' => $subscription?->status->value ?? 'none',
            'subscription_ends' => $subscription?->current_period_end,
            'registered_at' => $user->created_at,
        ];
    }

    // DELETE /api/v1/admin/users/{userId}

    /**
     * Delete a user
     *
     * Soft delete: the row is retained because `payment_orders`,
     * `credit_transactions` and `subscriptions` reference it and are financial
     * records. Access ends immediately — all tokens are revoked and the account is
     * deactivated, and the SoftDeletes global scope stops the user resolving from
     * any future token.
     *
     * Refused with 409 when deleting yourself or the last remaining admin, so the
     * organisation cannot lock itself out.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user id. Example: 12
     *
     * @response 200 {"success": true, "message": "User deleted successfully", "data": null}
     * @response 409 {"success": false, "message": "You cannot delete your own account.", "errors": null}
     * @response 404 {"success": false, "message": "User not found.", "errors": null}
     */
    public function destroy(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return $this->errorResponse(message: 'User not found.', statusCode: 404);
        }

        if ($reason = $this->userService->delete($request->user(), $user, $request)) {
            return $this->errorResponse(message: $reason, statusCode: 409);
        }

        return $this->successResponse(message: 'User deleted successfully');
    }

    // POST /api/v1/admin/users/{userId}/restore

    /**
     * Restore a deleted user
     *
     * The account is restored but stays deactivated, so restoring never silently
     * hands access back. Reactivate it separately with the toggle-status endpoint.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user id. Example: 12
     *
     * @response 200 {
     *   "success": true,
     *   "message": "User restored successfully",
     *   "data": {"id": 12, "is_active": false}
     * }
     * @response 404 {"success": false, "message": "Deleted user not found.", "errors": null}
     */
    public function restore(Request $request, int $userId): JsonResponse
    {
        $user = User::onlyTrashed()->find($userId);

        if (! $user) {
            return $this->errorResponse(message: 'Deleted user not found.', statusCode: 404);
        }

        $this->userService->restore($request->user(), $user, $request);

        return $this->successResponse(
            data: ['id' => $user->id, 'is_active' => $user->fresh()->is_active],
            message: 'User restored successfully'
        );
    }

    // POST /api/v1/admin/users/{userId}/admin

    /**
     * Grant the admin role
     *
     * This is how admins are provisioned. The first admin has to be created out of
     * band (`php artisan tinker` then `$user->assignRole('admin')`); after that,
     * existing admins promote others through this endpoint.
     *
     * Roles are registered against the `api` guard, matching `User::$guard_name`.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user id. Example: 12
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Admin role granted successfully",
     *   "data": {"id": 12, "roles": ["user", "admin"]}
     * }
     * @response 409 {"success": false, "message": "A deactivated user cannot be promoted to admin.", "errors": null}
     */
    public function grantAdmin(Request $request, int $userId): JsonResponse
    {
        return $this->changeAdminRole($request, $userId, true);
    }

    // DELETE /api/v1/admin/users/{userId}/admin

    /**
     * Revoke the admin role
     *
     * Revoking signs the target out of all sessions. Refused with 409 when
     * demoting yourself or the last remaining admin.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user id. Example: 12
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Admin role revoked successfully",
     *   "data": {"id": 12, "roles": ["user"]}
     * }
     * @response 409 {
     *   "success": false,
     *   "message": "This is the last remaining admin, so it cannot be demoted.",
     *   "errors": null
     * }
     */
    public function revokeAdmin(Request $request, int $userId): JsonResponse
    {
        return $this->changeAdminRole($request, $userId, false);
    }

    // POST /api/v1/admin/users/{userId}/credits

    /**
     * Grant credits to a user
     *
     * Writes an `admin_adjustment` ledger entry through CreditService, so the
     * balance and the ledger stay reconcilable. A reason is required and is stored
     * on both the transaction and the audit log.
     *
     * Grants only — to reduce an allowance, change the user's plan instead.
     * Users on an unlimited plan record the adjustment but keep a zero balance.
     *
     * @authenticated
     *
     * @group Admin — Users
     *
     * @urlParam userId integer required The user id. Example: 12
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Credits granted successfully",
     *   "data": {"user_id": 12, "amount": 100, "credits_balance": 150, "transaction_id": 842}
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {"amount": ["Amount must be a positive number of credits to grant."]}
     * }
     */
    public function adjustCredits(AdjustCreditsRequest $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return $this->errorResponse(message: 'User not found.', statusCode: 404);
        }

        return $this->successResponse(
            data: $this->userService->adjustCredits(
                $request->user(),
                $user,
                (int) $request->validated('amount'),
                $request->validated('reason'),
                $request
            ),
            message: 'Credits granted successfully'
        );
    }

    private function changeAdminRole(Request $request, int $userId, bool $grant): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return $this->errorResponse(message: 'User not found.', statusCode: 404);
        }

        if ($reason = $this->userService->setAdminRole($request->user(), $user, $grant, $request)) {
            return $this->errorResponse(message: $reason, statusCode: 409);
        }

        return $this->successResponse(
            data: [
                'id' => $user->id,
                'roles' => $user->fresh()->getRoleNames()->values(),
            ],
            message: 'Admin role '.($grant ? 'granted' : 'revoked').' successfully'
        );
    }
}
