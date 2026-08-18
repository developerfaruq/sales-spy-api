<?php

namespace App\Http\Controllers\Lead;

use App\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\WebsiteIndexRequest;
use App\Models\Website;
use App\Services\LeadCreditService;
use App\Services\LeadPresenter;
use App\Services\LeadQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WebsiteController extends Controller
{
    public function __construct(
        protected LeadQueryService $queries,
        protected LeadPresenter $presenter,
        protected LeadCreditService $credits
    ) {}

    /**
     * List website leads
     *
     * Search and filter discovered websites. Each newly viewed result costs the
     * configured search-result credit amount once per subscription period.
     *
     * @authenticated
     *
     * @group Website Leads
     */
    public function index(WebsiteIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $websites = $this->queries->websites($validated)
            ->paginate($validated['per_page'] ?? 25);

        try {
            $charge = $this->credits->chargeWebsiteResults($request->user(), $websites->getCollection());
        } catch (InsufficientCreditsException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 402);
        }

        return $this->successResponse(
            data: $websites->getCollection()
                ->map(fn (Website $website): array => $this->presenter->website($website, $request->user())),
            message: 'Website leads retrieved successfully',
            meta: $this->paginationMeta($websites, $charge)
        );
    }

    /**
     * Get website lead details
     *
     * Unlocks detailed website intelligence. Contact fields remain masked on
     * the Free plan. The resource is charged once per subscription period.
     *
     * @authenticated
     *
     * @group Website Leads
     *
     * @urlParam domain string required Canonical website domain. Example: example.com
     */
    public function show(Request $request, string $domain): JsonResponse
    {
        try {
            $normalizedDomain = Website::normalizeDomain($domain);
        } catch (InvalidArgumentException) {
            return $this->errorResponse('Website lead not found.', statusCode: 404);
        }

        $website = Website::where('domain', $normalizedDomain)
            ->where('crawl_status', 'completed')
            ->first();

        if (! $website) {
            return $this->errorResponse('Website lead not found.', statusCode: 404);
        }

        try {
            $charge = $this->credits->chargeWebsiteDetail($request->user(), $website);
        } catch (InsufficientCreditsException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 402);
        }

        return $this->successResponse(
            data: $this->presenter->website($website, $request->user(), true),
            message: 'Website lead retrieved successfully',
            meta: [
                'charged_items' => $charge['charged_items'],
                'credits_spent' => $charge['credits_spent'],
                'credits_balance' => $request->user()->fresh()->credits_balance,
            ]
        );
    }

    private function paginationMeta($paginator, array $charge): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'charged_items' => $charge['charged_items'],
            'credits_spent' => $charge['credits_spent'],
            'credits_balance' => request()->user()->fresh()->credits_balance,
        ];
    }
}
