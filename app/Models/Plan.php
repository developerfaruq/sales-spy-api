<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    /**
     * Rank applied when a plan is created without an explicit `access_rank`.
     *
     * This is a creation convenience only. `plans.access_rank` is what
     * LeadAccessPolicy reads, so an admin can set any rank on any plan and a
     * slug outside this list simply starts at 0 instead of being unresolvable.
     */
    private const DEFAULT_RANKS = [
        'free' => 0,
        'basic' => 1,
        'pro' => 2,
        'enterprise' => 3,
    ];

    protected $fillable = [
        'slug',
        'name',
        'monthly_price',
        'yearly_price',
        'monthly_quota',
        'features',
        'is_active',
        'sort_order',
        'access_rank',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'monthly_quota' => 'integer',
            'access_rank' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Plan $plan): void {
            if ($plan->access_rank === null) {
                $plan->access_rank = self::DEFAULT_RANKS[$plan->slug] ?? 0;
            }
        });
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the price for a given billing cycle in dollars (not cents).
     */
    public function getPriceInDollars(string $billingCycle): ?float
    {
        if ($billingCycle === 'yearly') {
            return $this->yearly_price > 0 ? $this->yearly_price / 100 : null;
        }

        return $this->monthly_price > 0 ? $this->monthly_price / 100 : null;
    }

    /**
     * Check if this is the free plan.
     */
    public function isFree(): bool
    {
        return $this->slug === 'free';
    }
}
