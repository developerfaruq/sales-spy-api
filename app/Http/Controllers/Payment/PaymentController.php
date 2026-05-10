<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Requests\Payment\SubmitTxidRequest;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    // ─────────────────────────────────────────────────────────────
    //  Initiate Payment
    // ─────────────────────────────────────────────────────────────

    /**
     * Initiate a payment order
     *
     * Creates a new payment order and returns the wallet address
     * and exact amount the user needs to send.
     * Any existing pending orders for the user are automatically cancelled.
     *
     * @authenticated
     * @group Payments
     *
     * @bodyParam plan_slug string required The plan slug. Example: pro
     * @bodyParam billing_cycle string required monthly or yearly. Example: monthly
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Payment order created successfully",
     *   "data": {
     *     "order_id": 1,
     *     "reference": "SPY-2026-00001",
     *     "plan": "Pro",
     *     "billing_cycle": "monthly",
     *     "amount": 225.00,
     *     "currency": "USDT",
     *     "network": "TRC20",
     *     "wallet_address": "TXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
     *     "status": "pending",
     *     "expires_at": "2026-03-26T12:00:00.000000Z",
     *     "instructions": [
     *       "Send exactly 225.00 USDT (TRC20) to the wallet address above",
     *       "Take a screenshot of the transaction confirmation",
     *       "Upload the screenshot using the proof upload endpoint",
     *       "Submit your transaction hash (TXID) from TronScan",
     *       "Your subscription will be activated after admin verification"
     *     ]
     *   }
     * }
     *
     * @response 400 {
     *   "success": false,
     *   "message": "Cannot purchase the free plan",
     *   "errors": null
     * }
     */
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', statusCode: 401);
        }

        $plan = Plan::where('slug', $request->plan_slug)->firstOrFail();

        // Cannot pay for the free plan
        if ($plan->isFree()) {
            return $this->errorResponse(
                message: 'Cannot purchase the free plan.',
                statusCode: 400
            );
        }

        // Enterprise has no fixed price — contact sales
        if ($plan->slug === 'enterprise') {
            return $this->errorResponse(
                message: 'Enterprise plan requires a custom quote. Please contact us.',
                statusCode: 400
            );
        }

        $result = $this->paymentService->initiatePayment(
            $user,
            $plan,
            $request->billing_cycle
        );

        return $this->successResponse(
            data: [
                'order_id'       => $result['order']->id,
                'reference'      => $result['order']->reference,
                'plan'           => $plan->name,
                'billing_cycle'  => $request->billing_cycle,
                'amount'         => $result['amount'],
                'currency'       => $result['currency'],
                'network'        => $result['network'],
                'wallet_address' => $result['wallet_address'],
                'status'         => $result['order']->status->value,
                'expires_at'     => $result['order']->expires_at,
                'instructions'   => [
                    "Send exactly {$result['amount']} {$result['currency']} ({$result['network']}) to the wallet address above",
                    'Take a screenshot of the transaction confirmation',
                    'Upload the screenshot using the proof upload endpoint',
                    'Submit your transaction hash (TXID) from TronScan',
                    'Your subscription will be activated after admin verification',
                ],
            ],
            message: 'Payment order created successfully',
            statusCode: 201
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Upload Proof
    // ─────────────────────────────────────────────────────────────

    /**
     * Upload payment proof screenshot
     *
     * Upload a screenshot of the transaction confirmation.
     * Can be updated multiple times as long as the order
     * has not been approved or rejected.
     *
     * @authenticated
     * @group Payments
     *
     * @urlParam orderId integer required The payment order ID. Example: 1
     * @bodyParam proof file required Screenshot image. Max 5MB. Accepted: jpg, jpeg, png, webp.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Proof uploaded successfully",
     *   "data": {
     *     "order_id": 1,
     *     "reference": "SPY-2026-00001",
     *     "proof_image_url": "https://res.cloudinary.com/...",
     *     "status": "pending"
     *   }
     * }
     *
     * @response 403 {
     *   "success": false,
     *   "message": "This order cannot accept a proof upload",
     *   "errors": null
     * }
     */
    public function uploadProof(Request $request, int $orderId): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', statusCode: 401);
        }

        $request->validate([
            'proof' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $order = PaymentOrder::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (! $order) {
            return $this->errorResponse(
                message: 'Payment order not found.',
                statusCode: 404
            );
        }

        if (! $order->canSubmitProof()) {
            return $this->errorResponse(
                message: 'This order cannot accept a proof upload.',
                statusCode: 403
            );
        }

        try {
            $order = $this->paymentService->uploadProof($order, $request->file('proof'));
        } catch (\Exception $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: 500
            );
        }

        return $this->successResponse(
            data: $this->formatOrder($order),
            message: 'Proof uploaded successfully'
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Submit TXID
    // ─────────────────────────────────────────────────────────────

    /**
     * Submit transaction hash
     *
     * Submit the TXID (transaction hash) from TronScan after sending payment.
     * The order status will change to awaiting_verification and
     * the admin will be notified to review.
     *
     * @authenticated
     * @group Payments
     *
     * @urlParam orderId integer required The payment order ID. Example: 1
     * @bodyParam txid string required The 64-character hex transaction hash from TronScan. Example: a1b2c3d4e5f6...
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Transaction submitted. Your payment is being reviewed.",
     *   "data": {
     *     "order_id": 1,
     *     "reference": "SPY-2026-00001",
     *     "status": "awaiting_verification",
     *     "txid": "a1b2c3d4e5f6..."
     *   }
     * }
     *
     * @response 422 {
     *   "success": false,
     *   "message": "Please upload a proof screenshot before submitting your TXID.",
     *   "errors": null
     * }
     */
    public function submitTxid(SubmitTxidRequest $request, int $orderId): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', statusCode: 401);
        }

        $order = PaymentOrder::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (! $order) {
            return $this->errorResponse(
                message: 'Payment order not found.',
                statusCode: 404
            );
        }

        if ($order->status->isTerminal()) {
            return $this->errorResponse(
                message: 'This order has already been resolved.',
                statusCode: 400
            );
        }

        if ($order->isExpired()) {
            return $this->errorResponse(
                message: 'This payment order has expired. Please create a new one.',
                statusCode: 400
            );
        }

        // Require screenshot before TXID
        if (! $order->proof_image_url) {
            return $this->errorResponse(
                message: 'Please upload a proof screenshot before submitting your TXID.',
                statusCode: 422
            );
        }

        $order = $this->paymentService->submitTxid($order, $request->txid);

        return $this->successResponse(
            data: $this->formatOrder($order),
            message: 'Transaction submitted. Your payment is being reviewed.'
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Order History
    // ─────────────────────────────────────────────────────────────

    /**
     * List payment orders
     *
     * Returns all payment orders for the authenticated user,
     * newest first. Includes all statuses.
     *
     * @authenticated
     * @group Payments
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Payment orders retrieved successfully",
     *   "data": [
     *     {
     *       "order_id": 1,
     *       "reference": "SPY-2026-00001",
     *       "plan": "Pro",
     *       "billing_cycle": "monthly",
     *       "amount": 225.00,
     *       "currency": "USDT",
     *       "status": "approved",
     *       "txid": "a1b2c3d4...",
     *       "proof_image_url": "https://res.cloudinary.com/...",
     *       "created_at": "2026-03-25T10:00:00.000000Z"
     *     }
     *   ]
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', statusCode: 401);
        }

        $orders = PaymentOrder::where('user_id', $user->id)
            ->with('plan')
            ->latest()
            ->get()
            ->map(fn($order) => $this->formatOrder($order));

        return $this->successResponse(
            data: $orders,
            message: 'Payment orders retrieved successfully'
        );
    }

    /**
     * Get a single payment order
     *
     * @authenticated
     * @group Payments
     *
     * @urlParam orderId integer required The payment order ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Order retrieved successfully",
     *   "data": {
     *     "order_id": 1,
     *     "reference": "SPY-2026-00001",
     *     "status": "awaiting_verification"
     *   }
     * }
     */
    public function show(Request $request, int $orderId): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', statusCode: 401);
        }

        $order = PaymentOrder::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with('plan')
            ->first();

        if (! $order) {
            return $this->errorResponse(
                message: 'Payment order not found.',
                statusCode: 404
            );
        }

        return $this->successResponse(
            data: $this->formatOrder($order),
            message: 'Order retrieved successfully'
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Private Helpers
    // ─────────────────────────────────────────────────────────────

    private function formatOrder(PaymentOrder $order): array
    {
        return [
            'order_id'         => $order->id,
            'reference'        => $order->reference,
            'plan'             => $order->plan?->name,
            'billing_cycle'    => $order->billing_cycle,
            'amount'           => $order->amount_in_dollars,
            'currency'         => $order->currency,
            'network'          => $order->network,
            'status'           => $order->status->value,
            'txid'             => $order->txid,
            'proof_image_url'  => $order->proof_image_url,
            'rejection_reason' => $order->rejection_reason,
            'expires_at'       => $order->expires_at,
            'created_at'       => $order->created_at,
        ];
    }
}
