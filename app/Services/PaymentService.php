<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentReviewException;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected CloudinaryService $cloudinaryService,
        protected ActivityService $activityService,
    ) {}

    /**
     * Generate a unique human-readable order reference.
     * Format: SPY-2026-00042
     */
    private function generateReference(): string
    {
        do {
            $reference = 'SPY-'.date('Y').'-'.str_pad(
                random_int(1, 99999),
                5,
                '0',
                STR_PAD_LEFT
            );
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Create a new payment order.
     * Returns the order with wallet address and amount to pay.
     */
    public function initiatePayment(
        User $user,
        Plan $plan,
        string $billingCycle
    ): array {
        // Cancel any existing pending orders for this user
        // so they don't have multiple open orders
        PaymentOrder::where('user_id', $user->id)
            ->where('status', PaymentStatus::PENDING)
            ->update(['status' => PaymentStatus::EXPIRED]);

        $amountCents = $billingCycle === 'yearly'
            ? $plan->yearly_price
            : $plan->monthly_price;

        $expiryHours = Setting::get('payment_order_expiry_hours', 24);

        $order = PaymentOrder::create([
            'reference' => $this->generateReference(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'amount_usd_cents' => $amountCents,
            'currency' => Setting::get('crypto_currency', 'USDT'),
            'network' => Setting::get('crypto_network', 'TRC20'),
            'status' => PaymentStatus::PENDING,
            'expires_at' => now()->addHours($expiryHours),
        ]);

        $this->activityService->log(
            userId: $user->id,
            type: 'payment_initiated',
            description: "Payment order {$order->reference} created for {$plan->name} plan",
        );

        return [
            'order' => $order,
            'wallet_address' => Setting::get('crypto_wallet_address'),
            'network' => $order->network,
            'currency' => $order->currency,
            'amount' => $order->amount_in_dollars,
        ];
    }

    /**
     * Upload a payment proof screenshot.
     * User can update the screenshot multiple times
     * as long as the order is not yet approved.
     */
    public function uploadProof(
        PaymentOrder $order,
        UploadedFile $file
    ): PaymentOrder {
        // Delete old proof image if one exists
        if ($order->proof_image_public_id) {
            $this->cloudinaryService->deleteImage($order->proof_image_public_id);
        }

        try {
            $uploaded = $this->cloudinaryService->uploadImage(
                $file->getRealPath(),
                'payment-proofs'
            );
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload proof image. Please try again.');
        }

        $order->update([
            'proof_image_url' => $uploaded['url'],
            'proof_image_public_id' => $uploaded['public_id'],
        ]);

        return $order->fresh();
    }

    /**
     * Submit a transaction hash (TXID) for an order.
     * Moves the order to awaiting_verification status.
     */
    public function submitTxid(
        PaymentOrder $order,
        string $txid
    ): PaymentOrder {
        $order->update([
            'txid' => trim($txid),
            'status' => PaymentStatus::AWAITING_VERIFICATION,
        ]);

        $this->activityService->log(
            userId: $order->user_id,
            type: 'payment_submitted',
            description: "TXID submitted for order {$order->reference}",
        );

        return $order->fresh();
    }

    public function reviewPayment(
        int $orderId,
        User $admin,
        PaymentStatus $decision,
        ?string $rejectionReason = null
    ): PaymentOrder {
        return DB::transaction(function () use ($orderId, $admin, $decision, $rejectionReason): PaymentOrder {
            $order = PaymentOrder::whereKey($orderId)
                ->with(['user', 'plan'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== PaymentStatus::AWAITING_VERIFICATION) {
                throw new PaymentReviewException('Only payments awaiting verification can be reviewed.');
            }

            if (! in_array($decision, [PaymentStatus::APPROVED, PaymentStatus::REJECTED], true)) {
                throw new PaymentReviewException('Payment decision must be approved or rejected.');
            }

            if ($decision === PaymentStatus::APPROVED) {
                if (! $order->txid || ! $order->proof_image_url) {
                    throw new PaymentReviewException('Payment proof and TXID are required before approval.');
                }

                $this->subscriptionService->activateSubscription(
                    $order->user,
                    $order->plan,
                    $order->billing_cycle->value
                );
            }

            $order->update([
                'status' => $decision,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $decision === PaymentStatus::REJECTED
                    ? trim((string) $rejectionReason)
                    : null,
            ]);

            $this->activityService->log(
                userId: $order->user_id,
                type: $decision === PaymentStatus::APPROVED
                    ? 'payment_approved'
                    : 'payment_rejected',
                description: $decision === PaymentStatus::APPROVED
                    ? "Payment order {$order->reference} approved - {$order->plan->name} plan activated"
                    : "Payment order {$order->reference} rejected",
                metadata: [
                    'payment_order_id' => $order->id,
                    'reviewed_by' => $admin->id,
                ]
            );

            return $order->fresh(['user', 'plan', 'reviewer']);
        });
    }
}
