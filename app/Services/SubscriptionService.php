<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;

class SubscriptionService
{
    /**
     * Assign the free plan to a newly registered user.
     * Called from AuthService after registration.
     */
    public function assignFreePlan(User $user): Subscription
    {
        $freePlan = Plan::where('slug', 'free')->firstOrFail();

        return Subscription::create([
            'user_id'              => $user->id,
            'plan_id'              => $freePlan->id,
            'billing_cycle'        => BillingCycle::MONTHLY,
            'status'               => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);
    }

    /**
     * Activate a subscription after a payment is verified.
     * Called from PaymentService in Phase 5.
     *
     * @param User   $user
     * @param Plan   $plan
     * @param string $billingCycle monthly|yearly
     */
    public function activateSubscription(
        User $user,
        Plan $plan,
        string $billingCycle
    ): Subscription {
        // Cancel any existing active subscription
        $this->cancelExistingSubscription($user);

        $periodEnd = $billingCycle === 'yearly'
            ? now()->addYear()
            : now()->addMonth();

        $subscription = Subscription::create([
            'user_id'              => $user->id,
            'plan_id'              => $plan->id,
            'billing_cycle'        => $billingCycle,
            'status'               => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end'   => $periodEnd,
        ]);

        // Reset user credits to new plan's monthly quota
        $user->update([
            'credits_balance'       => $plan->monthly_quota === -1
                ? 99999  // Unlimited represented as large number
                : $plan->monthly_quota,
            'credits_monthly_quota' => $plan->monthly_quota,
        ]);

        return $subscription;
    }

    /**
     * Cancel a user's active subscription.
     * Access continues until current_period_end.
     */
    public function cancelSubscription(User $user): bool
    {
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            return false;
        }

        $subscription->update([
            'status'       => SubscriptionStatus::CANCELLED,
            'cancelled_at' => now(),
            'expires_at'   => $subscription->current_period_end,
        ]);

        return true;
    }

    /**
     * Expire a subscription and downgrade user to free plan.
     * Called by the daily scheduled job.
     */
    public function expireSubscription(Subscription $subscription): void
    {
        $subscription->update([
            'status'     => SubscriptionStatus::EXPIRED,
            'expires_at' => now(),
        ]);

        $freePlan = Plan::where('slug', 'free')->firstOrFail();

        // Assign a new free plan subscription
        Subscription::create([
            'user_id'              => $subscription->user_id,
            'plan_id'              => $freePlan->id,
            'billing_cycle'        => BillingCycle::MONTHLY,
            'status'               => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        // Reset credits to free plan quota
        $subscription->user->update([
            'credits_balance'       => $freePlan->monthly_quota,
            'credits_monthly_quota' => $freePlan->monthly_quota,
        ]);
    }

    /**
     * Reset a user's credit balance at the start of a new billing period.
     * Called monthly by the scheduled job.
     */
    public function resetMonthlyCredits(User $user): void
    {
        $quota = $user->currentMonthlyQuota();

        $user->update([
            'credits_balance' => $quota === -1 ? 99999 : $quota,
        ]);
    }

    /**
     * Cancel existing active subscriptions before activating a new one.
     */
    private function cancelExistingSubscription(User $user): void
    {
        $user->subscriptions()
            ->whereIn('status', ['active', 'trial'])
            ->update([
                'status'       => SubscriptionStatus::CANCELLED,
                'cancelled_at' => now(),
                'expires_at'   => now(),
            ]);
    }
}
