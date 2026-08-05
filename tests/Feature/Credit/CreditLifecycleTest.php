<?php

namespace Tests\Feature\Credit;

use App\Enums\BillingCycle;
use App\Enums\CreditTransactionType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreditService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    private Plan $yearlyPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = $this->createPlan('free', 50);
        $this->yearlyPlan = $this->createPlan('pro', 2000);
    }

    public function test_free_plan_assignment_records_subscription_grant(): void
    {
        $user = $this->createUser();

        $subscription = app(SubscriptionService::class)->assignFreePlan($user);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'subscription_grant',
            'amount' => 50,
            'balance_after' => 50,
            'reference_id' => (string) $subscription->id,
            'idempotency_key' => "subscription:{$subscription->id}:grant",
        ]);
    }

    public function test_yearly_plan_resets_on_monthly_anniversary_and_advances_schedule(): void
    {
        $user = $this->createUser();
        $subscription = $this->createDueYearlySubscription($user);
        $user->update([
            'credits_balance' => 100,
            'credits_monthly_quota' => 2000,
        ]);
        $originalReset = $subscription->credits_reset_at;

        $transaction = app(CreditService::class)->resetSubscriptionCredits($subscription);

        $this->assertSame(CreditTransactionType::MONTHLY_RESET, $transaction->type);
        $this->assertSame(1900, $transaction->amount);
        $this->assertSame(2000, $user->fresh()->credits_balance);
        $this->assertTrue($subscription->fresh()->credits_reset_at->equalTo($originalReset->copy()->addMonthNoOverflow()));
    }

    public function test_reset_command_is_idempotent(): void
    {
        $user = $this->createUser();
        $subscription = $this->createDueYearlySubscription($user);
        $user->update(['credits_balance' => 0, 'credits_monthly_quota' => 2000]);

        $this->artisan('credits:reset-due')
            ->expectsOutput('Reset credits for 1 subscriptions.')
            ->assertSuccessful();

        $this->artisan('credits:reset-due')
            ->expectsOutput('Reset credits for 0 subscriptions.')
            ->assertSuccessful();

        $this->assertSame(1, $user->creditTransactions()->where('type', 'monthly_reset')->count());
        $this->assertSame(2000, $user->fresh()->credits_balance);
        $this->assertTrue($subscription->fresh()->credits_reset_at->isFuture());
    }

    public function test_expired_paid_subscription_downgrade_records_free_plan_grant(): void
    {
        $user = $this->createUser();
        $paid = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->yearlyPlan->id,
            'billing_cycle' => BillingCycle::MONTHLY,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
            'credits_reset_at' => now()->subDay(),
        ]);
        $user->update(['credits_balance' => 100, 'credits_monthly_quota' => 2000]);

        app(SubscriptionService::class)->expireSubscription($paid);

        $freeSubscription = $user->fresh()->activeSubscription;
        $this->assertSame('free', $freeSubscription->plan->slug);
        $this->assertSame(50, $user->fresh()->credits_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'subscription_grant',
            'amount' => -50,
            'balance_before' => 100,
            'balance_after' => 50,
            'reference_id' => (string) $freeSubscription->id,
        ]);
    }

    private function createDueYearlySubscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->yearlyPlan->id,
            'billing_cycle' => BillingCycle::YEARLY,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now()->subMonth()->subDay(),
            'current_period_end' => now()->addMonths(11),
            'credits_reset_at' => now()->subDay(),
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Lifecycle User',
            'email' => uniqid('credit-lifecycle-', true).'@example.com',
            'password' => 'password123',
        ])->fresh();
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
