<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Plan;
use App\Services\ActivityService;
use App\Services\AdminPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlanController extends Controller
{
    public function __construct(
        protected AdminPlanService $planService,
        protected ActivityService $activityService
    ) {}

    // GET /api/v1/admin/plans

    /**
     * List every plan
     *
     * Includes inactive plans and subscriber counts, unlike the public
     * `GET /plans` endpoint which only returns active plans.
     *
     * @authenticated
     *
     * @group Admin — Plans
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Plans retrieved successfully",
     *   "data": [
     *     {
     *       "id": 1,
     *       "slug": "free",
     *       "name": "Free",
     *       "monthly_price_cents": 0,
     *       "yearly_price_cents": 0,
     *       "monthly_quota": 50,
     *       "unlimited_credits": false,
     *       "access_rank": 0,
     *       "features": ["50 credits per month"],
     *       "is_active": true,
     *       "sort_order": 0,
     *       "is_protected": true,
     *       "subscriptions_count": 90,
     *       "active_subscriptions_count": 88
     *     }
     *   ]
     * }
     * @response 403 {"success": false, "message": "Unauthorized. Admin access required.", "errors": null}
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            data: $this->planService->list(),
            message: 'Plans retrieved successfully'
        );
    }

    // POST /api/v1/admin/plans

    /**
     * Create a plan
     *
     * `access_rank` decides what lead data the tier unlocks: 0 unlocks nothing
     * beyond basic listings, 1 unlocks contacts and products, 2 unlocks deep
     * scans. If omitted it defaults by slug for the four built-in tiers, and to 0
     * for any new slug — so set it explicitly on a new paid tier or subscribers
     * will get free-tier access.
     *
     * @authenticated
     *
     * @group Admin — Plans
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Plan created successfully",
     *   "data": {"id": 5, "slug": "growth", "name": "Growth", "access_rank": 1}
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {"slug": ["A plan with this slug already exists."]}
     * }
     */
    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = $this->planService->create($request->validated());

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'admin_plan_created',
            description: "Created the {$plan->slug} plan",
            metadata: ['plan_id' => $plan->id, 'slug' => $plan->slug],
            request: $request
        );

        return $this->successResponse(
            data: $this->planService->present($plan),
            message: 'Plan created successfully',
            statusCode: 201
        );
    }

    // PUT /api/v1/admin/plans/{planId}

    /**
     * Update a plan
     *
     * Send only the fields you want to change.
     *
     * Changing `monthly_quota` does not alter what current subscribers already
     * hold — their balance was granted when they subscribed and is recalculated
     * on their next monthly reset. The free plan's slug cannot be changed because
     * registration resolves it by slug.
     *
     * @authenticated
     *
     * @group Admin — Plans
     *
     * @urlParam planId integer required The plan id. Example: 2
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Plan updated successfully",
     *   "data": {"id": 2, "slug": "basic", "monthly_price_cents": 2900, "is_active": true}
     * }
     * @response 404 {"success": false, "message": "Plan not found.", "errors": null}
     */
    public function update(UpdatePlanRequest $request, int $planId): JsonResponse
    {
        $plan = Plan::find($planId);

        if (! $plan) {
            return $this->errorResponse(message: 'Plan not found.', statusCode: 404);
        }

        $before = $plan->only(['slug', 'name', 'monthly_price', 'yearly_price', 'monthly_quota', 'access_rank', 'is_active']);
        $plan = $this->planService->update($plan, $request->validated());

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'admin_plan_updated',
            description: "Updated the {$plan->slug} plan",
            metadata: ['plan_id' => $plan->id, 'before' => $before],
            request: $request
        );

        return $this->successResponse(
            data: $this->planService->present($plan),
            message: 'Plan updated successfully'
        );
    }

    // DELETE /api/v1/admin/plans/{planId}

    /**
     * Delete a plan
     *
     * Refused with 409 when the plan is the free plan, or when any subscription
     * or payment order references it — deleting those would break registration or
     * orphan billing history. Deactivate the plan instead, which hides it from
     * the public list without affecting existing subscribers.
     *
     * @authenticated
     *
     * @group Admin — Plans
     *
     * @urlParam planId integer required The plan id. Example: 5
     *
     * @response 200 {"success": true, "message": "Plan deleted successfully", "data": null}
     * @response 409 {
     *   "success": false,
     *   "message": "This plan still has subscriptions. Deactivate it instead so existing subscribers are unaffected.",
     *   "errors": null
     * }
     * @response 404 {"success": false, "message": "Plan not found.", "errors": null}
     */
    public function destroy(Request $request, int $planId): JsonResponse
    {
        $plan = Plan::find($planId);

        if (! $plan) {
            return $this->errorResponse(message: 'Plan not found.', statusCode: 404);
        }

        $slug = $plan->slug;

        if ($reason = $this->planService->delete($plan)) {
            return $this->errorResponse(message: $reason, statusCode: 409);
        }

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'admin_plan_deleted',
            description: "Deleted the {$slug} plan",
            metadata: ['slug' => $slug],
            request: $request
        );

        return $this->successResponse(message: 'Plan deleted successfully');
    }
}
