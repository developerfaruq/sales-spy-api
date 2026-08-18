<?php

namespace App\Services;

use App\Enums\CommercePlatform;
use App\Enums\StoreScanStatus;
use App\Exceptions\StoreScanConflictException;
use App\Models\EcommerceStore;
use App\Models\StoreScanRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoreScanService
{
    public function __construct(
        protected CreditService $creditService
    ) {}

    public function request(User $user, EcommerceStore $store, string $idempotencyKey): StoreScanRequest
    {
        return DB::transaction(function () use ($user, $store, $idempotencyKey): StoreScanRequest {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = StoreScanRequest::where('user_id', $lockedUser->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->ecommerce_store_id !== $store->id) {
                    throw new StoreScanConflictException('The idempotency key is already used for another store.');
                }

                return $existing;
            }

            if ($store->platform !== CommercePlatform::SHOPIFY) {
                throw new StoreScanConflictException('Deep scans are currently supported for Shopify stores only.');
            }

            $creditPeriodTransactionId = $this->creditService->currentPeriodId($lockedUser);

            if (! $creditPeriodTransactionId) {
                throw new StoreScanConflictException('The account has no active credit period.');
            }

            $transaction = $this->creditService->spend(
                user: $lockedUser,
                amount: $this->creditService->getCost('deep_scan'),
                description: "Deep scan requested for {$store->website->domain}",
                referenceType: EcommerceStore::class,
                referenceId: $store->id,
                idempotencyKey: "deep-scan:{$lockedUser->id}:{$idempotencyKey}",
                metadata: ['domain' => $store->website->domain]
            );

            return StoreScanRequest::create([
                'user_id' => $lockedUser->id,
                'ecommerce_store_id' => $store->id,
                'idempotency_key' => $idempotencyKey,
                'status' => StoreScanStatus::QUEUED,
                'credit_transaction_id' => $transaction->id,
                'credit_period_transaction_id' => $creditPeriodTransactionId,
                'requested_at' => now(),
            ]);
        });
    }
}
