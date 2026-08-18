<?php

namespace App\Http\Controllers\User;

use App\Enums\StoreScanStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreScanController extends Controller
{
    /**
     * Get deep scan status
     *
     * Returns only the authenticated user's scan request. Worker claims and raw
     * crawler errors are intentionally excluded.
     *
     * @authenticated
     *
     * @group E-commerce Leads
     *
     * @urlParam scanRequestId string required Scan request ULID.
     */
    public function show(Request $request, string $scanRequestId): JsonResponse
    {
        // Scoped through the relation so ownership cannot be omitted here.
        $scan = $request->user()->storeScanRequests()
            ->with(['store.website', 'refundTransaction'])
            ->whereKey($scanRequestId)
            ->first();

        if (! $scan) {
            return $this->errorResponse('Scan request not found.', statusCode: 404);
        }

        // A refund transaction is always written on failure, but its amount is
        // zero when the charge fell outside the current credit period or the
        // plan is unlimited. The worker records which reason applies.
        $refundedCredits = (int) ($scan->refundTransaction?->amount ?? 0);
        $isFailed = $scan->status === StoreScanStatus::FAILED;
        $refundMetadata = $scan->refundTransaction?->metadata ?? [];

        return $this->successResponse(
            data: [
                'id' => $scan->id,
                'domain' => $scan->store->website->domain,
                'status' => $scan->status->value,
                'requested_at' => $scan->requested_at,
                'started_at' => $scan->started_at,
                'completed_at' => $scan->completed_at,
                'products_count' => $scan->status === StoreScanStatus::COMPLETED
                    ? $scan->store->product_count
                    : null,
                'credits_refunded' => $refundedCredits > 0,
                'refunded_credits' => $refundedCredits,
                'error' => $isFailed
                    ? $this->failureMessage($refundedCredits, $refundMetadata)
                    : null,
            ],
            message: 'Scan status retrieved successfully'
        );
    }

    /**
     * Explain a failure using the reason the worker recorded.
     *
     * Re-deriving it from the amount alone reported an earlier credit period to
     * unlimited-plan users, whose refunds are zero for a different reason.
     */
    private function failureMessage(int $refundedCredits, array $refundMetadata): string
    {
        if ($refundedCredits > 0) {
            return 'The scan could not be completed and your scan credits were refunded.';
        }

        if (($refundMetadata['unlimited'] ?? false) === true) {
            return 'The scan could not be completed. Your plan has unlimited credits, so there was nothing to refund.';
        }

        if (($refundMetadata['credit_period_current'] ?? true) === false) {
            return 'The scan could not be completed. No credits were refunded because the original charge belongs to an earlier credit period.';
        }

        return 'The scan could not be completed.';
    }
}
