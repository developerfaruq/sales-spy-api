<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    // ─────────────────────────────────────────────────────────────
    //  Public Endpoints
    // ─────────────────────────────────────────────────────────────

    /**
     * List all available plans
     *
     * Returns all active plans with prices and features.
     * Public endpoint — no authentication required.
     * The frontend uses this to populate the pricing page dynamically.
     *
     * @unauthenticated
     * @group Plans
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Plans retrieved successfully",
     *   "data": [
     *     {
     *       "slug": "basic",
     *       "name": "Basic",
     *       "monthly_price_usd": 115.00,
     *       "yearly_price_usd": 1104.00,
     *       "monthly_quota": 500,
     *       "features": ["500 leads per month", "Basic filtering options"],
     *       "is_popular": false
     *     }
     *   ]
     * }
     */
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn(Plan $plan) => $this->formatPlan($plan));

        return $this->successResponse(
            data: $plans,
            message: 'Plans retrieved successfully'
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Protected Endpoints
    // ─────────────────────────────────────────────────────────────

    /**
     * Get current subscription
     *
     * Returns the authenticated user's active subscription
     * including plan details, billing cycle, and period end date.
     *
     * @authenticated
     * @group Plans
     *
     * @response 200 scenario="Active subscription" {
     *   "success": true,
     *   "message": "Subscription retrieved successfully",
     *   "data": {
     *     "plan": {
     *       "slug": "pro",
     *       "name": "Pro",
     *       "monthly_price_usd": 225.00,
     *       "yearly_price_usd": 2160.00,
     *       "monthly_quota": 2000,
     *       "features": ["2,000 leads per month", "Priority support"],
     *       "is_popular": true
     *     },
     *     "status": "active",
     *     "billing_cycle": "monthly",
     *     "current_period_end": "2026-04-25T00:00:00.000000Z",
     *     "cancelled_at": null
     *   }
     * }
     *
     * @response 200 scenario="No subscription" {
     *   "success": true,
     *   "message": "No active subscription found",
     *   "data": null
     * }
     */
    public function currentSubscription(Request $request): JsonResponse
    {
        $subscription = $request->user()
            ?->activeSubscription()
            ?->with('plan')
            ?->first();

        if (! $subscription) {
            return $this->successResponse(
                data: null,
                message: 'No active subscription found'
            );
        }

        return $this->successResponse(
            data: $this->formatSubscription($subscription),
            message: 'Subscription retrieved successfully'
        );
    }

    /**
     * Cancel subscription
     *
     * Cancels the authenticated user's active subscription.
     * Access continues until the end of the current billing period —
     * the user is not cut off immediately.
     * After the period ends, they are automatically downgraded to the free plan.
     *
     * @authenticated
     * @group Plans
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Subscription cancelled. Access continues until 2026-04-25.",
     *   "data": null
     * }
     *
     * @response 400 {
     *   "success": false,
     *   "message": "No active subscription to cancel",
     *   "errors": null
     * }
     */
    public function cancel(Request $request): JsonResponse
    {
        $user      = $request->user();
        $cancelled = $this->subscriptionService->cancelSubscription($user);

        if (! $cancelled) {
            return $this->errorResponse(
                message: 'No active subscription to cancel',
                statusCode: 400
            );
        }

        $periodEnd = $user->activeSubscription
            ?->current_period_end
            ?->toDateString() ?? 'end of current period';

        return $this->successResponse(
            message: "Subscription cancelled. Access continues until {$periodEnd}."
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Private Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Format a plan model into the standard API response shape.
     */
    private function formatPlan(Plan $plan): array
    {
        return [
            'slug'              => $plan->slug,
            'name'              => $plan->name,
            'monthly_price_usd' => $plan->monthly_price > 0
                ? round($plan->monthly_price / 100, 2)
                : 0,
            'yearly_price_usd'  => $plan->yearly_price > 0
                ? round($plan->yearly_price / 100, 2)
                : 0,
            'monthly_quota'     => $plan->monthly_quota,
            'features'          => $plan->features,
            'is_popular'        => $plan->slug === 'pro',
        ];
    }

    /**
     * Format a subscription model into the standard API response shape.
     */
    private function formatSubscription($subscription): array
    {
        return [
            'plan'               => $this->formatPlan($subscription->plan),
            'status'             => $subscription->status->value,
            'billing_cycle'      => $subscription->billing_cycle->value,
            'current_period_end' => $subscription->current_period_end,
            'cancelled_at'       => $subscription->cancelled_at,
        ];
    }
}
