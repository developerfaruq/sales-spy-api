<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * List all available plans.
     *
     * Returns all active plans with their prices and features.
     * This is a public endpoint — no authentication required.
     * The FE uses this to populate the pricing page dynamically.
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
            ->map(fn($plan) => $this->formatPlan($plan));

        return $this->successResponse(
            data: $plans,
            message: 'Plans retrieved successfully'
        );
    }

    /**
     * Get the authenticated user's current subscription.
     *
     * @authenticated
     * @group Plans
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Subscription retrieved successfully",
     *   "data": {
     *     "plan": {
     *       "slug": "pro",
     *       "name": "Pro",
     *       "monthly_price_usd": 225.00,
     *       "monthly_quota": 2000,
     *       "features": ["2,000 leads per month"]
     *     },
     *     "status": "active",
     *     "billing_cycle": "monthly",
     *     "current_period_end": "2026-04-25T00:00:00.000000Z",
     *     "cancelled_at": null
     *   }
     * }
     */
    public function currentSubscription(Request $request): JsonResponse
    {
        $subscription = $request->user()
            ->activeSubscription()
            ->with('plan')
            ->first();

        if (!$subscription) {
            return $this->successResponse(
                data: null,
                message: 'No active subscription found'
            );
        }

        return $this->successResponse(
            data: [
                'plan'               => $this->formatPlan($subscription->plan),
                'status'             => $subscription->status->value,
                'billing_cycle'      => $subscription->billing_cycle->value,
                'current_period_end' => $subscription->current_period_end,
                'cancelled_at'       => $subscription->cancelled_at,
            ],
            message: 'Subscription retrieved successfully'
        );
    }

    /**
     * Cancel the authenticated user's subscription.
     *
     * Access continues until the end of the current billing period.
     * After that the user is automatically downgraded to the free plan.
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
    public function cancel(
        Request $request,
        \App\Services\SubscriptionService $subscriptionService
    ): JsonResponse {
        $cancelled = $subscriptionService->cancelSubscription($request->user());

        if (!$cancelled) {
            return $this->errorResponse(
                message: 'No active subscription to cancel',
                statusCode: 400
            );
        }

        $periodEnd = $request->user()
            ->activeSubscription
            ->current_period_end
            ->toDateString();

        return $this->successResponse(
            message: "Subscription cancelled. Access continues until {$periodEnd}."
        );
    }

    private function formatPlan(Plan $plan): array
    {
        return [
            'slug'              => $plan->slug,
            'name'              => $plan->name,
            'monthly_price_usd' => $plan->monthly_price > 0
                ? $plan->monthly_price / 100
                : 0,
            'yearly_price_usd'  => $plan->yearly_price > 0
                ? $plan->yearly_price / 100
                : 0,
            'monthly_quota'     => $plan->monthly_quota,
            'features'          => $plan->features,
            'is_popular'        => $plan->slug === 'pro',
        ];
    }
}
