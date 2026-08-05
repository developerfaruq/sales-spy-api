<?php

namespace Tests\Feature\Payment;

use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTxidTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_id_cannot_be_reused_for_another_payment(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser('first@example.com');
        $otherUser = $this->createUser('second@example.com');
        $txid = str_repeat('a1', 32);

        $this->createOrder($user, $plan, $txid);
        $otherOrder = $this->createOrder($otherUser, $plan);

        Sanctum::actingAs($otherUser->fresh());

        $this->postJson("/api/v1/user/payments/{$otherOrder->id}/txid", [
            'txid' => $txid,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.txid.0',
                'This transaction ID has already been submitted for another payment.'
            );

        $this->assertNull($otherOrder->fresh()->txid);
    }

    public function test_transaction_id_can_be_resubmitted_for_the_same_unresolved_order(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser('same-order@example.com');
        $txid = str_repeat('b2', 32);
        $order = $this->createOrder($user, $plan, $txid);

        Sanctum::actingAs($user->fresh());

        $this->postJson("/api/v1/user/payments/{$order->id}/txid", [
            'txid' => $txid,
        ])->assertOk()
            ->assertJsonPath('data.txid', $txid);
    }

    private function createUser(string $email): User
    {
        return User::create([
            'name' => 'Payment User',
            'email' => $email,
            'password' => 'password123',
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::create([
            'slug' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 5000,
            'yearly_price' => 48000,
            'monthly_quota' => 2000,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function createOrder(User $user, Plan $plan, ?string $txid = null): PaymentOrder
    {
        return PaymentOrder::create([
            'reference' => 'SPY-'.uniqid(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_cycle' => BillingCycle::MONTHLY,
            'amount_usd_cents' => $plan->monthly_price,
            'currency' => 'USDT',
            'network' => 'TRC20',
            'status' => PaymentStatus::AWAITING_VERIFICATION,
            'txid' => $txid,
            'proof_image_url' => 'https://example.com/proof.png',
            'expires_at' => now()->addDay(),
        ]);
    }
}
