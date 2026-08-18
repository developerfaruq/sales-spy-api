<?php

namespace App\Services;

use App\Models\EcommerceStore;
use App\Models\StoreProduct;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class LeadPresenter
{
    private const LIST_IMAGE_LIMIT = 5;

    public function __construct(
        protected LeadAccessPolicy $policy
    ) {}

    public function website(Website $website, User $user, bool $detail = false): array
    {
        $contactsVisible = $this->policy->canViewContacts($user);

        return [
            'domain' => $website->domain,
            'canonical_url' => $website->canonical_url,
            'name' => $website->name,
            'description' => $detail ? $website->description : null,
            'website_status' => $website->website_status->value,
            'is_ecommerce' => $website->is_ecommerce,
            'platform' => $website->platform,
            'cms' => $website->cms,
            'niche' => $website->niche,
            'location' => [
                'country_code' => $website->country_code,
                'region' => $website->region,
                'city' => $website->city,
            ],
            'language_code' => $website->language_code,
            'contacts' => [
                'email' => $contactsVisible ? $website->email : null,
                'phone' => $contactsVisible ? $website->phone : null,
                'contact_page_url' => $contactsVisible ? $website->contact_page_url : null,
                'social_links' => $contactsVisible ? $website->social_links : null,
                'masked' => ! $contactsVisible,
            ],
            'logo_url' => $website->logo_url,
            'favicon_url' => $website->favicon_url,
            'http_status' => $website->http_status,
            'estimated_monthly_traffic' => $website->estimated_monthly_traffic,
            'domain_age_days' => $website->domain_age_days,
            'technologies' => $detail ? $website->technologies : null,
            'freshness' => $this->freshness($website->last_seen_at),
        ];
    }

    public function store(EcommerceStore $store, User $user, bool $detail = false): array
    {
        return [
            'website' => $this->website($store->website, $user, $detail),
            'store' => [
                'platform' => $store->platform->value,
                'name' => $store->store_name,
                'category' => $store->category,
                'currency_code' => $store->currency_code,
                'product_count' => $store->product_count,
                'collection_count' => $store->collection_count,
                'average_price' => $this->centsToDollars($store->average_price_cents),
                'minimum_price' => $this->centsToDollars($store->minimum_price_cents),
                'maximum_price' => $this->centsToDollars($store->maximum_price_cents),
                'has_discounted_products' => $store->has_discounted_products,
                'accepts_payments' => $store->accepts_payments,
                'has_cart' => $store->has_cart,
                'payment_methods' => $detail ? $store->payment_methods : null,
                'shipping_countries' => $detail ? $store->shipping_countries : null,
                'last_product_sync_at' => $store->last_product_sync_at,
            ],
        ];
    }

    /**
     * @param  bool  $detail  Include the unbounded variant payload.
     *
     * Variants come from a third-party catalog and are only capped at ~500 KB
     * per product by ingestion, so a 100-row page could otherwise assemble a
     * multi-megabyte response. List responses expose a bounded preview and the
     * variant count instead.
     */
    public function product(StoreProduct $product, bool $detail = false): array
    {
        $images = $product->images ?? [];
        $description = $detail
            ? $product->description
            : ($product->description === null ? null : Str::limit($product->description, 500));

        return [
            'id' => $product->id,
            'external_id' => $product->external_id,
            'handle' => $product->handle,
            'title' => $product->title,
            'description' => $description,
            'vendor' => $product->vendor,
            'product_type' => $product->product_type,
            'status' => $product->status->value,
            'currency_code' => $product->currency_code,
            'price' => $this->centsToDollars($product->price_cents),
            'compare_at_price' => $this->centsToDollars($product->compare_at_price_cents),
            'minimum_variant_price' => $this->centsToDollars($product->minimum_variant_price_cents),
            'maximum_variant_price' => $this->centsToDollars($product->maximum_variant_price_cents),
            'variant_count' => $product->variant_count,
            'is_available' => $product->is_available,
            'inventory_quantity' => $product->inventory_quantity,
            'product_url' => $product->product_url,
            'primary_image_url' => $product->primary_image_url,
            'tags' => $product->tags,
            'images' => $detail ? $images : array_slice($images, 0, self::LIST_IMAGE_LIMIT),
            'images_truncated' => ! $detail && count($images) > self::LIST_IMAGE_LIMIT,
            'variants' => $detail ? $product->variants : null,
            'published_at' => $product->published_at,
            'freshness' => $this->freshness($product->last_seen_at),
        ];
    }

    private function freshness(?CarbonInterface $lastSeenAt): array
    {
        return [
            'state' => match (true) {
                $lastSeenAt === null => 'unknown',
                $lastSeenAt->greaterThanOrEqualTo(now()->subDays(7)) => 'fresh',
                $lastSeenAt->greaterThanOrEqualTo(now()->subDays(30)) => 'aging',
                default => 'stale',
            },
            'last_seen_at' => $lastSeenAt,
        ];
    }

    private function centsToDollars(?int $cents): ?float
    {
        return $cents === null ? null : round($cents / 100, 2);
    }
}
