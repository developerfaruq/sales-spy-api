<?php

namespace Tests\Feature\Credit;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = Plan::create([
            'slug' => 'free',
            'name' => 'Free',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => 50,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_credit_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/user/credits')->assertUnauthorized();
        $this->getJson('/api/v1/user/credits/history')->assertUnauthorized();
    }

    public function test_balance_endpoint_returns_allowance_reset_and_costs(): void
    {
        $user = $this->createSubscribedUser();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/user/credits')
            ->assertOk()
            ->assertJsonPath('data.balance', 50)
            ->assertJsonPath('data.monthly_quota', 50)
            ->assertJsonPath('data.unlimited', false)
            ->assertJsonPath('data.costs.website_access', 1)
            ->assertJsonPath('data.costs.deep_scan', 5)
            ->assertJsonPath('data.next_reset_at', $user->fresh()->activeSubscription->credits_reset_at->toJSON());
    }

    public function test_unlimited_balance_is_returned_without_a_fake_number(): void
    {
        $user = $this->createSubscribedUser();
        $user->update([
            'credits_balance' => 0,
            'credits_monthly_quota' => -1,
        ]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/user/credits')
            ->assertOk()
            ->assertJsonPath('data.balance', null)
            ->assertJsonPath('data.monthly_quota', null)
            ->assertJsonPath('data.unlimited', true);
    }

    public function test_history_is_isolated_filtered_and_paginated(): void
    {
        $user = $this->createSubscribedUser();
        $otherUser = $this->createSubscribedUser();

        $this->createTransaction($user, CreditTransactionType::SPEND, -5, now()->subDay());
        $refund = $this->createTransaction($user, CreditTransactionType::REFUND, 5, now());
        $this->createTransaction($otherUser, CreditTransactionType::REFUND, 5, now());

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/user/credits/history?type=refund&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $refund->id)
            ->assertJsonPath('data.0.type', 'refund')
            ->assertJsonPath('data.0.absolute_amount', 5)
            ->assertJsonPath('data.0.is_deduction', false)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_history_supports_date_range_filters(): void
    {
        $user = $this->createSubscribedUser();
        $old = $this->createTransaction($user, CreditTransactionType::SPEND, -1, now()->subDays(10));
        $current = $this->createTransaction($user, CreditTransactionType::SPEND, -1, now());
        Sanctum::actingAs($user->fresh());

        $response = $this->getJson('/api/v1/user/credits/history?type=spend&from='.now()->subDay()->toDateString().'&to='.now()->toDateString());

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $current->id);
        $this->assertNotSame($old->id, $response->json('data.0.id'));
    }

    public function test_invalid_history_filters_return_standard_validation_errors(): void
    {
        $user = $this->createSubscribedUser();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/user/credits/history?type=invalid&from=bad-date')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed');
    }

    private function createSubscribedUser(): User
    {
        $user = User::create([
            'name' => 'Credit Endpoint User',
            'email' => uniqid('credit-endpoint-', true).'@example.com',
            'password' => 'password123',
        ]);

        app(SubscriptionService::class)->assignFreePlan($user);

        return $user->fresh();
    }

    private function createTransaction(
        User $user,
        CreditTransactionType $type,
        int $amount,
        $createdAt
    ): CreditTransaction {
        $transaction = CreditTransaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => 50,
            'balance_after' => 50 + $amount,
            'description' => 'Test transaction',
        ]);

        $transaction->forceFill(['created_at' => $createdAt])->save();

        return $transaction;
    }
}
