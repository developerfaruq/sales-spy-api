<?php

namespace App\Http\Controllers\Lead;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\StoreScanConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\EcommerceIndexRequest;
use App\Http\Requests\Lead\ProductIndexRequest;
use App\Http\Requests\Lead\StoreScanRequest;
use App\Models\EcommerceStore;
use App\Models\StoreProduct;
use App\Models\Website;
use App\Services\LeadAccessPolicy;
use App\Services\LeadCreditService;
use App\Services\LeadPresenter;
use App\Services\LeadQueryService;
use App\Services\StoreScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EcommerceController extends Controller
{
    public function __construct(
        protected LeadQueryService $queries,
        protected LeadPresenter $presenter,
        protected LeadCreditService $credits,
        protected LeadAccessPolicy $policy,
        protected StoreScanService $scans
    ) {}

    /**
     * List e-commerce leads
     *
     * @authenticated
     *
     * @group E-commerce Leads
     */
    public function index(EcommerceIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $stores = $this->queries->ecommerceStores($validated)
            ->paginate($validated['per_page'] ?? 25);

        try {
            $charge = $this->credits->chargeWebsiteResults(
                $request->user(),
                $stores->getCollection()->pluck('website')
            );
        } catch (InsufficientCreditsException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 402);
        }

        return $this->successResponse(
            data: $stores->getCollection()
                ->map(fn (EcommerceStore $store): array => $this->presenter->store($store, $request->user())),
            message: 'E-commerce leads retrieved successfully',
            meta: $this->paginationMeta($stores, $charge, $request)
        );
    }

    /**
     * Get e-commerce lead details
     *
     * @authenticated
     *
     * @group E-commerce Leads
     *
     * @urlParam domain string required Canonical store domain. Example: store.example.com
     */
    public function show(Request $request, string $domain): JsonResponse
    {
        $store = $this->findStore($domain);
        if (! $store) {
            return $this->errorResponse('E-commerce lead not found.', statusCode: 404);
        }

        try {
            $charge = $this->credits->chargeWebsiteDetail($request->user(), $store->website);
        } catch (InsufficientCreditsException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 402);
        }

        return $this->successResponse(
            data: $this->presenter->store($store, $request->user(), true),
            message: 'E-commerce lead retrieved successfully',
            meta: $this->chargeMeta($request, $charge)
        );
    }

    /**
     * List store products
     *
     * Available on Basic, Pro, and Enterprise plans.
     *
     * @authenticated
     *
     * @group E-commerce Leads
     *
     * @urlParam domain string required Canonical store domain. Example: store.example.com
     */
    public function products(ProductIndexRequest $request, string $domain): JsonResponse
    {
        if (! $this->policy->canViewProducts($request->user())) {
            return $this->errorResponse('Upgrade to Basic or higher to access store products.', statusCode: 403);
        }

        $store = $this->findStore($domain);
        if (! $store) {
            return $this->errorResponse('E-commerce lead not found.', statusCode: 404);
        }

        $validated = $request->validated();
        $products = $this->queries->products($store, $validated)
            ->paginate($validated['per_page'] ?? 25);

        try {
            $charge = $this->credits->chargeProductResults($request->user(), $products->getCollection());
        } catch (InsufficientCreditsException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 402);
        }

        return $this->successResponse(
            data: $products->getCollection()
                ->map(fn (StoreProduct $product): array => $this->presenter->product($product)),
            message: 'Store products retrieved successfully',
            meta: $this->paginationMeta($products, $charge, $request)
        );
    }

    /**
     * Request a deep store scan
     *
     * Queues an idempotent Shopify catalog scan. Available on Pro and Enterprise.
     *
     * @authenticated
     *
     * @group E-commerce Leads
     *
     * @urlParam domain string required Canonical store domain. Example: store.example.com
     */
    public function scan(StoreScanRequest $request, string $domain): JsonResponse
    {
        if (! $this->policy->canRequestDeepScan($request->user())) {
            return $this->errorResponse('Upgrade to Pro or higher to request deep scans.', statusCode: 403);
        }

        $store = $this->findStore($domain);
        if (! $store) {
            return $this->errorResponse('E-commerce lead not found.', statusCode: 404);
        }

        try {
            $scan = $this->scans->request(
                $request->user(),
                $store,
                $request->validated('idempotency_key')
            );
        } catch (InsufficientCreditsException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 402);
        } catch (StoreScanConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), statusCode: 409);
        }

        return $this->successResponse(
            data: [
                'id' => $scan->id,
                'domain' => $store->website->domain,
                'status' => $scan->status->value,
                'requested_at' => $scan->requested_at,
            ],
            message: 'Deep scan queued successfully',
            statusCode: $scan->wasRecentlyCreated ? 202 : 200,
            meta: ['credits_balance' => $request->user()->fresh()->credits_balance]
        );
    }

    private function findStore(string $domain): ?EcommerceStore
    {
        try {
            $normalizedDomain = Website::normalizeDomain($domain);
        } catch (InvalidArgumentException) {
            return null;
        }

        return EcommerceStore::with('website')
            ->where('is_active', true)
            ->where('crawl_status', 'completed')
            ->whereHas('website', fn ($query) => $query
                ->where('domain', $normalizedDomain)
                ->where('crawl_status', 'completed'))
            ->first();
    }

    private function paginationMeta($paginator, array $charge, Request $request): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            ...$this->chargeMeta($request, $charge),
        ];
    }

    private function chargeMeta(Request $request, array $charge): array
    {
        return [
            'charged_items' => $charge['charged_items'],
            'credits_spent' => $charge['credits_spent'],
            'credits_balance' => $request->user()->fresh()->credits_balance,
        ];
    }
}
