<?php

namespace Tests\Feature\Admin;

use App\Enums\CreditTransactionType;
use App\Enums\PaymentStatus;
use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = $this->createPlan('free', 50);
        // Roles are registered against the `api` guard in this app; a role
        // created with the default `web` guard silently fails the admin check.
        Role::findOrCreate('admin', 'api');
    }

    public function test_metrics_require_the_admin_role(): void
    {
        $user = $this->createUser('plain@example.com');
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/admin/metrics')->assertForbidden();
        $this->getJson('/api/v1/admin/metrics/pipeline')->assertForbidden();
        $this->getJson('/api/v1/admin/activities')->assertForbidden();
        $this->postJson('/api/v1/admin/leads/enqueue', ['domains' => ['a.com']])->assertForbidden();
        $this->postJson('/api/v1/admin/leads/a.com/recrawl')->assertForbidden();
    }

    public function test_metrics_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/metrics')->assertUnauthorized();
    }

    public function test_metrics_summary_counts_users_revenue_and_credits(): void
    {
        $admin = $this->createAdmin();
        $verified = $this->createUser('verified@example.com');
        $verified->markEmailAsVerified();
        $inactive = $this->createUser('inactive@example.com');
        $inactive->update(['is_active' => false]);

        PaymentOrder::create([
            'reference' => 'REF-APPROVED',
            'user_id' => $verified->id,
            'plan_id' => $this->freePlan->id,
            'billing_cycle' => 'monthly',
            'amount_usd_cents' => 4900,
            'currency' => 'USD',
            'network' => 'trc20',
            'status' => PaymentStatus::APPROVED,
            'expires_at' => now()->addDay(),
        ]);
        PaymentOrder::create([
            'reference' => 'REF-AWAITING',
            'user_id' => $verified->id,
            'plan_id' => $this->freePlan->id,
            'billing_cycle' => 'monthly',
            'amount_usd_cents' => 9900,
            'currency' => 'USD',
            'network' => 'trc20',
            'status' => PaymentStatus::AWAITING_VERIFICATION,
            'expires_at' => now()->addDay(),
        ]);

        CreditTransaction::create([
            'user_id' => $verified->id,
            'type' => CreditTransactionType::SPEND,
            'amount' => -30,
            'balance_before' => 50,
            'balance_after' => 20,
            'description' => 'Lead views',
            'idempotency_key' => 'metrics-spend-1',
        ]);
        CreditTransaction::create([
            'user_id' => $verified->id,
            'type' => CreditTransactionType::REFUND,
            'amount' => 5,
            'balance_before' => 20,
            'balance_after' => 25,
            'description' => 'Failed scan refund',
            'idempotency_key' => 'metrics-refund-1',
        ]);

        Sanctum::actingAs($admin->fresh());

        $response = $this->getJson('/api/v1/admin/metrics')->assertOk();

        // 3 users seeded here (admin, verified, inactive) plus their opening balances.
        $response->assertJsonPath('data.users.total', 3)
            ->assertJsonPath('data.users.active', 2)
            ->assertJsonPath('data.users.inactive', 1)
            ->assertJsonPath('data.users.verified', 1)
            ->assertJsonPath('data.users.unverified', 2);

        // Only the approved order counts toward revenue.
        $response->assertJsonPath('data.revenue.approved_total_cents', 4900)
            ->assertJsonPath('data.revenue.orders_awaiting_verification', 1);

        // Spend is stored negative but reported as a magnitude.
        $response->assertJsonPath('data.credits.total_spent', 30)
            ->assertJsonPath('data.credits.total_refunded', 5);

        $response->assertJsonPath('data.subscriptions.active_by_plan.free', 3);
    }

    public function test_pipeline_reports_crawl_states_and_stale_claims(): void
    {
        $admin = $this->createAdmin();

        $this->makeWebsite('pending-one.com', 'pending', now()->subMinute());
        $this->makeWebsite('done-one.com', 'completed', now()->addDay());
        $stale = $this->makeWebsite('stuck-one.com', 'crawling', null);
        DB::table('websites')->where('id', $stale->id)->update([
            'claim_lease_expires_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/metrics/pipeline')
            ->assertOk()
            ->assertJsonPath('data.websites_by_crawl_status.pending', 1)
            ->assertJsonPath('data.websites_by_crawl_status.completed', 1)
            ->assertJsonPath('data.websites_by_crawl_status.crawling', 1)
            // pending-one is due; done-one is scheduled a day out.
            ->assertJsonPath('data.due_now', 1)
            ->assertJsonPath('data.stale_crawl_claims', 1);
    }

    public function test_enqueue_normalizes_domains_and_skips_known_ones(): void
    {
        $admin = $this->createAdmin();
        $this->makeWebsite('already-known.com', 'completed', now()->addDay());
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/leads/enqueue', [
            'domains' => [
                'https://WWW.New-Store.com/collections/all',
                'already-known.com',
                'münchen.example',
                'not a domain',
                // Duplicate of the first entry once normalized.
                'new-store.com',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.invalid', ['not a domain']);

        // Stored in the exact form the Python worker writes.
        $this->assertDatabaseHas('websites', [
            'domain' => 'new-store.com',
            'crawl_status' => 'pending',
            'source' => 'admin',
        ]);
        $this->assertDatabaseHas('websites', ['domain' => 'xn--mnchen-3ya.example']);

        // Queued rows must be immediately due, or the worker will not claim them.
        $queued = Website::where('domain', 'new-store.com')->firstOrFail();
        $this->assertTrue($queued->next_crawl_at->lessThanOrEqualTo(now()));

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_leads_enqueued',
        ]);
    }

    public function test_enqueue_is_idempotent(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $payload = ['domains' => ['repeat-store.com']];

        $this->postJson('/api/v1/admin/leads/enqueue', $payload)
            ->assertOk()
            ->assertJsonPath('data.queued', 1);

        $this->postJson('/api/v1/admin/leads/enqueue', $payload)
            ->assertOk()
            ->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, Website::where('domain', 'repeat-store.com')->count());
    }

    public function test_enqueue_caps_batch_size(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/leads/enqueue', [
            'domains' => array_map(fn (int $i): string => "store-{$i}.com", range(1, 501)),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.domains.0', 'A maximum of 500 domains can be queued per request.');
    }

    /**
     * The ceiling counts consecutive failures, so a blocked domain must have it
     * cleared or the claim predicate will skip it straight after being set due.
     */
    public function test_recrawl_makes_a_blocked_domain_claimable_again(): void
    {
        $admin = $this->createAdmin();
        $site = $this->makeWebsite('blocked-store.com', 'blocked', null);
        DB::table('websites')->where('id', $site->id)->update([
            'crawl_attempts' => 5,
            'last_crawl_error' => 'permanently broken',
        ]);
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/leads/blocked-store.com/recrawl')
            ->assertOk()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.domain', 'blocked-store.com');

        $site->refresh();
        $this->assertSame('pending', $site->crawl_status->value);
        $this->assertSame(0, $site->crawl_attempts);
        $this->assertNull($site->last_crawl_error);
        $this->assertTrue($site->next_crawl_at->lessThanOrEqualTo(now()));
    }

    /**
     * Resetting an in-flight row would strand the worker's claim token and its
     * completion write would be silently discarded.
     */
    public function test_recrawl_leaves_an_in_flight_crawl_alone(): void
    {
        $admin = $this->createAdmin();
        $site = $this->makeWebsite('running-store.com', 'crawling', null);
        DB::table('websites')->where('id', $site->id)->update([
            'claim_token' => '11111111-1111-4111-8111-111111111111',
            'claim_lease_expires_at' => now()->addHour(),
        ]);
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/leads/running-store.com/recrawl')
            ->assertOk()
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.reason', 'A crawl is already in progress for this domain.');

        $site->refresh();
        $this->assertSame('crawling', $site->crawl_status->value);
        $this->assertNotNull($site->claim_token);
    }

    public function test_recrawl_returns_404_for_unknown_domains(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/leads/never-seen.com/recrawl')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Domain not found.');
    }

    public function test_activities_are_listed_newest_first_and_filterable(): void
    {
        $admin = $this->createAdmin();
        $other = $this->createUser('other@example.com');

        DB::table('user_activities')->insert([
            ['user_id' => $other->id, 'type' => 'login', 'description' => 'Older', 'created_at' => now()->subHour()],
            ['user_id' => $admin->id, 'type' => 'password_change', 'description' => 'Newer', 'created_at' => now()],
        ]);

        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/activities')
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Newer')
            ->assertJsonPath('data.0.user_email', $admin->email)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/activities?type=login')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'login');

        $this->getJson('/api/v1/admin/activities?user_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $other->id);
    }

    public function test_activities_cap_page_size(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/activities?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    private function makeWebsite(string $domain, string $status, ?\DateTimeInterface $nextCrawlAt): Website
    {
        return Website::create([
            'domain' => $domain,
            'canonical_url' => 'https://'.$domain,
            'source' => 'test',
            'crawl_status' => $status,
            'next_crawl_at' => $nextCrawlAt,
            'discovered_at' => now(),
        ]);
    }

    private function createAdmin(): User
    {
        $admin = $this->createUser('admin@example.com');
        $admin->assignRole('admin');

        return $admin;
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'name' => 'Dashboard User',
            'email' => $email,
            'password' => 'password123',
        ]);

        app(SubscriptionService::class)->assignFreePlan($user);

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
}
