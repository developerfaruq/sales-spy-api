<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnqueueDomainsRequest;
use App\Services\ActivityService;
use App\Services\AdminLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLeadController extends Controller
{
    public function __construct(
        protected AdminLeadService $leadService,
        protected ActivityService $activityService
    ) {}

    // POST /api/v1/admin/leads/enqueue

    /**
     * Queue domains for crawling
     *
     * Inserts each domain as `pending` with an immediate `next_crawl_at`. The
     * Python worker's beat tick claims them within a minute, then detects the
     * platform, extracts contacts, and — for Shopify — pulls the product
     * catalogue in the same pass.
     *
     * This does NOT perform any crawling itself, and it is not bulk discovery.
     * Finding domains from Common Crawl streams a multi-megabyte index and runs
     * in the worker via `discover-common-crawl`, not in a web request.
     *
     * Domains already known are reported as `skipped` rather than re-queued, so
     * the call is safe to retry.
     *
     * @authenticated
     *
     * @group Admin — Leads
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Domains queued for crawling",
     *   "data": {
     *     "queued": 2,
     *     "skipped": 1,
     *     "invalid": ["not a domain"]
     *   }
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {"domains": ["A maximum of 500 domains can be queued per request."]}
     * }
     * @response 403 {"success": false, "message": "Admin access required.", "errors": null}
     */
    public function enqueue(EnqueueDomainsRequest $request): JsonResponse
    {
        $result = $this->leadService->enqueueDomains(
            $request->validated('domains'),
            $request->validated('source', 'admin')
        );

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'admin_leads_enqueued',
            description: 'Queued domains for crawling',
            metadata: [
                'queued' => $result['queued'],
                'skipped' => $result['skipped'],
                'invalid' => count($result['invalid']),
            ],
            request: $request
        );

        return $this->successResponse(
            data: $result,
            message: 'Domains queued for crawling'
        );
    }

    // POST /api/v1/admin/leads/{domain}/recrawl

    /**
     * Request an immediate re-crawl
     *
     * Marks one known domain due now. Also clears `crawl_attempts`, because that
     * counter is the consecutive-failure ceiling the worker filters on — a domain
     * parked as `blocked` would otherwise be set due and then skipped.
     *
     * A domain currently being crawled is left untouched: resetting it would
     * strand the worker's claim token and its completion write would be discarded.
     *
     * @authenticated
     *
     * @group Admin — Leads
     *
     * @urlParam domain string required The domain to re-crawl. Example: example-store.com
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Re-crawl requested",
     *   "data": {"domain": "example-store.com", "queued": true, "reason": null}
     * }
     * @response 200 scenario="Crawl already running" {
     *   "success": true,
     *   "message": "Re-crawl requested",
     *   "data": {
     *     "domain": "example-store.com",
     *     "queued": false,
     *     "reason": "A crawl is already in progress for this domain."
     *   }
     * }
     * @response 404 {"success": false, "message": "Domain not found.", "errors": null}
     * @response 403 {"success": false, "message": "Admin access required.", "errors": null}
     */
    public function recrawl(Request $request, string $domain): JsonResponse
    {
        $result = $this->leadService->requestRecrawl($domain);

        if ($result === null) {
            return $this->errorResponse(
                message: 'Domain not found.',
                statusCode: 404
            );
        }

        if ($result['queued']) {
            $this->activityService->log(
                userId: $request->user()->id,
                type: 'admin_recrawl_requested',
                description: 'Requested a re-crawl for '.$result['domain'],
                request: $request
            );
        }

        return $this->successResponse(
            data: $result,
            message: 'Re-crawl requested'
        );
    }
}
