<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;

class StoreProduct extends Model
{
    /**
     * Columns the product list endpoint actually reads.
     *
     * `variants` and `source_payload` together are bounded only at ~500 KB per
     * product by ingestion, and LeadPresenter returns `variants => null` for
     * list responses, so selecting them would transfer tens of megabytes per
     * page only to discard them. Detail requests select every column.
     */
    public const LIST_COLUMNS = [
        'id',
        'ecommerce_store_id',
        'external_id',
        'handle',
        'title',
        'description',
        'vendor',
        'product_type',
        'status',
        'currency_code',
        'price_cents',
        'compare_at_price_cents',
        'minimum_variant_price_cents',
        'maximum_variant_price_cents',
        'variant_count',
        'is_available',
        'inventory_quantity',
        'product_url',
        'primary_image_url',
        'tags',
        'images',
        'published_at',
        'last_seen_at',
    ];

    protected $fillable = [
        'ecommerce_store_id',
        'external_id',
        'handle',
        'title',
        'description',
        'vendor',
        'product_type',
        'status',
        'currency_code',
        'price_cents',
        'compare_at_price_cents',
        'minimum_variant_price_cents',
        'maximum_variant_price_cents',
        'variant_count',
        'is_available',
        'inventory_quantity',
        'product_url',
        'primary_image_url',
        'tags',
        'images',
        'variants',
        'source_payload',
        'published_at',
        'source_created_at',
        'source_updated_at',
        'last_seen_at',
        'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'price_cents' => 'integer',
            'compare_at_price_cents' => 'integer',
            'minimum_variant_price_cents' => 'integer',
            'maximum_variant_price_cents' => 'integer',
            'variant_count' => 'integer',
            'is_available' => 'boolean',
            'inventory_quantity' => 'integer',
            'tags' => 'array',
            'images' => 'array',
            'variants' => 'array',
            'source_payload' => 'array',
            'published_at' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'first_seen_at' => 'datetime',
        ];
    }

    public function ecommerceStore()
    {
        return $this->belongsTo(EcommerceStore::class);
    }

    public function setCurrencyCodeAttribute(?string $currencyCode): void
    {
        $this->attributes['currency_code'] = $currencyCode
            ? strtoupper(trim($currencyCode))
            : null;
    }
}
