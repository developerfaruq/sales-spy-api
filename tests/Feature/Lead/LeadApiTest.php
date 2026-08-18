<?php

namespace Tests\Feature\Lead;

use App\Enums\BillingCycle;
use App\Enums\CommercePlatform;
use App\Enums\CrawlStatus;
use App\Enums\CreditTransactionType;
use App\Enums\ProductStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WebsiteStatus;
use App\Models\EcommerceStore;
use App\Models\Plan;
use App\Models\StoreProduct;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\CreditService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    private Plan $basicPlan;

    private Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = $this->createPlan('free', 50);
        $this->basicPlan = $this->createPlan('basic', 500);
        $this->proPlan = $this->createPlan('pro', 2000);
    }

    public function test_lead_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/websites')->assertUnauthorized();
        $this->getJson('/api/v1/ecommerce')->assertUnauthorized();
        $this->getJson('/api/v1/ecommerce/example.com/products')->assertUnauthorized();
        $this->postJson('/api/v1/ecommerce/example.com/scan', [
            'idempotency_key' => 'scan-request-0001',
        ])->assertUnauthorized();
    }

    public function test_free_user_can_filter_paginate_and_is_charged_once_per_result(): void
    {
        $user = $this->createUserWithPlan($this->freePlan);
        $first = $this->createWebsite('alpha.example.com', country: 'US', technology: 'Shopify');
        $this->createWebsite('beta.example.com', country: 'GB', technology: 'WordPress');
        Sanctum::actingAs($user->fresh());

        $response = $this->getJson('/api/v1/websites?country=us&technology=Shopify&per_page=1');

        $response->assertOk()
            ->assertJsonPath('data.0.domain', $first->domain)
            ->assertJsonPath('data.0.contacts.email', null)
            ->assertJsonPath('data.0.contacts.masked', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.charged_items', 1)
            ->assertJsonPath('meta.credits_spent', 1)
            ->assertJsonPath('meta.credits_balance', 49);

        $this->getJson('/api/v1/websites?country=us&technology=Shopify&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.charged_items', 0)
            ->assertJsonPath('meta.credits_spent', 0)
            ->assertJsonPath('meta.credits_balance', 49);

        $this->assertDatabaseCount('credit_transactions', 2); // plan grant + lead charge
    }

    public function test_leads_are_charged_again_in_a_new_credit_period(): void
    {
        $user = $this->createUserWithPlan($this->freePlan);
        $this->createWebsite('recharge.example.com');
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/websites?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.charged_items', 1)
            ->assertJsonPath('meta.credits_spent', 1);

        // Same period: the lead must not be charged twice.
        $this->getJson('/api/v1/websites?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.charged_items', 0);

        // A monthly reset reuses the same subscription row, so the credit period
        // must be derived from the reset transaction rather than the subscription.
        $subscription = $user->fresh()->activeSubscription;
        $subscription->update(['credits_reset_at' => now()->subDay()]);
        app(CreditService::class)->resetSubscriptionCredits($subscription);

        $this->getJson('/api/v1/websites?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.charged_items', 1)
            ->assertJsonPath('meta.credits_spent', 1);

        $this->assertSame($subscription->id, $user->fresh()->activeSubscription->id);
    }

    public function test_search_charging_is_atomic_when_balance_is_insufficient(): void
    {
        $user = $this->createUserWithPlan($this->freePlan);
        $user->update(['credits_balance' => 1]);
        $this->createWebsite('one.example.com');
        $this->createWebsite('two.example.com');
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/websites?per_page=2')
            ->assertStatus(402)
            ->assertJsonPath('message', 'Insufficient credits. Please upgrade your plan.');

        $this->assertSame(1, $user->fresh()->credits_balance);
        $this->assertSame(0, $user->creditTransactions()->where('type', 'spend')->count());
    }

    public function test_basic_user_sees_contacts_and_detail_access_is_repeat_safe(): void
    {
        $user = $this->createUserWithPlan($this->basicPlan);
        $website = $this->createWebsite('contacts.example.com');
        Sanctum::actingAs($user->fresh());

        $this->getJson("/api/v1/websites/{$website->domain}")
            ->assertOk()
            ->assertJsonPath('data.contacts.email', 'owner@contacts.example.com')
            ->assertJsonPath('data.contacts.phone', '+12025550123')
            ->assertJsonPath('data.contacts.masked', false)
            ->assertJsonPath('meta.credits_spent', 1)
            ->assertJsonMissingPath('data.source_payload')
            ->assertJsonMissingPath('data.last_crawl_error')
            ->assertJsonMissingPath('data.claim_token');

        $this->getJson("/api/v1/websites/{$website->domain}")
            ->assertOk()
            ->assertJsonPath('meta.credits_spent', 0);
    }

    public function test_ecommerce_filters_and_product_access_follow_plan_policy(): void
    {
        $freeUser = $this->createUserWithPlan($this->freePlan);
        $basicUser = $this->createUserWithPlan($this->basicPlan);
        [$website, $store] = $this->createStore('shop.example.com', CommercePlatform::SHOPIFY);
        $product = $this->createProduct($store, 'Running Shoe', 1999);

        Sanctum::actingAs($freeUser->fresh());
        $this->getJson("/api/v1/ecommerce/{$website->domain}/products")
            ->assertForbidden()
            ->assertJsonPath('message', 'Upgrade to Basic or higher to access store products.');

        Sanctum::actingAs($basicUser->fresh());
        $this->getJson('/api/v1/ecommerce?platform=shopify&country=US&min_products=1')
            ->assertOk()
            ->assertJsonPath('data.0.website.domain', $website->domain)
            ->assertJsonPath('data.0.store.platform', 'shopify');

        $this->getJson("/api/v1/ecommerce/{$website->domain}/products?q=Running&min_price=10&max_price=30")
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.price', 19.99)
            ->assertJsonPath('meta.charged_items', 1);
    }

    public function test_pro_scan_is_idempotent_and_charged_once(): void
    {
        $user = $this->createUserWithPlan($this->proPlan);
        [$website] = $this->createStore('scan.example.com', CommercePlatform::SHOPIFY);
        Sanctum::actingAs($user->fresh());
        $payload = ['idempotency_key' => 'scan-request-20260806-001'];

        $first = $this->postJson("/api/v1/ecommerce/{$website->domain}/scan", $payload);
        $first->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('meta.credits_balance', 1995);

        $this->postJson("/api/v1/ecommerce/{$website->domain}/scan", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('meta.credits_balance', 1995);

        $this->assertDatabaseCount('store_scan_requests', 1);
        $this->assertSame(1, $user->creditTransactions()->where('type', 'spend')->count());
    }

    public function test_scan_requires_pro_and_shopify(): void
    {
        $basic = $this->createUserWithPlan($this->basicPlan);
        $pro = $this->createUserWithPlan($this->proPlan);
        [$shopifyWebsite] = $this->createStore('upgrade.example.com', CommercePlatform::SHOPIFY);
        [$wooWebsite] = $this->createStore('woo.example.com', CommercePlatform::WOOCOMMERCE);
        $payload = ['idempotency_key' => 'scan-request-20260806-002'];

        Sanctum::actingAs($basic->fresh());
        $this->postJson("/api/v1/ecommerce/{$shopifyWebsite->domain}/scan", $payload)
            ->assertForbidden();

        Sanctum::actingAs($pro->fresh());
        $this->postJson("/api/v1/ecommerce/{$wooWebsite->domain}/scan", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'Deep scans are currently supported for Shopify stores only.');

        $this->assertSame(2000, $pro->fresh()->credits_balance);
        $this->assertDatabaseCount('store_scan_requests', 0);
    }

    public function test_invalid_filters_and_unknown_domains_return_consistent_errors(): void
    {
        $user = $this->createUserWithPlan($this->freePlan);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/websites?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed');
        $this->getJson('/api/v1/websites/not-a-domain')
            ->assertNotFound();
        $this->getJson('/api/v1/ecommerce/missing.example.com')
            ->assertNotFound();
    }

    public function test_array_query_filters_are_rejected_with_validation_not_a_server_error(): void
    {
        $user = $this->createUserWithPlan($this->freePlan);
        Sanctum::actingAs($user->fresh());

        // A scalar cast in prepareForValidation would raise a 500 here.
        $this->getJson('/api/v1/websites?country[]=US')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed');

        $this->getJson('/api/v1/ecommerce?currency[]=USD')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_string_boolean_filters_are_accepted_consistently(): void
    {
        $user = $this->createUserWithPlan($this->basicPlan);
        [$website] = $this->createStore('boolean.example.com', CommercePlatform::SHOPIFY);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/websites?is_ecommerce=true')->assertOk();
        $this->getJson('/api/v1/websites?is_ecommerce=false')->assertOk();
        $this->getJson("/api/v1/ecommerce/{$website->domain}/products?available=true")->assertOk();
        $this->getJson('/api/v1/user/notifications?unread=true')->assertOk();

        $this->getJson('/api/v1/websites?is_ecommerce=notabool')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_store_is_not_billable_until_its_own_crawl_completes(): void
    {
        $user = $this->createUserWithPlan($this->basicPlan);
        [$website, $store] = $this->createStore('pending.example.com', CommercePlatform::SHOPIFY);
        $store->update(['crawl_status' => CrawlStatus::PENDING]);
        $balanceBefore = $user->fresh()->credits_balance;
        Sanctum::actingAs($user->fresh());

        // Hidden from the listing, so it must not be reachable or billable
        // through the detail, product, or scan routes either.
        $this->getJson('/api/v1/ecommerce')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/ecommerce/{$website->domain}")->assertNotFound();
        $this->getJson("/api/v1/ecommerce/{$website->domain}/products")->assertNotFound();

        $this->assertSame($balanceBefore, $user->fresh()->credits_balance);
    }

    public function test_product_list_omits_unbounded_variant_payloads(): void
    {
        $user = $this->createUserWithPlan($this->basicPlan);
        [$website, $store] = $this->createStore('payload.example.com', CommercePlatform::SHOPIFY);
        $product = $this->createProduct($store, 'Heavy Product', 4999);
        $product->update([
            'variants' => array_fill(0, 50, ['id' => 'v', 'price_cents' => 4999]),
            'images' => array_fill(0, 25, 'https://cdn.example.com/image.jpg'),
        ]);
        Sanctum::actingAs($user->fresh());

        // Variants are third-party data bounded only at ~500 KB per product,
        // so a 100-row page must not embed them.
        $this->getJson("/api/v1/ecommerce/{$website->domain}/products")
            ->assertOk()
            ->assertJsonPath('data.0.variants', null)
            ->assertJsonPath('data.0.variant_count', 1)
            ->assertJsonPath('data.0.images_truncated', true)
            ->assertJsonCount(5, 'data.0.images');
    }

    /**
     * The response already hides these payloads; this pins the database read so
     * a 100-row page cannot detoast megabytes only to discard them.
     */
    public function test_list_queries_do_not_select_unbounded_jsonb_columns(): void
    {
        $user = $this->createUserWithPlan($this->basicPlan);
        [$website, $store] = $this->createStore('selects.example.com', CommercePlatform::SHOPIFY);
        $this->createProduct($store, 'Selective Product', 1500);
        Sanctum::actingAs($user->fresh());

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/v1/websites')->assertOk();
        $this->getJson("/api/v1/ecommerce/{$website->domain}/products")->assertOk();

        $websiteList = $this->findQuery($queries, 'from "websites" where "crawl_status"');
        $productList = $this->findQuery($queries, 'from "store_products" where "ecommerce_store_id"');

        // Explicit column lists, so a bare `select *` cannot creep back in.
        $this->assertStringStartsWith('select "', $websiteList);
        $this->assertStringStartsWith('select "', $productList);

        $this->assertStringNotContainsString('"source_payload"', $websiteList);
        $this->assertStringNotContainsString('"description"', $websiteList);
        $this->assertStringNotContainsString('"source_payload"', $productList);
        $this->assertStringNotContainsString('"variants"', $productList);

        // The columns the presenter does read must still be selected.
        $this->assertStringContainsString('"domain"', $websiteList);
        $this->assertStringContainsString('"social_links"', $websiteList);
        $this->assertStringContainsString('"images"', $productList);
        $this->assertStringContainsString('"variant_count"', $productList);
    }

    private function findQuery(array $queries, string $needle): string
    {
        foreach ($queries as $sql) {
            // Skip the paginator's count(*) companion query.
            if (str_contains($sql, $needle) && str_starts_with($sql, 'select "')) {
                return $sql;
            }
        }

        $this->fail("No column-list query matched: {$needle}");
    }

    public function test_ecommerce_sorting_works_across_joined_and_store_columns(): void
    {
        $user = $this->createUserWithPlan($this->basicPlan);
        $this->createStore('aaa-sort.example.com', CommercePlatform::SHOPIFY);
        $this->createStore('zzz-sort.example.com', CommercePlatform::SHOPIFY);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/ecommerce?sort=domain&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.website.domain', 'aaa-sort.example.com');

        $this->getJson('/api/v1/ecommerce?sort=product_count&direction=desc')->assertOk();
        $this->getJson('/api/v1/ecommerce?sort=store_name&direction=asc')->assertOk();
        $this->getJson('/api/v1/ecommerce?sort=average_price&direction=asc')->assertOk();
        $this->getJson('/api/v1/ecommerce?sort=last_seen_at&direction=desc')->assertOk();
    }

    private function createUserWithPlan(Plan $plan): User
    {
        $user = User::create([
            'name' => ucfirst($plan->slug).' User',
            'email' => uniqid($plan->slug.'-', true).'@example.com',
            'password' => 'password123',
        ])->fresh();

        if ($plan->isFree()) {
            app(SubscriptionService::class)->assignFreePlan($user);
        } else {
            Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::MONTHLY,
                'status' => SubscriptionStatus::ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'credits_reset_at' => now()->addMonth(),
            ]);
            $user->update([
                'credits_balance' => $plan->monthly_quota,
                'credits_monthly_quota' => $plan->monthly_quota,
            ]);
            $user->creditTransactions()->create([
                'type' => CreditTransactionType::SUBSCRIPTION_GRANT,
                'amount' => $plan->monthly_quota,
                'balance_before' => 0,
                'balance_after' => $plan->monthly_quota,
                'description' => "{$plan->name} test period grant",
                'idempotency_key' => "test-period:{$user->id}:{$plan->slug}",
            ]);
        }

        return $user->fresh();
    }

    private function createPlan(string $slug, int $quota): Plan
    {
        return Plan::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => $quota,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function createWebsite(
        string $domain,
        string $country = 'US',
        string $technology = 'Laravel'
    ): Website {
        return Website::create([
            'domain' => $domain,
            'canonical_url' => "https://{$domain}",
            'name' => ucfirst(strtok($domain, '.')),
            'description' => "Lead intelligence for {$domain}",
            'website_status' => WebsiteStatus::ACTIVE,
            'is_ecommerce' => false,
            'country_code' => $country,
            'email' => "owner@{$domain}",
            'phone' => '+12025550123',
            'contact_page_url' => "https://{$domain}/contact",
            'technologies' => [$technology],
            'social_links' => ['linkedin' => "https://linkedin.com/company/{$domain}"],
            'source' => 'test',
            'source_external_id' => uniqid('lead-', true),
            'crawl_status' => CrawlStatus::COMPLETED,
            'last_seen_at' => now(),
            'last_crawled_at' => now(),
        ]);
    }

    /** @return array{Website, EcommerceStore} */
    private function createStore(string $domain, CommercePlatform $platform): array
    {
        $website = $this->createWebsite($domain);
        $website->update([
            'is_ecommerce' => true,
            'platform' => $platform->value,
        ]);
        $store = $website->ecommerceStore()->create([
            'platform' => $platform,
            'platform_store_id' => uniqid('store-', true),
            'store_name' => ucfirst(strtok($domain, '.')).' Store',
            'category' => 'Fashion',
            'currency_code' => 'USD',
            'product_count' => 1,
            'average_price_cents' => 1999,
            'crawl_status' => CrawlStatus::COMPLETED,
            'is_active' => true,
            'last_product_sync_at' => now(),
            'last_crawled_at' => now(),
        ]);

        return [$website->fresh(), $store->fresh('website')];
    }

    private function createProduct(EcommerceStore $store, string $title, int $price): StoreProduct
    {
        return $store->products()->create([
            'external_id' => uniqid('product-', true),
            'handle' => str($title)->slug()->toString(),
            'title' => $title,
            'vendor' => 'Acme',
            'product_type' => 'Shoes',
            'status' => ProductStatus::ACTIVE,
            'currency_code' => 'USD',
            'price_cents' => $price,
            'variant_count' => 1,
            'is_available' => true,
            'last_seen_at' => now(),
        ]);
    }
}
