<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET TIME ZONE 'UTC'");
        }

        Schema::table('websites', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestampTz('claimed_at', 6)->nullable();
            $table->timestampTz('claim_lease_expires_at', 6)->nullable();

            $table->index(['crawl_status', 'claim_lease_expires_at']);
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->timestampTz('discovered_at', 6)->useCurrent()->change();
            $table->timestampTz('last_seen_at', 6)->nullable()->change();
            $table->timestampTz('last_crawled_at', 6)->nullable()->change();
            $table->timestampTz('next_crawl_at', 6)->nullable()->change();
            $table->timestampTz('created_at', 6)->nullable()->change();
            $table->timestampTz('updated_at', 6)->nullable()->change();
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('last_product_sync_at', 6)->nullable()->change();
            $table->timestampTz('last_crawled_at', 6)->nullable()->change();
            $table->timestampTz('next_crawl_at', 6)->nullable()->change();
            $table->timestampTz('created_at', 6)->nullable()->change();
            $table->timestampTz('updated_at', 6)->nullable()->change();
        });

        Schema::table('store_products', function (Blueprint $table): void {
            $table->timestampTz('published_at', 6)->nullable()->change();
            $table->timestampTz('source_created_at', 6)->nullable()->change();
            $table->timestampTz('source_updated_at', 6)->nullable()->change();
            $table->timestampTz('last_seen_at', 6)->nullable()->change();
            $table->timestampTz('first_seen_at', 6)->useCurrent()->change();
            $table->timestampTz('created_at', 6)->nullable()->change();
            $table->timestampTz('updated_at', 6)->nullable()->change();
        });

        // The ingestion identity pair must not be unique — a re-crawl can
        // legitimately resurface the same (source, source_external_id). This
        // runs on every driver so the SQLite test schema and the Postgres
        // production schema enforce the same rule; keeping it pgsql-only let
        // the suite assert a constraint production did not have.
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropUnique(['source', 'source_external_id']);
            $table->index(['source', 'source_external_id'], 'websites_source_external_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE websites ADD CONSTRAINT websites_domain_dns_valid CHECK (domain ~ '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$' AND domain !~ '^[0-9]+(\\.[0-9]+){3}$')");
            DB::statement('ALTER TABLE ecommerce_stores ADD CONSTRAINT ecommerce_stores_counts_valid CHECK (product_count IS NULL OR product_count >= 0)');
            DB::statement('ALTER TABLE ecommerce_stores ADD CONSTRAINT ecommerce_stores_prices_valid CHECK ((average_price_cents IS NULL OR average_price_cents >= 0) AND (minimum_price_cents IS NULL OR minimum_price_cents >= 0) AND (maximum_price_cents IS NULL OR maximum_price_cents >= 0))');
            DB::statement('ALTER TABLE store_products ADD CONSTRAINT store_products_numbers_valid CHECK ((price_cents IS NULL OR price_cents >= 0) AND (compare_at_price_cents IS NULL OR compare_at_price_cents >= 0) AND (variant_count >= 0))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE websites DROP CONSTRAINT IF EXISTS websites_domain_dns_valid');
            DB::statement('ALTER TABLE ecommerce_stores DROP CONSTRAINT IF EXISTS ecommerce_stores_counts_valid');
            DB::statement('ALTER TABLE ecommerce_stores DROP CONSTRAINT IF EXISTS ecommerce_stores_prices_valid');
            DB::statement('ALTER TABLE store_products DROP CONSTRAINT IF EXISTS store_products_numbers_valid');
        }

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex('websites_source_external_id_idx');
            $table->unique(['source', 'source_external_id']);
        });

        // Each column must be re-declared with the exact modifiers from the
        // create migration. Laravel rebuilds every modifier on change(), so
        // omitting nullable()/useCurrent() here would emit SET NOT NULL and
        // DROP DEFAULT and abort the rollback on any NULL row.
        Schema::table('store_products', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->change();
            $table->timestamp('source_created_at')->nullable()->change();
            $table->timestamp('source_updated_at')->nullable()->change();
            $table->timestamp('last_seen_at')->nullable()->change();
            $table->timestamp('first_seen_at')->useCurrent()->change();
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->timestamp('last_product_sync_at')->nullable()->change();
            $table->timestamp('last_crawled_at')->nullable()->change();
            $table->timestamp('next_crawl_at')->nullable()->change();
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('discovered_at')->useCurrent()->change();
            $table->timestamp('last_seen_at')->nullable()->change();
            $table->timestamp('last_crawled_at')->nullable()->change();
            $table->timestamp('next_crawl_at')->nullable()->change();
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });

        // Indexes must go before the columns they cover: SQLite refuses to drop
        // an indexed or unique column, which is what made this rollback path
        // untestable on the suite's connection.
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex(['crawl_status', 'claim_lease_expires_at']);
            $table->dropUnique(['claim_token']);
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['claim_token', 'claimed_at', 'claim_lease_expires_at']);
        });
    }
};
