<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->foreignId('credit_period_transaction_id')
                ->nullable()
                ->constrained('credit_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credit_period_transaction_id');
        });
    }
};
