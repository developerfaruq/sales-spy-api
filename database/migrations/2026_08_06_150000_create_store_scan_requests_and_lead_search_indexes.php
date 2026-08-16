<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_scan_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ecommerce_store_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('status', 32)->default('queued');
            $table->foreignId('credit_transaction_id')
                ->nullable()
                ->constrained('credit_transactions')
                ->nullOnDelete();
            $table->timestampTz('requested_at', 6)->useCurrent();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz(6);

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'requested_at']);
            $table->index(['ecommerce_store_id', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE store_scan_requests ADD CONSTRAINT store_scan_requests_status_valid CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
        DB::statement("CREATE INDEX ecommerce_stores_search_idx ON ecommerce_stores USING gin (to_tsvector('simple', coalesce(store_name, '') || ' ' || coalesce(category, '') || ' ' || platform))");
        DB::statement("CREATE INDEX store_products_search_idx ON store_products USING gin (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(vendor, '') || ' ' || coalesce(product_type, '')))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS store_products_search_idx');
            DB::statement('DROP INDEX IF EXISTS ecommerce_stores_search_idx');
        }

        Schema::dropIfExists('store_scan_requests');
    }
};
