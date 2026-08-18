<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LeadAccessPolicy;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = $this->createPlan('free', 50, 0);
        Role::findOrCreate('admin', 'api');
    }

    public function test_plan_management_requires_the_admin_role(): void
    {
        Sanctum::actingAs($this->createUser('plain@example.com')->fresh());

        $this->getJson('/api/v1/admin/plans')->assertForbidden();
        $this->postJson('/api/v1/admin/plans', [])->assertForbidden();
        $this->putJson("/api/v1/admin/plans/{$this->freePlan->id}", [])->assertForbidden();
        $this->deleteJson("/api/v1/admin/plans/{$this->freePlan->id}")->assertForbidden();
    }

    public function test_admin_list_includes_inactive_plans_and_subscriber_counts(): void
    {
        $hidden = $this->createPlan('legacy', 100, 1);
        $hidden->update(['is_active' => false]);
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $response = $this->getJson('/api/v1/admin/plans')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('legacy', $slugs, 'Admin list must include inactive plans.');

        $free = collect($response->json('data'))->firstWhere('slug', 'free');
        // The admin who was just created holds a free subscription.
        $this->assertSame(1, $free['active_subscriptions_count']);
        $this->assertTrue($free['is_protected']);

        // The public endpoint must still hide inactive plans.
        $this->assertNotContains(
            'legacy',
            collect($this->getJson('/api/v1/plans')->json('data'))->pluck('slug')->all()
        );
    }

    public function test_admin_can_create_a_plan(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'growth',
            'name' => 'Growth',
            'monthly_price' => 4900,
            'yearly_price' => 49000,
            'monthly_quota' => 1000,
            'access_rank' => 1,
            'features' => ['1000 credits', 'Contact data'],
            'sort_order' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'growth')
            ->assertJsonPath('data.access_rank', 1)
            ->assertJsonPath('data.monthly_price_cents', 4900)
            ->assertJsonPath('data.is_protected', false);

        $this->assertDatabaseHas('plans', ['slug' => 'growth', 'access_rank' => 1]);
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_plan_created',
        ]);
    }

    /**
     * A new tier must actually unlock the data its rank implies. Before ranks
     * lived on the row, any slug outside free/basic/pro/enterprise silently fell
     * back to free-tier access.
     */
    public function test_a_new_plan_rank_controls_lead_access(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'growth',
            'name' => 'Growth',
            'monthly_price' => 4900,
            'yearly_price' => 49000,
            'monthly_quota' => 1000,
            'access_rank' => 2,
        ])->assertCreated();

        $growth = Plan::where('slug', 'growth')->firstOrFail();
        $subscriber = $this->createUser('growth-user@example.com');
        Subscription::where('user_id', $subscriber->id)->delete();
        Subscription::create([
            'user_id' => $subscriber->id,
            'plan_id' => $growth->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $policy = app(LeadAccessPolicy::class);
        $fresh = $subscriber->fresh();

        $this->assertTrue($policy->canViewContacts($fresh));
        $this->assertTrue($policy->canViewProducts($fresh));
        $this->assertTrue($policy->canRequestDeepScan($fresh), 'Rank 2 must unlock deep scans.');
    }

    public function test_plan_slug_must_be_unique_and_well_formed(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'free',
            'name' => 'Duplicate',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => 10,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.slug.0', 'A plan with this slug already exists.');

        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'Not Valid',
            'name' => 'Bad Slug',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => 10,
        ])->assertUnprocessable();

        // -1 means unlimited; anything lower is meaningless.
        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'negative',
            'name' => 'Negative',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => -5,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.monthly_quota.0', 'Monthly quota must be -1 for unlimited, or 0 and above.');
    }

    public function test_admin_can_update_a_plan_partially(): void
    {
        $basic = $this->createPlan('basic', 500, 1);
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->putJson("/api/v1/admin/plans/{$basic->id}", ['monthly_price' => 2900])
            ->assertOk()
            ->assertJsonPath('data.monthly_price_cents', 2900)
            // Untouched fields must survive a partial update.
            ->assertJsonPath('data.monthly_quota', 500)
            ->assertJsonPath('data.name', 'Basic');

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_plan_updated',
        ]);
    }

    /**
     * SubscriptionService::assignFreePlan resolves the free plan by slug on every
     * registration, so renaming that slug would break signup.
     */
    public function test_free_plan_slug_cannot_be_changed(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->putJson("/api/v1/admin/plans/{$this->freePlan->id}", [
            'slug' => 'starter',
            'name' => 'Starter',
        ])->assertOk();

        $this->freePlan->refresh();
        $this->assertSame('free', $this->freePlan->slug, 'The free slug must be immutable.');
        // Other fields on the protected plan are still editable.
        $this->assertSame('Starter', $this->freePlan->name);

        // Registration must still resolve the free plan.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'After Rename',
            'email' => 'after@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }

    public function test_free_plan_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->deleteJson("/api/v1/admin/plans/{$this->freePlan->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'The free plan cannot be deleted because new registrations are assigned to it.');

        $this->assertDatabaseHas('plans', ['slug' => 'free']);
    }

    public function test_plan_with_subscriptions_cannot_be_deleted(): void
    {
        $pro = $this->createPlan('pro', 2000, 2);
        $subscriber = $this->createUser('pro-user@example.com');
        Subscription::create([
            'user_id' => $subscriber->id,
            'plan_id' => $pro->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->deleteJson("/api/v1/admin/plans/{$pro->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This plan still has subscriptions. Deactivate it instead so existing subscribers are unaffected.');

        $this->assertDatabaseHas('plans', ['id' => $pro->id]);
    }

    /**
     * Billing history must not be orphaned even when nobody is subscribed.
     */
    public function test_plan_referenced_by_a_payment_order_cannot_be_deleted(): void
    {
        $legacy = $this->createPlan('legacy', 100, 1);
        $buyer = $this->createUser('buyer@example.com');
        PaymentOrder::create([
            'reference' => 'REF-LEGACY',
            'user_id' => $buyer->id,
            'plan_id' => $legacy->id,
            'billing_cycle' => 'monthly',
            'amount_usd_cents' => 1000,
            'currency' => 'USD',
            'network' => 'trc20',
            'status' => PaymentStatus::APPROVED,
            'expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->deleteJson("/api/v1/admin/plans/{$legacy->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This plan is referenced by payment orders and must be kept for billing history. Deactivate it instead.');
    }

    public function test_unused_plan_can_be_deleted(): void
    {
        $unused = $this->createPlan('unused', 100, 1);
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->deleteJson("/api/v1/admin/plans/{$unused->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Plan deleted successfully');

        $this->assertDatabaseMissing('plans', ['id' => $unused->id]);
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_plan_deleted',
        ]);
    }

    public function test_deactivating_a_plan_hides_it_without_touching_subscribers(): void
    {
        $pro = $this->createPlan('pro', 2000, 2);
        $subscriber = $this->createUser('keeps-access@example.com');
        Subscription::create([
            'user_id' => $subscriber->id,
            'plan_id' => $pro->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->putJson("/api/v1/admin/plans/{$pro->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Hidden from the public catalogue...
        $this->assertNotContains(
            'pro',
            collect($this->getJson('/api/v1/plans')->json('data'))->pluck('slug')->all()
        );
        // ...but the existing subscription is untouched.
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $subscriber->id,
            'plan_id' => $pro->id,
            'status' => 'active',
        ]);
    }

    public function test_unknown_plan_returns_not_found(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->putJson('/api/v1/admin/plans/999999', ['name' => 'Ghost'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Plan not found.');

        $this->deleteJson('/api/v1/admin/plans/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Plan not found.');
    }

    /**
     * Creating a plan without an explicit rank falls back to the slug default, and
     * to 0 for an unrecognised slug — never to an unresolvable state.
     */
    public function test_access_rank_defaults_by_slug(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'enterprise',
            'name' => 'Enterprise',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => -1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.access_rank', 3)
            ->assertJsonPath('data.unlimited_credits', true);

        $this->postJson('/api/v1/admin/plans', [
            'slug' => 'brand-new-tier',
            'name' => 'Brand New',
            'monthly_price' => 100,
            'yearly_price' => 1000,
            'monthly_quota' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('data.access_rank', 0);
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
            'name' => 'Plan User',
            'email' => $email,
            'password' => 'password123',
        ]);

        app(SubscriptionService::class)->assignFreePlan($user);

        return $user->fresh();
    }

    private function createPlan(string $slug, int $quota, int $rank): Plan
    {
        return Plan::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => $quota,
            'access_rank' => $rank,
            'features' => [],
            'is_active' => true,
            'sort_order' => $rank,
        ]);
    }
}
