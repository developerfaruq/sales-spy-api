<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

class PaymentOrder extends Model
{
    protected $fillable = [
        'reference',
        'user_id',
        'plan_id',
        'billing_cycle',
        'amount_usd_cents',
        'currency',
        'network',
        'status',
        'txid',
        'proof_image_url',
        'proof_image_public_id',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => PaymentStatus::class,
            'reviewed_at' => 'datetime',
            'expires_at'  => 'datetime',
            'amount_usd_cents' => 'integer',
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

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the amount formatted in dollars.
     */
    public function getAmountInDollarsAttribute(): float
    {
        return round($this->amount_usd_cents / 100, 2);
    }

    /**
     * Check if the order can still accept proof submission.
     */
    public function canSubmitProof(): bool
    {
        return in_array($this->status, [
            PaymentStatus::PENDING,
            PaymentStatus::AWAITING_VERIFICATION,
        ]) && $this->expires_at->isFuture();
    }

    /**
     * Check if the order has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast()
            && $this->status === PaymentStatus::PENDING;
    }
}
