<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentReviewException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListPaymentsRequest;
use App\Http\Requests\Admin\ReviewPaymentRequest;
use App\Models\PaymentOrder;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class AdminPaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * List payment orders
     *
     * Returns payment orders across all users, newest first. Results can be
     * filtered by status or plan and searched by payment or user details.
     *
     * @authenticated
     *
     * @group Admin - Payments
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Payment orders retrieved successfully",
     *   "data": [{
     *     "order_id": 42,
     *     "reference": "SPY-2026-00042",
     *     "user": {"id": 7, "name": "Jane Doe", "email": "jane@example.com"},
     *     "plan": {"slug": "pro", "name": "Pro"},
     *     "billing_cycle": "monthly",
     *     "amount": 50,
     *     "currency": "USDT",
     *     "network": "TRC20",
     *     "status": "awaiting_verification",
     *     "txid": "a1b2c3d4e5f6",
     *     "proof_image_url": "https://res.cloudinary.com/example/proof.png",
     *     "reviewer": null,
     *     "reviewed_at": null,
     *     "rejection_reason": null,
     *     "expires_at": "2026-08-06T12:00:00.000000Z",
     *     "created_at": "2026-08-05T12:00:00.000000Z"
     *   }],
     *   "meta": {"current_page": 1, "last_page": 1, "per_page": 25, "total": 1}
     * }
     * @response 403 {"success": false, "message": "Unauthorized. Admin access required.", "errors": null}
     */
    public function index(ListPaymentsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = PaymentOrder::query()
            ->with(['user', 'plan', 'reviewer'])
            ->latest();

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['plan'])) {
            $query->whereHas('plan', fn ($planQuery) => $planQuery->where('slug', $validated['plan']));
        }

        if (isset($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($paymentQuery) use ($search): void {
                $paymentQuery
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhere('txid', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search): void {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->paginate($validated['per_page'] ?? 25);

        return $this->successResponse(
            data: $orders->getCollection()->map(fn (PaymentOrder $order) => $this->formatOrder($order)),
            message: 'Payment orders retrieved successfully',
            meta: [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        );
    }

    /**
     * Review a payment order
     *
     * Approves or rejects an order awaiting verification. Approval activates
     * the purchased subscription and resets credits to the selected plan quota.
     * Each order can be reviewed only once.
     *
     * @authenticated
     *
     * @group Admin - Payments
     *
     * @urlParam orderId integer required The payment order ID. Example: 42
     *
     * @response 200 scenario="Approved" {
     *   "success": true,
     *   "message": "Payment approved and subscription activated successfully",
     *   "data": {"order_id": 42, "reference": "SPY-2026-00042", "status": "approved"}
     * }
     * @response 200 scenario="Rejected" {
     *   "success": true,
     *   "message": "Payment rejected successfully",
     *   "data": {"order_id": 42, "reference": "SPY-2026-00042", "status": "rejected", "rejection_reason": "TXID could not be verified"}
     * }
     * @response 404 {"success": false, "message": "Payment order not found.", "errors": null}
     * @response 409 {"success": false, "message": "Only payments awaiting verification can be reviewed.", "errors": null}
     * @response 422 {"success": false, "message": "Validation failed", "errors": {"rejection_reason": ["A rejection reason is required when rejecting a payment."]}}
     */
    public function review(ReviewPaymentRequest $request, int $orderId): JsonResponse
    {
        if (! PaymentOrder::whereKey($orderId)->exists()) {
            return $this->errorResponse(
                message: 'Payment order not found.',
                statusCode: 404
            );
        }

        try {
            $order = $this->paymentService->reviewPayment(
                orderId: $orderId,
                admin: $request->user(),
                decision: PaymentStatus::from($request->validated('decision')),
                rejectionReason: $request->validated('rejection_reason')
            );
        } catch (PaymentReviewException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: 409
            );
        }

        return $this->successResponse(
            data: $this->formatOrder($order),
            message: $order->status === PaymentStatus::APPROVED
                ? 'Payment approved and subscription activated successfully'
                : 'Payment rejected successfully'
        );
    }

    private function formatOrder(PaymentOrder $order): array
    {
        return [
            'order_id' => $order->id,
            'reference' => $order->reference,
            'user' => [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ],
            'plan' => [
                'slug' => $order->plan->slug,
                'name' => $order->plan->name,
            ],
            'billing_cycle' => $order->billing_cycle->value,
            'amount' => $order->amount_in_dollars,
            'currency' => $order->currency,
            'network' => $order->network,
            'status' => $order->status->value,
            'txid' => $order->txid,
            'proof_image_url' => $order->proof_image_url,
            'reviewer' => $order->reviewer ? [
                'id' => $order->reviewer->id,
                'name' => $order->reviewer->name,
            ] : null,
            'reviewed_at' => $order->reviewed_at,
            'rejection_reason' => $order->rejection_reason,
            'expires_at' => $order->expires_at,
            'created_at' => $order->created_at,
        ];
    }
}
