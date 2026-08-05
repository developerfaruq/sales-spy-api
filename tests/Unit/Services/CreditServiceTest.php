<?php

namespace Tests\Unit\Services;

use App\Enums\CreditTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CreditService::class);
    }

    public function test_spend_atomically_deducts_credits_and_records_the_ledger(): void
    {
        $user = $this->createUser(50, 50);

        $transaction = $this->service->spend(
            user: $user,
            amount: 5,
            description: 'Deep store scan',
            referenceType: 'store_scan',
            referenceId: 42,
            idempotencyKey: 'scan:42:charge'
        );

        $this->assertSame(-5, $transaction->amount);
        $this->assertSame(50, $transaction->balance_before);
        $this->assertSame(45, $transaction->balance_after);
        $this->assertTrue($transaction->isDeduction());
        $this->assertSame(5, $transaction->absolute_amount);
        $this->assertSame(45, $user->fresh()->credits_balance);
    }

    public function test_insufficient_balance_does_not_change_balance_or_create_a_transaction(): void
    {
        $user = $this->createUser(2, 50);

        try {
            $this->service->spend($user, 5, 'Deep store scan');
            $this->fail('Expected insufficient credits exception.');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame('Insufficient credits. Please upgrade your plan.', $exception->getMessage());
        }

        $this->assertSame(2, $user->fresh()->credits_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_idempotency_key_prevents_duplicate_spending(): void
    {
        $user = $this->createUser(50, 50);

        $first = $this->service->spend($user, 5, 'Scan', idempotencyKey: 'scan:100:charge');
        $second = $this->service->spend($user, 5, 'Scan', idempotencyKey: 'scan:100:charge');

        $this->assertTrue($first->is($second));
        $this->assertSame(45, $user->fresh()->credits_balance);
        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_idempotency_key_cannot_be_reused_by_another_user(): void
    {
        $firstUser = $this->createUser(50, 50);
        $secondUser = $this->createUser(50, 50);
        $this->service->spend($firstUser, 1, 'Access', idempotencyKey: 'website:1:access');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The idempotency key is already used by another user.');

        $this->service->spend($secondUser, 1, 'Access', idempotencyKey: 'website:1:access');
    }

    public function test_refund_restores_credits_and_records_a_positive_transaction(): void
    {
        $user = $this->createUser(45, 50);

        $transaction = $this->service->refund(
            user: $user,
            amount: 5,
            description: 'Failed scan refund',
            idempotencyKey: 'scan:42:refund'
        );

        $this->assertSame(CreditTransactionType::REFUND, $transaction->type);
        $this->assertSame(5, $transaction->amount);
        $this->assertSame(50, $user->fresh()->credits_balance);
    }

    public function test_unlimited_user_can_spend_without_a_fake_balance(): void
    {
        $user = $this->createUser(0, -1);

        $transaction = $this->service->spend($user, 500, 'Enterprise export');

        $this->assertTrue($user->fresh()->hasUnlimitedCredits());
        $this->assertTrue($this->service->canAfford($user->fresh(), 1000000));
        $this->assertSame(0, $user->fresh()->credits_balance);
        $this->assertSame(0, $transaction->amount);
        $this->assertSame(500, $transaction->metadata['requested_amount']);
        $this->assertTrue($transaction->metadata['unlimited']);
    }

    public function test_plan_grant_replaces_balance_and_is_idempotent(): void
    {
        $user = $this->createUser(10, 50);
        $plan = $this->createPlan('pro', 2000);

        $first = $this->service->grantPlanCredits(
            user: $user,
            plan: $plan,
            type: CreditTransactionType::SUBSCRIPTION_GRANT,
            description: 'Pro plan credits granted',
            idempotencyKey: 'subscription:10:grant'
        );
        $second = $this->service->grantPlanCredits(
            user: $user,
            plan: $plan,
            type: CreditTransactionType::SUBSCRIPTION_GRANT,
            description: 'Pro plan credits granted',
            idempotencyKey: 'subscription:10:grant'
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1990, $first->amount);
        $this->assertSame(2000, $user->fresh()->credits_balance);
        $this->assertSame(2000, $user->fresh()->credits_monthly_quota);
        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_costs_are_loaded_from_settings(): void
    {
        Setting::set('credit_cost_deep_scan', 7, 'integer');

        $this->assertSame(7, $this->service->getCost('deep_scan'));
        $this->assertSame([
            'website_access' => 1,
            'search_result' => 1,
            'export_row' => 2,
            'deep_scan' => 7,
        ], $this->service->getCosts());
    }

    public function test_nonpositive_amounts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->spend($this->createUser(50, 50), 0, 'Invalid spend');
    }

    private function createUser(int $balance, int $quota): User
    {
        $user = User::create([
            'name' => 'Credit User',
            'email' => uniqid('credit-', true).'@example.com',
            'password' => 'password123',
        ]);

        $user->update([
            'credits_balance' => $balance,
            'credits_monthly_quota' => $quota,
        ]);

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
