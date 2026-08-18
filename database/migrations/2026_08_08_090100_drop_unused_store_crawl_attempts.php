<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unused per-store crawl attempt counter.
 *
 * Only `websites.crawl_attempts` is maintained (incremented on claim, reset on a
 * successful persist, and compared against the crawl ceiling). The e-commerce
 * copy was written by nothing and read by nothing, and there is no store-level
 * crawl scheduler for it to serve. Its `(crawl_status, next_crawl_at)` index
 * goes too, since the scope that would have used it is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->dropIndex(['crawl_status', 'next_crawl_at']);
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->dropColumn('crawl_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->unsignedInteger('crawl_attempts')->default(0);
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->index(['crawl_status', 'next_crawl_at']);
        });
    }
};
