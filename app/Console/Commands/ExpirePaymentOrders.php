<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\PaymentOrder;
use Illuminate\Console\Command;

class ExpirePaymentOrders extends Command
{
    protected $signature   = 'payments:expire';
    protected $description = 'Mark pending payment orders as expired after 24 hours';

    public function handle(): void
    {
        $this->info('Expiring old payment orders...');

        $expired = PaymentOrder::where('status', PaymentStatus::PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentStatus::EXPIRED]);

        $this->info("Expired {$expired} payment orders.");
    }
}
