<?php

namespace App\Models;

use App\Enums\StoreScanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StoreScanRequest extends Model
{
    use HasUlids;

    protected $fillable = [
        'id',
        'user_id',
        'ecommerce_store_id',
        'idempotency_key',
        'status',
        'credit_transaction_id',
        'credit_period_transaction_id',
        'refund_transaction_id',
        'claim_token',
        'claim_lease_expires_at',
        'requested_at',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreScanStatus::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'claim_lease_expires_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(EcommerceStore::class, 'ecommerce_store_id');
    }

    public function refundTransaction()
    {
        return $this->belongsTo(CreditTransaction::class, 'refund_transaction_id');
    }
}
