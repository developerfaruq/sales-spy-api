<?php

namespace App\Services;

use App\Models\EcommerceStore;
use App\Models\StoreProduct;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadQueryService
{
    /**
     * Sort key to column map for the e-commerce listing.
     *
     * EcommerceIndexRequest builds its allowed-value rule from these keys, so
     * the validated vocabulary and the columns it maps to cannot drift. A key
     * accepted by validation but missing here would silently fall back to the
     * default ordering and return a 200 with the wrong result set.
     */
    public const ECOMMERCE_SORTS = [
        'domain' => 'websites.domain',
        'store_name' => 'ecommerce_stores.store_name',
        'product_count' => 'ecommerce_stores.product_count',
        'average_price' => 'ecommerce_stores.average_price_cents',
        'last_seen_at' => 'websites.last_seen_at',
    ];

    /** Sort key to column map for the product listing. */
    public const PRODUCT_SORTS = [
        'title' => 'title',
        'price' => 'price_cents',
        'published_at' => 'published_at',
        'last_seen_at' => 'last_seen_at',
    ];

    /** Sort keys allowed on the website listing, mapped to their columns. */
    public const WEBSITE_SORTS = [
        'domain' => 'domain',
        'last_seen_at' => 'last_seen_at',
        'discovered_at' => 'discovered_at',
        'estimated_monthly_traffic' => 'estimated_monthly_traffic',
    ];

    public function websites(array $filters): Builder
    {
        $query = Website::query()
            ->select(Website::LIST_COLUMNS)
            ->where('crawl_status', 'completed');

        $this->applyWebsiteFilters($query, $filters);

        $sort = self::WEBSITE_SORTS[$filters['sort'] ?? 'last_seen_at']
            ?? self::WEBSITE_SORTS['last_seen_at'];
        $query->orderBy($sort, $filters['direction'] ?? 'desc')->orderBy('id');

        return $query;
    }

    public function ecommerceStores(array $filters): Builder
    {
        // websites is joined once up front. Expressing the website predicates as
        // where clauses on the join avoids resolving the table again through
        // whereHas subqueries on the default (websites.last_seen_at) sort path.
        $query = EcommerceStore::query()
            ->with(['website' => fn ($relation) => $relation->select(Website::LIST_COLUMNS)])
            ->join('websites', 'websites.id', '=', 'ecommerce_stores.website_id')
            ->select('ecommerce_stores.*')
            ->where('ecommerce_stores.is_active', true)
            ->where('ecommerce_stores.crawl_status', 'completed')
            ->where('websites.crawl_status', 'completed');

        if (! empty($filters['q'])) {
            $this->applyStoreSearch($query, $filters['q']);
        }
        if (! empty($filters['platform'])) {
            $query->where('ecommerce_stores.platform', $filters['platform']);
        }
        if (! empty($filters['category'])) {
            $query->where('ecommerce_stores.category', $filters['category']);
        }
        if (! empty($filters['currency'])) {
            $query->where('ecommerce_stores.currency_code', $filters['currency']);
        }
        if (! empty($filters['country'])) {
            $query->where('websites.country_code', $filters['country']);
        }
        if (isset($filters['min_products'])) {
            $query->where('ecommerce_stores.product_count', '>=', $filters['min_products']);
        }
        if (isset($filters['max_products'])) {
            $query->where('ecommerce_stores.product_count', '<=', $filters['max_products']);
        }
        if (isset($filters['min_price'])) {
            $query->where('ecommerce_stores.average_price_cents', '>=', $this->dollarsToCents($filters['min_price']));
        }
        if (isset($filters['max_price'])) {
            $query->where('ecommerce_stores.average_price_cents', '<=', $this->dollarsToCents($filters['max_price']));
        }

        $column = self::ECOMMERCE_SORTS[$filters['sort'] ?? 'last_seen_at']
            ?? self::ECOMMERCE_SORTS['last_seen_at'];

        return $query->orderBy($column, $filters['direction'] ?? 'desc')->orderBy('ecommerce_stores.id');
    }

    public function products(EcommerceStore $store, array $filters): Builder
    {
        $query = StoreProduct::query()
            ->select(StoreProduct::LIST_COLUMNS)
            ->where('ecommerce_store_id', $store->id);

        if (! empty($filters['q'])) {
            $this->applyProductSearch($query, $filters['q']);
        }
        foreach (['vendor' => 'vendor', 'type' => 'product_type', 'status' => 'status'] as $input => $column) {
            if (! empty($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }
        if (isset($filters['available'])) {
            $query->where('is_available', $filters['available']);
        }
        if (isset($filters['min_price'])) {
            $query->where('price_cents', '>=', $this->dollarsToCents($filters['min_price']));
        }
        if (isset($filters['max_price'])) {
            $query->where('price_cents', '<=', $this->dollarsToCents($filters['max_price']));
        }

        $column = self::PRODUCT_SORTS[$filters['sort'] ?? 'last_seen_at']
            ?? self::PRODUCT_SORTS['last_seen_at'];

        return $query->orderBy($column, $filters['direction'] ?? 'desc')->orderBy('id');
    }

    private function applyWebsiteFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['q'])) {
            $this->applyWebsiteSearch($query, $filters['q']);
        }
        foreach (['country' => 'country_code', 'platform' => 'platform', 'cms' => 'cms', 'niche' => 'niche', 'status' => 'website_status'] as $input => $column) {
            if (! empty($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }
        if (isset($filters['is_ecommerce'])) {
            $query->where('is_ecommerce', $filters['is_ecommerce']);
        }
        if (! empty($filters['technology'])) {
            $query->whereJsonContains('technologies', $filters['technology']);
        }
    }

    private function applyWebsiteSearch(Builder $query, string $search): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw("to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '') || ' ' || domain) @@ plainto_tsquery('simple', ?)", [$search]);

            return;
        }

        $term = '%'.strtolower($search).'%';
        $query->where(fn (Builder $nested): Builder => $nested
            ->whereRaw('LOWER(domain) LIKE ?', [$term])
            ->orWhereRaw('LOWER(name) LIKE ?', [$term])
            ->orWhereRaw('LOWER(description) LIKE ?', [$term]));
    }

    private function applyStoreSearch(Builder $query, string $search): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw("to_tsvector('simple', coalesce(ecommerce_stores.store_name, '') || ' ' || coalesce(ecommerce_stores.category, '') || ' ' || ecommerce_stores.platform) @@ plainto_tsquery('simple', ?)", [$search]);

            return;
        }

        $term = '%'.strtolower($search).'%';
        $query->where(fn (Builder $nested): Builder => $nested
            ->whereRaw('LOWER(ecommerce_stores.store_name) LIKE ?', [$term])
            ->orWhereRaw('LOWER(ecommerce_stores.category) LIKE ?', [$term])
            ->orWhereRaw('LOWER(ecommerce_stores.platform) LIKE ?', [$term]));
    }

    private function applyProductSearch(Builder $query, string $search): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw("to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(vendor, '') || ' ' || coalesce(product_type, '')) @@ plainto_tsquery('simple', ?)", [$search]);

            return;
        }

        $term = '%'.strtolower($search).'%';
        $query->where(fn (Builder $nested): Builder => $nested
            ->whereRaw('LOWER(title) LIKE ?', [$term])
            ->orWhereRaw('LOWER(vendor) LIKE ?', [$term])
            ->orWhereRaw('LOWER(product_type) LIKE ?', [$term]));
    }

    private function dollarsToCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
