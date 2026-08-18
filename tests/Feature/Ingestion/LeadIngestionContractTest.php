<?php

namespace Tests\Feature\Ingestion;

use App\Enums\CommercePlatform;
use App\Enums\CrawlStatus;
use App\Enums\ProductStatus;
use App\Enums\WebsiteStatus;
use App\Models\EcommerceStore;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class LeadIngestionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingestion_tables_expose_the_required_contract_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('websites', [
            'domain',
            'canonical_url',
            'website_status',
            'is_ecommerce',
            'platform',
            'country_code',
            'source',
            'source_external_id',
            'crawl_status',
            'technologies',
            'source_payload',
            'discovered_at',
            'last_seen_at',
            'next_crawl_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ecommerce_stores', [
            'website_id',
            'platform',
            'platform_store_id',
            'currency_code',
            'product_count',
            'average_price_cents',
            'crawl_status',
            'platform_metadata',
            'last_product_sync_at',
        ]));
        $this->assertTrue(Schema::hasColumns('store_products', [
            'ecommerce_store_id',
            'external_id',
            'handle',
            'title',
            'status',
            'price_cents',
            'currency_code',
            'variants',
            'source_payload',
            'last_seen_at',
        ]));
    }

    public function test_models_normalize_and_cast_ingested_data(): void
    {
        $website = Website::create([
            'domain' => 'HTTPS://WWW.Example.COM/catalog?ref=feed',
            'canonical_url' => 'https://example.com',
            'name' => 'Example Store',
            'website_status' => WebsiteStatus::ACTIVE,
            'is_ecommerce' => true,
            'platform' => 'shopify',
            'country_code' => 'us',
            'source' => 'common_crawl',
            'source_external_id' => 'crawl-1',
            'crawl_status' => CrawlStatus::COMPLETED,
            'technologies' => ['Shopify', 'Cloudflare'],
            'social_links' => ['instagram' => 'https://instagram.com/example'],
            'source_payload' => ['score' => 0.98],
            'last_seen_at' => now(),
        ]);
        $store = $website->ecommerceStore()->create([
            'platform' => CommercePlatform::SHOPIFY,
            'platform_store_id' => 'gid://shopify/Shop/1',
            'store_name' => 'Example Store',
            'currency_code' => 'usd',
            'product_count' => 1,
            'crawl_status' => CrawlStatus::COMPLETED,
            'payment_methods' => ['visa', 'paypal'],
        ]);
        $product = $store->products()->create([
            'external_id' => 'gid://shopify/Product/10',
            'handle' => 'example-product',
            'title' => 'Example Product',
            'status' => ProductStatus::ACTIVE,
            'currency_code' => 'usd',
            'price_cents' => 1999,
            'is_available' => true,
            'tags' => ['featured'],
            'variants' => [['id' => 'variant-1', 'price_cents' => 1999]],
        ]);

        $this->assertSame('example.com', $website->domain);
        $this->assertSame('US', $website->country_code);
        $this->assertSame(WebsiteStatus::ACTIVE, $website->website_status);
        $this->assertSame(CrawlStatus::COMPLETED, $website->crawl_status);
        $this->assertSame(['Shopify', 'Cloudflare'], $website->technologies);
        $this->assertSame(CommercePlatform::SHOPIFY, $store->platform);
        $this->assertSame('USD', $store->currency_code);
        $this->assertSame(['visa', 'paypal'], $store->payment_methods);
        $this->assertSame(ProductStatus::ACTIVE, $product->status);
        $this->assertSame('USD', $product->currency_code);
        $this->assertSame(1999, $product->price_cents);
        $this->assertTrue($website->ecommerceStore->is($store));
        $this->assertTrue($store->website->is($website));
        $this->assertTrue($product->ecommerceStore->is($store));
    }

    public function test_python_style_raw_upserts_are_idempotent(): void
    {
        $now = now();

        DB::table('websites')->upsert([
            [
                'domain' => 'raw-example.com',
                'canonical_url' => 'https://raw-example.com',
                'name' => 'Raw Example',
                'website_status' => 'active',
                'is_ecommerce' => true,
                'source' => 'common_crawl',
                'source_external_id' => 'raw-1',
                'crawl_status' => 'completed',
                'technologies' => json_encode(['Shopify']),
                'source_payload' => json_encode(['crawl_id' => '2026-31']),
                'discovered_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['domain'], ['name', 'last_seen_at', 'source_payload', 'updated_at']);

        DB::table('websites')->upsert([
            [
                'domain' => 'raw-example.com',
                'canonical_url' => 'https://raw-example.com',
                'name' => 'Raw Example Updated',
                'website_status' => 'active',
                'is_ecommerce' => true,
                'source' => 'common_crawl',
                'source_external_id' => 'raw-1',
                'crawl_status' => 'completed',
                'technologies' => json_encode(['Shopify', 'Cloudflare']),
                'source_payload' => json_encode(['crawl_id' => '2026-32']),
                'discovered_at' => $now,
                'last_seen_at' => $now->copy()->addDay(),
                'created_at' => $now,
                'updated_at' => $now->copy()->addDay(),
            ],
        ], ['domain'], ['name', 'last_seen_at', 'source_payload', 'updated_at']);

        $website = Website::where('domain', 'raw-example.com')->firstOrFail();

        $this->assertDatabaseCount('websites', 1);
        $this->assertSame('Raw Example Updated', $website->name);
        $this->assertSame(['crawl_id' => '2026-32'], $website->source_payload);
    }

    public function test_domain_and_ingestion_identifiers_are_unique(): void
    {
        $website = $this->createWebsite('unique.example.com');

        try {
            $this->createWebsite('unique.example.com');
            $this->fail('Expected duplicate domain constraint violation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('websites', 1);
        }

        $store = EcommerceStore::create([
            'website_id' => $website->id,
            'platform' => CommercePlatform::SHOPIFY,
            'platform_store_id' => 'shop-unique',
        ]);
        $store->products()->create([
            'external_id' => 'product-1',
            'handle' => 'product-one',
            'title' => 'Product One',
        ]);

        $this->expectException(QueryException::class);

        $store->products()->create([
            'external_id' => 'product-1',
            'handle' => 'product-one-copy',
            'title' => 'Duplicate Product',
        ]);
    }

    public function test_deleting_website_cascades_store_and_products(): void
    {
        $website = $this->createWebsite('cascade.example.com');
        $store = $website->ecommerceStore()->create([
            'platform' => CommercePlatform::SHOPIFY,
            'platform_store_id' => 'cascade-shop',
        ]);
        $store->products()->create([
            'external_id' => 'cascade-product',
            'title' => 'Cascade Product',
        ]);

        $website->delete();

        $this->assertDatabaseCount('websites', 0);
        $this->assertDatabaseCount('ecommerce_stores', 0);
        $this->assertDatabaseCount('store_products', 0);
    }

    public function test_due_for_crawl_scopes_exclude_future_and_in_progress_records(): void
    {
        $due = $this->createWebsite('due.example.com', CrawlStatus::FAILED, now()->subMinute());
        $this->createWebsite('future.example.com', CrawlStatus::PENDING, now()->addHour());
        $this->createWebsite('crawling.example.com', CrawlStatus::CRAWLING, now()->subMinute());

        $this->assertSame([$due->id], Website::dueForCrawl()->pluck('id')->all());
    }

    public function test_invalid_domain_is_rejected_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Website::create([
            'domain' => 'not-a-domain',
            'source' => 'test',
        ]);
    }

    public function test_ip_and_invalid_dns_labels_are_rejected(): void
    {
        foreach (['127.0.0.1', 'bad_label.example.com'] as $domain) {
            try {
                Website::create([
                    'domain' => $domain,
                    'source' => 'test',
                ]);
                $this->fail("Expected {$domain} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->assertDatabaseMissing('websites', ['domain' => $domain]);
            }
        }
    }

    /**
     * The Python worker punycodes hosts before writing, so the API must apply
     * the same IDNA step or an internationalised lead becomes unreachable by the
     * name a caller would type.
     */
    public function test_international_domains_normalize_to_the_same_punycode_as_the_worker(): void
    {
        $this->assertSame(
            'xn--mnchen-3ya.example',
            Website::normalizeDomain('münchen.example')
        );
        $this->assertSame(
            'xn--mnchen-3ya.example',
            Website::normalizeDomain('https://WWW.München.Example/path')
        );

        // Already-encoded input must be stable, so a round trip cannot drift.
        $this->assertSame(
            'xn--mnchen-3ya.example',
            Website::normalizeDomain('xn--mnchen-3ya.example')
        );

        $website = Website::create([
            'domain' => 'münchen.example',
            'website_status' => WebsiteStatus::ACTIVE,
            'source' => 'test',
            'crawl_status' => CrawlStatus::COMPLETED,
        ]);

        $this->assertSame('xn--mnchen-3ya.example', $website->fresh()->domain);
    }

    private function createWebsite(
        string $domain,
        CrawlStatus $crawlStatus = CrawlStatus::PENDING,
        $nextCrawlAt = null
    ): Website {
        return Website::create([
            'domain' => $domain,
            'website_status' => WebsiteStatus::ACTIVE,
            'source' => 'test',
            'source_external_id' => uniqid('source-', true),
            'crawl_status' => $crawlStatus,
            'next_crawl_at' => $nextCrawlAt,
        ]);
    }
}
