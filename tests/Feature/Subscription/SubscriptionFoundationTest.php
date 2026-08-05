<?php

namespace Tests\Feature\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    public function test_assigning_the_free_plan_uses_the_plan_credit_quota(): void
    {
        $free = $this->createPlan('free', 50);
        $user = $this->createUser();

        $subscription = $this->service->assignFreePlan($user);

        $this->assertTrue($subscription->plan->is($free));
        $this->assertSame(50, $user->fresh()->credits_balance);
        $this->assertSame(50, $user->fresh()->credits_monthly_quota);
    }

    public function test_free_subscription_cannot_be_cancelled(): void
    {
        $this->createPlan('free', 50);
        $user = $this->createUser();
        $this->service->assignFreePlan($user);

        $this->assertFalse($this->service->cancelSubscription($user->fresh()));
    }

    public function test_cancelled_paid_subscription_keeps_access_until_period_end(): void
    {
        $this->createPlan('free', 50);
        $basic = $this->createPlan('basic', 500, 2000, 19200);
        $user = $this->createUser();
        $this->service->assignFreePlan($user);
        $paid = $this->service->activateSubscription($user, $basic, BillingCycle::MONTHLY->value);

        $this->assertTrue($this->service->cancelSubscription($user->fresh()));

        $cancelled = $paid->fresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->isActive());
    }

    public function test_expired_free_subscription_is_renewed_and_credits_are_reset(): void
    {
        $this->createPlan('free', 50);
        $user = $this->createUser();
        $free = $this->service->assignFreePlan($user);
        $free->update([
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subMonth(),
        ]);
        $user->update(['credits_balance' => 0]);

        $this->service->renewFreeSubscription($free);

        $renewed = $free->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $renewed->status);
        $this->assertTrue($renewed->current_period_end->isFuture());
        $this->assertSame(50, $user->fresh()->credits_balance);
    }

    public function test_expiring_an_old_paid_subscription_does_not_override_a_newer_plan(): void
    {
        $free = $this->createPlan('free', 50);
        $basic = $this->createPlan('basic', 500, 2000, 19200);
        $pro = $this->createPlan('pro', 2000, 5000, 48000);
        $user = $this->createUser();

        $old = $this->createSubscription($user, $basic, now()->subMonth());
        $this->createSubscription($user, $pro, now()->addMonth());
        $user->update([
            'credits_balance' => 2000,
            'credits_monthly_quota' => 2000,
        ]);

        $this->service->expireSubscription($old);

        $this->assertSame(SubscriptionStatus::EXPIRED, $old->fresh()->status);
        $this->assertSame(2000, $user->fresh()->credits_balance);
        $this->assertFalse($user->subscriptions()->where('plan_id', $free->id)->exists());
        $this->assertSame('pro', $user->fresh()->currentPlanSlug());
    }

    public function test_expiring_the_only_paid_subscription_downgrades_to_free(): void
    {
        $this->createPlan('free', 50);
        $basic = $this->createPlan('basic', 500, 2000, 19200);
        $user = $this->createUser();
        $paid = $this->createSubscription($user, $basic, now()->subDay());

        $this->service->expireSubscription($paid);

        $this->assertSame('free', $user->fresh()->currentPlanSlug());
        $this->assertSame(50, $user->fresh()->credits_balance);
    }

    public function test_stale_free_subscription_does_not_reset_a_current_paid_plan(): void
    {
        $free = $this->createPlan('free', 50);
        $pro = $this->createPlan('pro', 2000, 5000, 48000);
        $user = $this->createUser();
        $staleFree = $this->createSubscription($user, $free, now()->subDay());
        $this->createSubscription($user, $pro, now()->addMonth());
        $user->update([
            'credits_balance' => 2000,
            'credits_monthly_quota' => 2000,
        ]);

        $this->service->renewFreeSubscription($staleFree);

        $this->assertSame(SubscriptionStatus::EXPIRED, $staleFree->fresh()->status);
        $this->assertSame(2000, $user->fresh()->credits_balance);
        $this->assertSame('pro', $user->fresh()->currentPlanSlug());
    }

    public function test_expiration_command_renews_an_expired_free_subscription(): void
    {
        $this->createPlan('free', 50);
        $user = $this->createUser();
        $free = $this->service->assignFreePlan($user);
        $free->update([
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subMonth(),
        ]);
        $user->update(['credits_balance' => 0]);

        $this->artisan('subscriptions:expire')
            ->expectsOutputToContain("Renewed free subscription for user ID: {$user->id}")
            ->assertSuccessful();

        $this->assertTrue($free->fresh()->current_period_end->isFuture());
        $this->assertSame(50, $user->fresh()->credits_balance);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Subscription User',
            'email' => uniqid('subscription-', true).'@example.com',
            'password' => 'password123',
        ]);
    }

    private function createPlan(
        string $slug,
        int $quota,
        int $monthlyPrice = 0,
        int $yearlyPrice = 0
    ): Plan {
        return Plan::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'monthly_price' => $monthlyPrice,
            'yearly_price' => $yearlyPrice,
            'monthly_quota' => $quota,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function createSubscription(User $user, Plan $plan, $periodEnd): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_cycle' => BillingCycle::MONTHLY,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $periodEnd,
        ]);
    }
}
