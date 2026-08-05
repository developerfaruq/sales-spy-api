<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\CreditService;
use Illuminate\Console\Command;

class ResetSubscriptionCredits extends Command
{
    protected $signature = 'credits:reset-due';

    protected $description = 'Reset credits for subscriptions that reached their monthly billing anniversary';

    public function handle(CreditService $creditService): int
    {
        $processed = 0;

        Subscription::query()
            ->whereIn('status', [
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::CANCELLED,
                SubscriptionStatus::TRIAL,
            ])
            ->whereNotNull('credits_reset_at')
            ->where('credits_reset_at', '<=', now())
            ->where('current_period_end', '>', now())
            ->orderBy('id')
            ->eachById(function (Subscription $subscription) use ($creditService, &$processed): void {
                if ($creditService->resetSubscriptionCredits($subscription)) {
                    $processed++;
                }
            });

        $this->info("Reset credits for {$processed} subscriptions.");

        return self::SUCCESS;
    }
}
