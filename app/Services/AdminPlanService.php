<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class AdminPlanService
{
    /**
     * Plans the system resolves by slug and cannot function without.
     *
     * SubscriptionService::assignFreePlan does
     * `Plan::where('slug', 'free')->firstOrFail()` on every registration, so
     * deleting or renaming the free plan breaks signup entirely.
     */
    public const PROTECTED_SLUGS = ['free'];

    /**
     * Every plan, including inactive ones, with subscriber counts.
     *
     * The public /plans endpoint only returns active plans; an admin needs to see
     * what is hidden and how many people are on each tier before changing it.
     */
    public function list(): array
    {
        return Plan::query()
            ->withCount([
                'subscriptions',
                'subscriptions as active_subscriptions_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan): array => $this->present($plan))
            ->all();
    }

    public function create(array $data): Plan
    {
        // plans.features is NOT NULL with no database default, so omitting it
        // would fail the insert rather than create a plan with no feature bullets.
        $plan = Plan::create([...$data, 'features' => $data['features'] ?? []]);

        return $plan->refresh();
    }

    /**
     * Update a plan in place.
     *
     * Changing `monthly_quota` does not retroactively change what current
     * subscribers hold: their balance was granted at subscription time and is
     * only recomputed on the next monthly reset. The free plan's slug is fixed
     * because the system resolves it by slug.
     */
    public function update(Plan $plan, array $data): Plan
    {
        if ($this->isProtected($plan)) {
            unset($data['slug']);
        }

        $plan->update($data);

        return $plan->refresh();
    }

    /**
     * Delete a plan, or explain why it cannot be deleted.
     *
     * Returns null on success, otherwise a human-readable reason. Deleting a plan
     * that any subscription or payment order references would either violate a
     * foreign key or orphan billing history, so those are refused in favour of
     * deactivating.
     */
    public function delete(Plan $plan): ?string
    {
        if ($this->isProtected($plan)) {
            return 'The free plan cannot be deleted because new registrations are assigned to it.';
        }

        if ($plan->subscriptions()->exists()) {
            return 'This plan still has subscriptions. Deactivate it instead so existing subscribers are unaffected.';
        }

        if (DB::table('payment_orders')->where('plan_id', $plan->id)->exists()) {
            return 'This plan is referenced by payment orders and must be kept for billing history. Deactivate it instead.';
        }

        $plan->delete();

        return null;
    }

    public function isProtected(Plan $plan): bool
    {
        return in_array($plan->slug, self::PROTECTED_SLUGS, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            // Cents, matching how they are stored and how payments are recorded.
            'monthly_price_cents' => $plan->monthly_price,
            'yearly_price_cents' => $plan->yearly_price,
            'monthly_quota' => $plan->monthly_quota,
            'unlimited_credits' => $plan->monthly_quota === -1,
            'access_rank' => $plan->access_rank,
            'features' => $plan->features,
            'is_active' => $plan->is_active,
            'sort_order' => $plan->sort_order,
            'is_protected' => $this->isProtected($plan),
            'subscriptions_count' => $plan->subscriptions_count ?? null,
            'active_subscriptions_count' => $plan->active_subscriptions_count ?? null,
        ];
    }
}
