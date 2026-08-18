<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMetricsService;
use Illuminate\Http\JsonResponse;

class AdminMetricsController extends Controller
{
    public function __construct(
        protected AdminMetricsService $metricsService
    ) {}

    // GET /api/v1/admin/metrics

    /**
     * Dashboard summary metrics
     *
     * Aggregate counters for the admin dashboard landing page. Monetary values
     * are integer cents.
     *
     * @authenticated
     *
     * @group Admin — Dashboard
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Metrics retrieved successfully",
     *   "data": {
     *     "users": {
     *       "total": 120,
     *       "active": 118,
     *       "inactive": 2,
     *       "verified": 96,
     *       "unverified": 24,
     *       "new_last_30_days": 31
     *     },
     *     "subscriptions": {
     *       "active_by_plan": {"free": 90, "basic": 20, "pro": 8},
     *       "by_status": {"active": 118, "cancelled": 4, "expired": 9}
     *     },
     *     "revenue": {
     *       "approved_total_cents": 429900,
     *       "approved_last_30_days_cents": 89900,
     *       "orders_awaiting_verification": 3
     *     },
     *     "credits": {
     *       "total_spent": 18420,
     *       "total_refunded": 260,
     *       "spent_last_30_days": 5310,
     *       "outstanding_balance": 74250
     *     },
     *     "leads": {
     *       "websites": 12840,
     *       "websites_crawled": 11203,
     *       "ecommerce_stores": 3180,
     *       "products": 412903,
     *       "deep_scans_completed": 92
     *     }
     *   }
     * }
     * @response 403 {"success": false, "message": "Admin access required.", "errors": null}
     */
    public function summary(): JsonResponse
    {
        return $this->successResponse(
            data: $this->metricsService->summary(),
            message: 'Metrics retrieved successfully'
        );
    }

    // GET /api/v1/admin/metrics/pipeline

    /**
     * Discovery pipeline status
     *
     * Operational view of the crawl and scan queues. Use this to confirm the
     * Python workers are running: a climbing `due_now` with a flat `completed`
     * count means Celery beat or the worker is down. A persistently non-zero
     * `stale_crawl_claims` means lease recovery is not running.
     *
     * @authenticated
     *
     * @group Admin — Dashboard
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Pipeline status retrieved successfully",
     *   "data": {
     *     "websites_by_crawl_status": {"pending": 420, "crawling": 8, "completed": 11203, "failed": 190, "blocked": 12},
     *     "scans_by_status": {"queued": 2, "running": 1, "completed": 92, "failed": 4},
     *     "due_now": 420,
     *     "stale_crawl_claims": 0,
     *     "stale_scan_claims": 0,
     *     "last_completed_crawl_at": "2026-08-16T17:42:10.000000Z"
     *   }
     * }
     * @response 403 {"success": false, "message": "Admin access required.", "errors": null}
     */
    public function pipeline(): JsonResponse
    {
        return $this->successResponse(
            data: $this->metricsService->pipeline(),
            message: 'Pipeline status retrieved successfully'
        );
    }
}
