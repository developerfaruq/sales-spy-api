<?php

namespace App\Services;

use App\Enums\CreditTransactionType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminUserService
{
    public const ADMIN_ROLE = 'admin';

    public function __construct(
        protected CreditService $creditService,
        protected ActivityService $activityService
    ) {}

    /**
     * Soft delete a user and cut off access immediately.
     *
     * Returns null on success, otherwise the reason it was refused.
     *
     * Soft, not hard: `payment_orders` and `credit_transactions` are financial
     * records that must survive, and they reference this row. Tokens are revoked
     * so existing sessions stop working without waiting for the SoftDeletes
     * global scope to be hit.
     */
    public function delete(User $actor, User $target, ?Request $request = null): ?string
    {
        if ($guard = $this->guardSelfOrLastAdmin($actor, $target, 'delete')) {
            return $guard;
        }

        $target->tokens()->delete();
        $target->update(['is_active' => false]);
        $target->delete();

        $this->activityService->log(
            userId: $actor->id,
            type: 'admin_user_deleted',
            description: "Deleted user {$target->email}",
            metadata: ['target_user_id' => $target->id, 'target_email' => $target->email],
            request: $request
        );

        return null;
    }

    /**
     * Restore a soft-deleted user. They stay deactivated until explicitly
     * reactivated, so restoring never silently hands back access.
     */
    public function restore(User $actor, User $target, ?Request $request = null): void
    {
        $target->restore();

        $this->activityService->log(
            userId: $actor->id,
            type: 'admin_user_restored',
            description: "Restored user {$target->email}",
            metadata: ['target_user_id' => $target->id],
            request: $request
        );
    }

    /**
     * Grant or revoke the admin role.
     *
     * Returns null on success, otherwise the reason it was refused. Revoking is
     * guarded against the two ways an organisation can lock itself out: an admin
     * demoting themselves, and removing the last remaining admin.
     */
    public function setAdminRole(User $actor, User $target, bool $grant, ?Request $request = null): ?string
    {
        if ($grant) {
            if ($target->hasRole(self::ADMIN_ROLE)) {
                return null;
            }

            if (! $target->is_active) {
                return 'A deactivated user cannot be promoted to admin.';
            }

            $target->assignRole(self::ADMIN_ROLE);
        } else {
            if (! $target->hasRole(self::ADMIN_ROLE)) {
                return null;
            }

            if ($guard = $this->guardSelfOrLastAdmin($actor, $target, 'demote')) {
                return $guard;
            }

            $target->removeRole(self::ADMIN_ROLE);
            // Admin rights are cached in the token's abilities nowhere, but the
            // session should not keep operating with stale expectations.
            $target->tokens()->delete();
        }

        $this->activityService->log(
            userId: $actor->id,
            type: $grant ? 'admin_role_granted' : 'admin_role_revoked',
            description: ($grant ? 'Granted' : 'Revoked')." admin role for {$target->email}",
            metadata: ['target_user_id' => $target->id],
            request: $request
        );

        return null;
    }

    /**
     * Grant credits to a user.
     *
     * Routed through CreditService so the ledger, balance and idempotency key are
     * written the same way as every other credit movement. A direct
     * `credits_balance` write would leave the ledger unable to reconcile.
     *
     * Grants only: CreditService::add rejects non-positive amounts. Reducing an
     * allowance is done by changing the user's plan, which recalculates the quota
     * on the next reset.
     */
    public function adjustCredits(
        User $actor,
        User $target,
        int $amount,
        string $reason,
        ?Request $request = null
    ): array {
        $transaction = $this->creditService->add(
            user: $target,
            amount: $amount,
            type: CreditTransactionType::ADMIN_ADJUSTMENT,
            description: $reason,
            idempotencyKey: 'admin-adjustment:'.Str::uuid()->toString(),
            referenceType: User::class,
            referenceId: $actor->id,
            metadata: ['adjusted_by' => $actor->id, 'reason' => $reason]
        );

        $this->activityService->log(
            userId: $actor->id,
            type: 'admin_credits_adjusted',
            description: sprintf('Adjusted credits for %s by %+d', $target->email, $amount),
            metadata: [
                'target_user_id' => $target->id,
                'amount' => $amount,
                'reason' => $reason,
            ],
            request: $request
        );

        $fresh = $target->fresh();

        return [
            'user_id' => $target->id,
            'amount' => $amount,
            'credits_balance' => $fresh->credits_balance,
            'transaction_id' => $transaction?->id,
        ];
    }

    /**
     * Refuse actions that would lock the organisation out of its own admin panel.
     */
    private function guardSelfOrLastAdmin(User $actor, User $target, string $verb): ?string
    {
        if ($actor->id === $target->id) {
            return "You cannot {$verb} your own account.";
        }

        if ($target->hasRole(self::ADMIN_ROLE) && $this->adminCount() <= 1) {
            return "This is the last remaining admin, so it cannot be {$verb}d.";
        }

        return null;
    }

    private function adminCount(): int
    {
        return User::role(self::ADMIN_ROLE)->count();
    }
}
