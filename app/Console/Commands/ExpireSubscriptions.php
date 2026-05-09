<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature   = 'subscriptions:expire';
    protected $description = 'Expire subscriptions past their end date and downgrade users';

    public function handle(SubscriptionService $subscriptionService): void
    {
        $this->info('Checking for expired subscriptions...');

        // Find all subscriptions where:
        // - Status is active or cancelled
        // - Current period has ended
        $expired = Subscription::whereIn('status', ['active', 'cancelled'])
            ->where('current_period_end', '<', now())
            ->with('user', 'plan')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired subscriptions found.');
            return;
        }

        foreach ($expired as $subscription) {
            // Skip if already on free plan
            if ($subscription->plan->isFree()) {
                continue;
            }

            $subscriptionService->expireSubscription($subscription);
            $this->info("Expired subscription for user ID: {$subscription->user_id}");
        }

        $this->info("Done. Processed {$expired->count()} expired subscriptions.");
    }
}
