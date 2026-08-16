<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table): void {
            // Backs the default product sort (ecommerce_store_id + last_seen_at)
            // and the worker's stale-product sweep, which filters the same pair.
            $table->index(['ecommerce_store_id', 'last_seen_at']);
        });

        Schema::table('websites', function (Blueprint $table): void {
            // Every sort option exposed by WebsiteIndexRequest is filtered by
            // crawl_status first, so each needs a matching composite.
            $table->index(['crawl_status', 'last_seen_at']);
            $table->index(['crawl_status', 'discovered_at']);
            $table->index(['crawl_status', 'estimated_monthly_traffic']);
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex(['crawl_status', 'estimated_monthly_traffic']);
            $table->dropIndex(['crawl_status', 'discovered_at']);
            $table->dropIndex(['crawl_status', 'last_seen_at']);
        });

        Schema::table('store_products', function (Blueprint $table): void {
            $table->dropIndex(['ecommerce_store_id', 'last_seen_at']);
        });
    }
};
