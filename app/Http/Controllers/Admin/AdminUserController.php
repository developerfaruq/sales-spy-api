<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
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
}
