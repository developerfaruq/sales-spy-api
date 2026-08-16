<?php

namespace App\Services;

use App\Models\StoreProduct;
use App\Models\User;
use App\Models\Website;

class LeadCreditService
{
    public function __construct(
        protected CreditService $creditService
    ) {}

    /** @return array{charged_items:int, credits_spent:int} */
    public function chargeWebsiteResults(User $user, iterable $websites): array
    {
        $period = $this->periodId($user);
        $resources = collect($websites)->map(fn (Website $website): array => [
            'idempotency_key' => "lead-search:{$user->id}:{$period}:website:{$website->id}",
            'description' => "Lead search result: {$website->domain}",
            'reference_type' => Website::class,
            'reference_id' => $website->id,
            'metadata' => ['domain' => $website->domain, 'access' => 'search_result'],
        ])->all();

        return $this->charge($user, 'search_result', $resources);
    }

    /** @return array{charged_items:int, credits_spent:int} */
    public function chargeProductResults(User $user, iterable $products): array
    {
        $period = $this->periodId($user);
        $resources = collect($products)->map(fn (StoreProduct $product): array => [
            'idempotency_key' => "product-search:{$user->id}:{$period}:product:{$product->id}",
            'description' => "Product search result: {$product->title}",
            'reference_type' => StoreProduct::class,
            'reference_id' => $product->id,
            'metadata' => ['access' => 'search_result'],
        ])->all();

        return $this->charge($user, 'search_result', $resources);
    }

    /** @return array{charged_items:int, credits_spent:int} */
    public function chargeWebsiteDetail(User $user, Website $website): array
    {
        $period = $this->periodId($user);

        return $this->charge($user, 'website_access', [[
            'idempotency_key' => "lead-detail:{$user->id}:{$period}:website:{$website->id}",
            'description' => "Lead detail access: {$website->domain}",
            'reference_type' => Website::class,
            'reference_id' => $website->id,
            'metadata' => ['domain' => $website->domain, 'access' => 'website_detail'],
        ]]);
    }

    /**
     * Charges are idempotent within a credit period, not for the lifetime of a
     * subscription. Credit resets reuse the same subscription row, so the
     * subscription id must not be used here.
     */
    private function periodId(User $user): string
    {
        return (string) ($this->creditService->currentPeriodId($user) ?? 'none');
    }

    private function charge(User $user, string $action, array $resources): array
    {
        if ($resources === []) {
            return ['charged_items' => 0, 'credits_spent' => 0];
        }

        return $this->creditService->spendForResources(
            $user,
            $this->creditService->getCost($action),
            $resources
        );
    }
}
