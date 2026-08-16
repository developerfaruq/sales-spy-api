<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cover every sort and filter option the lead endpoints expose.
 *
 * 2026_08_07_110000 added composites for the website sorts and the default
 * product sort, but the remaining validated options had nothing behind them:
 * product `title`/`published_at`/`price` all run under
 * `WHERE ecommerce_store_id = ?`, where a global price_cents index cannot order
 * within one store, and the e-commerce `store_name`/`average_price` sorts and
 * the `currency_code`/`average_price_cents` filters had no index at all.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction, so Laravel must
     * not wrap this migration. `IF NOT EXISTS` keeps a retry after a partial
     * failure safe.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // websites is expected to reach Common Crawl scale, so build these
        // without holding a write lock on Postgres. CONCURRENTLY cannot run in a
        // transaction, which is why these are raw statements.
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresIndexes() as $name => $definition) {
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}");
            }

            return;
        }

        Schema::table('store_products', function (Blueprint $table): void {
            $table->index(['ecommerce_store_id', 'price_cents']);
            $table->index(['ecommerce_store_id', 'published_at']);
            $table->index(['ecommerce_store_id', 'title']);
        });

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->index(['is_active', 'crawl_status', 'store_name']);
            $table->index(['is_active', 'crawl_status', 'average_price_cents']);
            $table->index(['currency_code', 'average_price_cents']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys($this->postgresIndexes()) as $name) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }

            return;
        }

        Schema::table('ecommerce_stores', function (Blueprint $table): void {
            $table->dropIndex(['currency_code', 'average_price_cents']);
            $table->dropIndex(['is_active', 'crawl_status', 'average_price_cents']);
            $table->dropIndex(['is_active', 'crawl_status', 'store_name']);
        });

        Schema::table('store_products', function (Blueprint $table): void {
            $table->dropIndex(['ecommerce_store_id', 'title']);
            $table->dropIndex(['ecommerce_store_id', 'published_at']);
            $table->dropIndex(['ecommerce_store_id', 'price_cents']);
        });
    }

    /** @return array<string, string> */
    private function postgresIndexes(): array
    {
        return [
            'store_products_store_id_price_cents_index' => 'store_products (ecommerce_store_id, price_cents)',
            'store_products_store_id_published_at_index' => 'store_products (ecommerce_store_id, published_at)',
            'store_products_store_id_title_index' => 'store_products (ecommerce_store_id, title)',
            'ecommerce_stores_active_store_name_index' => 'ecommerce_stores (is_active, crawl_status, store_name)',
            'ecommerce_stores_active_average_price_index' => 'ecommerce_stores (is_active, crawl_status, average_price_cents)',
            'ecommerce_stores_currency_average_price_index' => 'ecommerce_stores (currency_code, average_price_cents)',
        ];
    }
};
