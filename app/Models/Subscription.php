<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'billing_cycle',
        'status',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
        'expires_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'               => SubscriptionStatus::class,
            'billing_cycle'        => BillingCycle::class,
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'cancelled_at'         => 'datetime',
            'expires_at'           => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Check if the subscription is currently active.
     */
    public function isActive(): bool
    {
        return $this->status->hasAccess()
            && $this->current_period_end->isFuture();
    }

    /**
     * Check if the subscription has been cancelled
     * but access period has not yet ended.
     */
    public function isCancelledButActive(): bool
    {
        return $this->status === SubscriptionStatus::CANCELLED
            && $this->current_period_end->isFuture();
    }
}
