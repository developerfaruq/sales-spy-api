<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestampTz('claim_lease_expires_at', 6)->nullable();
            $table->foreignId('refund_transaction_id')
                ->nullable()
                ->constrained('credit_transactions')
                ->nullOnDelete();

            $table->index(['status', 'claim_lease_expires_at']);
        });
    }

    public function down(): void
    {
        // Indexes first: SQLite refuses to drop a column that an index or
        // unique constraint still references, which would abort the rollback.
        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->dropIndex(['status', 'claim_lease_expires_at']);
            $table->dropUnique(['claim_token']);
        });

        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_transaction_id');
        });

        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->dropColumn(['claim_token', 'claim_lease_expires_at']);
        });
    }
};
