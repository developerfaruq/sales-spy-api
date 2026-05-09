<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'monthly_price',
        'yearly_price',
        'monthly_quota',
        'features',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features'      => 'array',
            'is_active'     => 'boolean',
            'monthly_price' => 'integer',
            'yearly_price'  => 'integer',
            'monthly_quota' => 'integer',
        ];
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
