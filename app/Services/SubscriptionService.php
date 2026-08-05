<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\CreditTransactionType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(
        protected CreditService $creditService
    ) {}

    /**
     * Assign the free plan to a newly registered user.
     * Called from AuthService after registration.
     */
    public function assignFreePlan(User $user): Subscription
    {
        $freePlan = Plan::where('slug', 'free')->firstOrFail();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $freePlan->id,
            'billing_cycle' => BillingCycle::MONTHLY,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'credits_reset_at' => now()->addMonth(),
        ]);

        $this->creditService->grantPlanCredits(
            user: $user,
            plan: $freePlan,
            type: CreditTransactionType::SUBSCRIPTION_GRANT,
            description: 'Free plan credits granted',
            idempotencyKey: "subscription:{$subscription->id}:grant",
            referenceType: Subscription::class,
            referenceId: $subscription->id
        );

        return $subscription;
    }

    /**
     * Activate a subscription after a payment is verified.
     * Called from PaymentService in Phase 5.
     *
     * @param  string  $billingCycle  monthly|yearly
     */
    public function activateSubscription(
        User $user,
        Plan $plan,
        string $billingCycle
    ): Subscription {
        return DB::transaction(function () use ($user, $plan, $billingCycle): Subscription {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->cancelExistingSubscription($lockedUser);

            $periodEnd = $billingCycle === BillingCycle::YEARLY->value
                ? now()->addYear()
                : now()->addMonth();

            $subscription = Subscription::create([
                'user_id' => $lockedUser->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'status' => SubscriptionStatus::ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $periodEnd,
                'credits_reset_at' => $billingCycle === BillingCycle::YEARLY->value
                    ? now()->addMonthNoOverflow()
                    : $periodEnd,
            ]);

            $this->creditService->grantPlanCredits(
                user: $lockedUser,
                plan: $plan,
                type: CreditTransactionType::SUBSCRIPTION_GRANT,
                description: "{$plan->name} plan credits granted",
                idempotencyKey: "subscription:{$subscription->id}:grant",
                referenceType: Subscription::class,
                referenceId: $subscription->id
            );

            return $subscription;
        });
    }

    /**
     * Cancel a user's active subscription.
     * Access continues until current_period_end.
     */
    public function cancelSubscription(User $user): bool
    {
        $subscription = $user->activeSubscription;

        if (! $subscription
            || $subscription->plan->isFree()
            || ! in_array($subscription->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::TRIAL], true)
        ) {
            return false;
        }

        $subscription->update([
            'status' => SubscriptionStatus::CANCELLED,
            'cancelled_at' => now(),
            'expires_at' => $subscription->current_period_end,
        ]);

        return true;
    }

    /**
     * Expire a subscription and downgrade user to free plan.
     * Called by the daily scheduled job.
     */
    public function expireSubscription(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $lockedSubscription = Subscription::whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedSubscription->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED], true)) {
                return;
            }

            $lockedSubscription->update([
                'status' => SubscriptionStatus::EXPIRED,
                'expires_at' => now(),
            ]);

            $hasCurrentSubscription = Subscription::where('user_id', $lockedSubscription->user_id)
                ->whereKeyNot($lockedSubscription->id)
                ->whereIn('status', [
                    SubscriptionStatus::ACTIVE,
                    SubscriptionStatus::CANCELLED,
                    SubscriptionStatus::TRIAL,
                ])
                ->where('current_period_end', '>', now())
                ->exists();

            if (! $hasCurrentSubscription) {
                $this->assignFreePlan($lockedSubscription->user);
            }
        });
    }

    public function renewFreeSubscription(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $lockedSubscription = Subscription::whereKey($subscription->id)
                ->lockForUpdate()
                ->with('plan', 'user')
                ->firstOrFail();

            if (! $lockedSubscription->plan->isFree()
                || ! in_array($lockedSubscription->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED], true)
            ) {
                return;
            }

            $hasCurrentSubscription = Subscription::where('user_id', $lockedSubscription->user_id)
                ->whereKeyNot($lockedSubscription->id)
                ->whereIn('status', [
                    SubscriptionStatus::ACTIVE,
                    SubscriptionStatus::CANCELLED,
                    SubscriptionStatus::TRIAL,
                ])
                ->where('current_period_end', '>', now())
                ->exists();

            if ($hasCurrentSubscription) {
                $lockedSubscription->update([
                    'status' => SubscriptionStatus::EXPIRED,
                    'expires_at' => now(),
                ]);

                return;
            }

            $resetAt = $lockedSubscription->credits_reset_at
                ?? $lockedSubscription->current_period_end;

            $newPeriodEnd = now()->addMonth();

            $lockedSubscription->update([
                'status' => SubscriptionStatus::ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $newPeriodEnd,
                'credits_reset_at' => $newPeriodEnd,
                'cancelled_at' => null,
                'expires_at' => null,
            ]);

            $this->creditService->grantPlanCredits(
                user: $lockedSubscription->user,
                plan: $lockedSubscription->plan,
                type: CreditTransactionType::MONTHLY_RESET,
                description: 'Monthly Free plan credit reset',
                idempotencyKey: "subscription:{$lockedSubscription->id}:reset:{$resetAt->timestamp}",
                referenceType: Subscription::class,
                referenceId: $lockedSubscription->id
            );
        });
    }

    /**
     * Reset a user's credit balance at the start of a new billing period.
     * Called monthly by the scheduled job.
     */
    public function resetMonthlyCredits(User $user): void
    {
        $subscription = $user->activeSubscription;

        if ($subscription) {
            $this->creditService->resetSubscriptionCredits($subscription);
        }
    }

    /**
     * Cancel existing active subscriptions before activating a new one.
     */
    private function cancelExistingSubscription(User $user): void
    {
        $user->subscriptions()
            ->whereIn('status', [
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::CANCELLED,
                SubscriptionStatus::TRIAL,
            ])
            ->update([
                'status' => SubscriptionStatus::CANCELLED,
                'cancelled_at' => now(),
                'expires_at' => now(),
            ]);
    }
}
