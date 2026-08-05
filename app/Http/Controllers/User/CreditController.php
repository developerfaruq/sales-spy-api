<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreditHistoryRequest;
use App\Models\CreditTransaction;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function __construct(
        protected CreditService $creditService
    ) {}

    /**
     * Get credit balance
     *
     * Returns the authenticated user's current credit allowance, next reset
     * date, and the configured costs for credit-consuming actions.
     *
     * @authenticated
     *
     * @group Credits
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Credit balance retrieved successfully",
     *   "data": {
     *     "balance": 50,
     *     "monthly_quota": 50,
     *     "unlimited": false,
     *     "next_reset_at": "2026-09-05T12:00:00.000000Z",
     *     "costs": {"website_access": 1, "search_result": 1, "export_row": 2, "deep_scan": 5}
     *   }
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;
        $unlimited = $user->hasUnlimitedCredits();

        return $this->successResponse(
            data: [
                'balance' => $unlimited ? null : $user->credits_balance,
                'monthly_quota' => $unlimited ? null : $user->credits_monthly_quota,
                'unlimited' => $unlimited,
                'next_reset_at' => $subscription?->credits_reset_at,
                'costs' => $this->creditService->getCosts(),
            ],
            message: 'Credit balance retrieved successfully'
        );
    }

    /**
     * Get credit transaction history
     *
     * Returns the authenticated user's immutable credit ledger, newest first.
     * Results can be filtered by transaction type and date range.
     *
     * @authenticated
     *
     * @group Credits
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Credit history retrieved successfully",
     *   "data": [{
     *     "id": 10,
     *     "type": "spend",
     *     "amount": -5,
     *     "absolute_amount": 5,
     *     "is_deduction": true,
     *     "balance_before": 50,
     *     "balance_after": 45,
     *     "description": "Deep store scan",
     *     "reference_type": "store_scan",
     *     "reference_id": "42",
     *     "metadata": null,
     *     "created_at": "2026-08-05T12:00:00.000000Z"
     *   }],
     *   "meta": {"current_page": 1, "last_page": 1, "per_page": 25, "total": 1}
     * }
     */
    public function history(CreditHistoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = $request->user()->creditTransactions();

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (isset($validated['from'])) {
            $query->where('created_at', '>=', $validated['from'].' 00:00:00');
        }

        if (isset($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'].' 23:59:59');
        }

        $transactions = $query->paginate($validated['per_page'] ?? 25);

        return $this->successResponse(
            data: $transactions->getCollection()
                ->map(fn (CreditTransaction $transaction) => $this->formatTransaction($transaction)),
            message: 'Credit history retrieved successfully',
            meta: [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ]
        );
    }

    private function formatTransaction(CreditTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'amount' => $transaction->amount,
            'absolute_amount' => $transaction->absolute_amount,
            'is_deduction' => $transaction->isDeduction(),
            'balance_before' => $transaction->balance_before,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'metadata' => $transaction->metadata,
            'created_at' => $transaction->created_at,
        ];
    }
}
