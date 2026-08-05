<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    private Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $this->freePlan = $this->createPlan('free', 50);
        $this->proPlan = $this->createPlan('pro', 2000, 5000, 48000);
    }

    public function test_non_admin_users_cannot_list_or_review_payments(): void
    {
        $user = $this->createUser('regular@example.com');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/payments')
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Admin access required.');

        $order = $this->createOrder($user, PaymentStatus::AWAITING_VERIFICATION);

        $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_admin_can_list_filter_and_search_payment_orders(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser('buyer@example.com', 'Buyer User');
        $awaiting = $this->createOrder($user, PaymentStatus::AWAITING_VERIFICATION);
        $awaiting->update(['reference' => 'SPY-SEARCH-ME']);
        $this->createOrder($user, PaymentStatus::REJECTED);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/payments?status=awaiting_verification&search=SEARCH-ME&per_page=1');

        $response->assertOk()
            ->assertJsonPath('data.0.order_id', $awaiting->id)
            ->assertJsonPath('data.0.user.email', 'buyer@example.com')
            ->assertJsonPath('data.0.plan.slug', 'pro')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_invalid_payment_list_filter_returns_the_standard_validation_response(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/payments?status=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_admin_can_approve_payment_and_activate_the_purchased_subscription(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser('approval@example.com');
        $this->createFreeSubscription($user);
        $order = $this->createOrder($user, PaymentStatus::AWAITING_VERIFICATION);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Payment approved and subscription activated successfully')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewer.id', $admin->id);

        $reviewedOrder = $order->fresh();
        $user = $user->fresh();

        $this->assertSame(PaymentStatus::APPROVED, $reviewedOrder->status);
        $this->assertSame($admin->id, $reviewedOrder->reviewed_by);
        $this->assertSame('pro', $user->currentPlanSlug());
        $this->assertSame(2000, $user->credits_balance);
        $this->assertSame(2000, $user->credits_monthly_quota);
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'payment_approved',
        ]);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'subscription_grant',
            'balance_after' => 2000,
        ]);
    }

    public function test_approval_is_rejected_when_proof_or_txid_is_missing(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser('missing-proof@example.com');
        $order = $this->createOrder($user, PaymentStatus::AWAITING_VERIFICATION);
        $order->update(['txid' => null]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'approved',
        ])->assertConflict()
            ->assertJsonPath('message', 'Payment proof and TXID are required before approval.');

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $order->fresh()->status);
    }

    public function test_admin_can_reject_payment_with_a_reason_without_changing_subscription(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser('rejection@example.com');
        $subscription = $this->createFreeSubscription($user);
        $order = $this->createOrder($user, PaymentStatus::AWAITING_VERIFICATION);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'rejected',
            'rejection_reason' => 'The transaction could not be verified.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The transaction could not be verified.');

        $this->assertSame(PaymentStatus::REJECTED, $order->fresh()->status);
        $this->assertSame($subscription->id, $user->fresh()->activeSubscription?->id);
        $this->assertSame('free', $user->fresh()->currentPlanSlug());
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'payment_rejected',
        ]);
    }

    public function test_rejection_requires_a_reason_and_approval_rejects_a_reason(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser('validation@example.com');
        $order = $this->createOrder($user, PaymentStatus::AWAITING_VERIFICATION);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'rejected',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.rejection_reason.0', 'A rejection reason is required when rejecting a payment.');

        $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'approved',
            'rejection_reason' => 'Should not be sent',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.rejection_reason.0', 'A rejection reason cannot be provided when approving a payment.');
    }

    public function test_resolved_payment_cannot_be_reviewed_again(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser('resolved@example.com');
        $order = $this->createOrder($user, PaymentStatus::REJECTED);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/payments/{$order->id}/review", [
            'decision' => 'approved',
        ])->assertConflict()
            ->assertJsonPath('message', 'Only payments awaiting verification can be reviewed.');
    }

    public function test_unknown_payment_returns_not_found(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/payments/999999/review', [
            'decision' => 'approved',
        ])->assertNotFound()
            ->assertJsonPath('message', 'Payment order not found.');
    }

    private function createAdmin(): User
    {
        $admin = $this->createUser('admin-'.uniqid().'@example.com', 'Admin User');
        $admin->assignRole('admin');

        return $admin->fresh();
    }

    private function createUser(string $email, string $name = 'Payment User'): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
        ])->fresh();
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

    private function createFreeSubscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->freePlan->id,
            'billing_cycle' => BillingCycle::MONTHLY,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function createOrder(User $user, PaymentStatus $status): PaymentOrder
    {
        return PaymentOrder::create([
            'reference' => 'SPY-'.uniqid(),
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'billing_cycle' => BillingCycle::MONTHLY,
            'amount_usd_cents' => $this->proPlan->monthly_price,
            'currency' => 'USDT',
            'network' => 'TRC20',
            'status' => $status,
            'txid' => bin2hex(random_bytes(32)),
            'proof_image_url' => 'https://example.com/proof.png',
            'expires_at' => now()->addDay(),
        ]);
    }
}
