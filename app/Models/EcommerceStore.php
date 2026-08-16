<?php

namespace App\Models;

use App\Enums\CommercePlatform;
use App\Enums\CrawlStatus;
use Illuminate\Database\Eloquent\Model;

class EcommerceStore extends Model
{
    protected $fillable = [
        'website_id',
        'is_active',
        'platform',
        'platform_store_id',
        'store_name',
        'category',
        'currency_code',
        'product_count',
        'collection_count',
        'average_price_cents',
        'minimum_price_cents',
        'maximum_price_cents',
        'has_discounted_products',
        'accepts_payments',
        'has_cart',
        'crawl_status',
        'last_crawl_error',
        'payment_methods',
        'shipping_countries',
        'platform_metadata',
        'last_product_sync_at',
        'last_crawled_at',
        'next_crawl_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => CommercePlatform::class,
            'crawl_status' => CrawlStatus::class,
            'product_count' => 'integer',
            'collection_count' => 'integer',
            'average_price_cents' => 'integer',
            'minimum_price_cents' => 'integer',
            'maximum_price_cents' => 'integer',
            'has_discounted_products' => 'boolean',
            'accepts_payments' => 'boolean',
            'has_cart' => 'boolean',
            'is_active' => 'boolean',
            'payment_methods' => 'array',
            'shipping_countries' => 'array',
            'platform_metadata' => 'array',
            'last_product_sync_at' => 'datetime',
            'last_crawled_at' => 'datetime',
            'next_crawl_at' => 'datetime',
        ];
    }

    public function website()
    {
        return $this->belongsTo(Website::class);
    }

    public function products()
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function setCurrencyCodeAttribute(?string $currencyCode): void
    {
        $this->attributes['currency_code'] = $currencyCode
            ? strtoupper(trim($currencyCode))
            : null;
    }
}
