<?php

namespace App\Services;

use App\Enums\CreditTransactionType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditService
{
    public const COST_SETTINGS = [
        'website_access' => 'credit_cost_website_access',
        'search_result' => 'credit_cost_search_result',
        'export_row' => 'credit_cost_export_row',
        'deep_scan' => 'credit_cost_deep_scan',
    ];

    public function spend(
        User $user,
        int $amount,
        string $description,
        ?string $referenceType = null,
        string|int|null $referenceId = null,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): CreditTransaction {
        $this->ensurePositiveAmount($amount);

        return DB::transaction(function () use (
            $user,
            $amount,
            $description,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $metadata
        ): CreditTransaction {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($existing = $this->findIdempotentTransaction($lockedUser, $idempotencyKey)) {
                return $existing;
            }

            if ($lockedUser->hasUnlimitedCredits()) {
                return $this->record(
                    user: $lockedUser,
                    type: CreditTransactionType::SPEND,
                    amount: 0,
                    balanceBefore: $lockedUser->credits_balance,
                    balanceAfter: $lockedUser->credits_balance,
                    description: $description,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    idempotencyKey: $idempotencyKey,
                    metadata: [...$metadata, 'requested_amount' => $amount, 'unlimited' => true]
                );
            }

            if ($lockedUser->credits_balance < $amount) {
                throw new InsufficientCreditsException('Insufficient credits. Please upgrade your plan.');
            }

            $balanceBefore = $lockedUser->credits_balance;
            $balanceAfter = $balanceBefore - $amount;
            $lockedUser->update(['credits_balance' => $balanceAfter]);

            return $this->record(
                user: $lockedUser,
                type: CreditTransactionType::SPEND,
                amount: -$amount,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                description: $description,
                referenceType: $referenceType,
                referenceId: $referenceId,
                idempotencyKey: $idempotencyKey,
                metadata: $metadata
            );
        });
    }

    public function add(
        User $user,
        int $amount,
        string $description,
        CreditTransactionType $type = CreditTransactionType::ADMIN_ADJUSTMENT,
        ?string $referenceType = null,
        string|int|null $referenceId = null,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): CreditTransaction {
        $this->ensurePositiveAmount($amount);

        if (! in_array($type, [CreditTransactionType::REFUND, CreditTransactionType::ADMIN_ADJUSTMENT], true)) {
            throw new InvalidArgumentException('Credit additions must be refunds or admin adjustments.');
        }

        return DB::transaction(function () use (
            $user,
            $amount,
            $description,
            $type,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $metadata
        ): CreditTransaction {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($existing = $this->findIdempotentTransaction($lockedUser, $idempotencyKey)) {
                return $existing;
            }

            $balanceBefore = $lockedUser->credits_balance;
            $balanceAfter = $lockedUser->hasUnlimitedCredits()
                ? $balanceBefore
                : $balanceBefore + $amount;

            if ($balanceAfter !== $balanceBefore) {
                $lockedUser->update(['credits_balance' => $balanceAfter]);
            }

            return $this->record(
                user: $lockedUser,
                type: $type,
                amount: $balanceAfter - $balanceBefore,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                description: $description,
                referenceType: $referenceType,
                referenceId: $referenceId,
                idempotencyKey: $idempotencyKey,
                metadata: $lockedUser->hasUnlimitedCredits()
                    ? [...$metadata, 'requested_amount' => $amount, 'unlimited' => true]
                    : $metadata
            );
        });
    }

    public function refund(
        User $user,
        int $amount,
        string $description,
        ?string $referenceType = null,
        string|int|null $referenceId = null,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): CreditTransaction {
        return $this->add(
            user: $user,
            amount: $amount,
            description: $description,
            type: CreditTransactionType::REFUND,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata
        );
    }

    public function grantPlanCredits(
        User $user,
        Plan $plan,
        CreditTransactionType $type,
        string $description,
        string $idempotencyKey,
        ?string $referenceType = null,
        string|int|null $referenceId = null
    ): CreditTransaction {
        if (! in_array($type, [CreditTransactionType::SUBSCRIPTION_GRANT, CreditTransactionType::MONTHLY_RESET], true)) {
            throw new InvalidArgumentException('Plan credits must be a subscription grant or monthly reset.');
        }

        return DB::transaction(function () use (
            $user,
            $plan,
            $type,
            $description,
            $idempotencyKey,
            $referenceType,
            $referenceId
        ): CreditTransaction {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($existing = $this->findIdempotentTransaction($lockedUser, $idempotencyKey)) {
                return $existing;
            }

            $balanceBefore = $lockedUser->credits_balance;
            $balanceAfter = $plan->monthly_quota === -1 ? 0 : $plan->monthly_quota;

            $lockedUser->update([
                'credits_balance' => $balanceAfter,
                'credits_monthly_quota' => $plan->monthly_quota,
            ]);

            return $this->record(
                user: $lockedUser,
                type: $type,
                amount: $balanceAfter - $balanceBefore,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                description: $description,
                referenceType: $referenceType,
                referenceId: $referenceId,
                idempotencyKey: $idempotencyKey,
                metadata: [
                    'plan' => $plan->slug,
                    'quota' => $plan->monthly_quota,
                    'unlimited' => $plan->monthly_quota === -1,
                ]
            );
        });
    }

    public function resetSubscriptionCredits(Subscription $subscription): ?CreditTransaction
    {
        return DB::transaction(function () use ($subscription): ?CreditTransaction {
            $lockedSubscription = Subscription::whereKey($subscription->id)
                ->with(['user', 'plan'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedSubscription->credits_reset_at
                || $lockedSubscription->credits_reset_at->isFuture()
                || ! $lockedSubscription->current_period_end->isFuture()
                || ! in_array($lockedSubscription->status, [
                    SubscriptionStatus::ACTIVE,
                    SubscriptionStatus::CANCELLED,
                    SubscriptionStatus::TRIAL,
                ], true)
            ) {
                return null;
            }

            $resetAt = $lockedSubscription->credits_reset_at;
            $transaction = $this->grantPlanCredits(
                user: $lockedSubscription->user,
                plan: $lockedSubscription->plan,
                type: CreditTransactionType::MONTHLY_RESET,
                description: "Monthly {$lockedSubscription->plan->name} plan credit reset",
                idempotencyKey: "subscription:{$lockedSubscription->id}:reset:{$resetAt->timestamp}",
                referenceType: Subscription::class,
                referenceId: $lockedSubscription->id
            );

            $nextReset = $resetAt->copy()->addMonthNoOverflow();
            $lockedSubscription->update([
                'credits_reset_at' => $nextReset->lt($lockedSubscription->current_period_end)
                    ? $nextReset
                    : $lockedSubscription->current_period_end,
            ]);

            return $transaction;
        });
    }

    public function canAfford(User $user, int $amount): bool
    {
        return $amount > 0 && ($user->hasUnlimitedCredits() || $user->credits_balance >= $amount);
    }

    public function getCost(string $action): int
    {
        if (! isset(self::COST_SETTINGS[$action])) {
            throw new InvalidArgumentException("Unknown credit action: {$action}");
        }

        return Setting::get(self::COST_SETTINGS[$action], 0);
    }

    public function getCosts(): array
    {
        return collect(self::COST_SETTINGS)
            ->mapWithKeys(fn (string $setting, string $action) => [
                $action => Setting::get($setting, 0),
            ])
            ->all();
    }

    private function record(
        User $user,
        CreditTransactionType $type,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        string $description,
        ?string $referenceType,
        string|int|null $referenceId,
        ?string $idempotencyKey,
        array $metadata
    ): CreditTransaction {
        return CreditTransaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId === null ? null : (string) $referenceId,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function findIdempotentTransaction(User $user, ?string $idempotencyKey): ?CreditTransaction
    {
        if (! $idempotencyKey) {
            return null;
        }

        $transaction = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($transaction && $transaction->user_id !== $user->id) {
            throw new InvalidArgumentException('The idempotency key is already used by another user.');
        }

        return $transaction;
    }

    private function ensurePositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount must be greater than zero.');
        }
    }
}
