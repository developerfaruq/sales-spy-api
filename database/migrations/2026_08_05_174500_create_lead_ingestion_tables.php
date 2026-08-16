<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 253)->unique();
            $table->text('canonical_url')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('website_status', 32)->default('unknown');
            $table->boolean('is_ecommerce')->default(false);
            $table->string('platform', 64)->nullable();
            $table->string('cms', 64)->nullable();
            $table->string('niche')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('language_code', 12)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->text('contact_page_url')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('favicon_url')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedBigInteger('estimated_monthly_traffic')->nullable();
            $table->unsignedInteger('domain_age_days')->nullable();
            $table->string('source', 64);
            $table->string('source_external_id')->nullable();
            $table->string('crawl_status', 32)->default('pending');
            $table->unsignedInteger('crawl_attempts')->default(0);
            $table->text('last_crawl_error')->nullable();
            $table->json('technologies')->nullable();
            $table->json('social_links')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('discovered_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('next_crawl_at')->nullable();
            $table->timestamps();

            $table->index(['crawl_status', 'next_crawl_at']);
            $table->index(['is_ecommerce', 'country_code']);
            $table->index(['platform', 'country_code']);
            $table->index(['niche', 'country_code']);
            $table->index('last_seen_at');
            $table->unique(['source', 'source_external_id']);
        });

        Schema::create('ecommerce_stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('platform', 32)->default('unknown');
            $table->string('platform_store_id')->nullable();
            $table->string('store_name')->nullable();
            $table->string('category')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedInteger('product_count')->nullable();
            $table->unsignedInteger('collection_count')->nullable();
            $table->unsignedInteger('average_price_cents')->nullable();
            $table->unsignedInteger('minimum_price_cents')->nullable();
            $table->unsignedInteger('maximum_price_cents')->nullable();
            $table->boolean('has_discounted_products')->nullable();
            $table->boolean('accepts_payments')->nullable();
            $table->boolean('has_cart')->nullable();
            $table->string('crawl_status', 32)->default('pending');
            $table->unsignedInteger('crawl_attempts')->default(0);
            $table->text('last_crawl_error')->nullable();
            $table->json('payment_methods')->nullable();
            $table->json('shipping_countries')->nullable();
            $table->json('platform_metadata')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('next_crawl_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'category']);
            $table->index(['crawl_status', 'next_crawl_at']);
            $table->index('product_count');
            $table->unique(['platform', 'platform_store_id']);
        });

        Schema::create('store_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ecommerce_store_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('handle')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status', 32)->default('active');
            $table->char('currency_code', 3)->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedInteger('compare_at_price_cents')->nullable();
            $table->unsignedInteger('minimum_variant_price_cents')->nullable();
            $table->unsignedInteger('maximum_variant_price_cents')->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('inventory_quantity')->nullable();
            $table->text('product_url')->nullable();
            $table->text('primary_image_url')->nullable();
            $table->json('tags')->nullable();
            $table->json('images')->nullable();
            $table->json('variants')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['ecommerce_store_id', 'external_id']);
            $table->unique(['ecommerce_store_id', 'handle']);
            $table->index(['ecommerce_store_id', 'status']);
            $table->index(['ecommerce_store_id', 'is_available']);
            $table->index(['product_type', 'vendor']);
            $table->index('price_cents');
        });

        $this->addProductionConstraintsAndIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('ecommerce_stores');
        Schema::dropIfExists('websites');
    }

    private function addProductionConstraintsAndIndexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'websites.technologies',
            'websites.social_links',
            'websites.source_payload',
            'ecommerce_stores.payment_methods',
            'ecommerce_stores.shipping_countries',
            'ecommerce_stores.platform_metadata',
            'store_products.tags',
            'store_products.images',
            'store_products.variants',
            'store_products.source_payload',
        ] as $column) {
            [$table, $name] = explode('.', $column);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE jsonb USING {$name}::jsonb");
        }

        DB::statement("ALTER TABLE websites ADD CONSTRAINT websites_domain_canonical CHECK (domain = lower(domain) AND domain !~ '^(https?://|www\\.)')");
        DB::statement("ALTER TABLE websites ADD CONSTRAINT websites_status_valid CHECK (website_status IN ('active', 'inactive', 'parked', 'unreachable', 'unknown'))");
        DB::statement("ALTER TABLE websites ADD CONSTRAINT websites_crawl_status_valid CHECK (crawl_status IN ('pending', 'crawling', 'completed', 'failed', 'blocked'))");
        DB::statement('ALTER TABLE websites ADD CONSTRAINT websites_country_code_valid CHECK (country_code IS NULL OR country_code = upper(country_code))');
        DB::statement('ALTER TABLE websites ADD CONSTRAINT websites_http_status_valid CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599)');
        DB::statement("ALTER TABLE ecommerce_stores ADD CONSTRAINT ecommerce_stores_platform_valid CHECK (platform IN ('shopify', 'woocommerce', 'wix', 'bigcommerce', 'magento', 'prestashop', 'squarespace', 'custom', 'unknown'))");
        DB::statement("ALTER TABLE ecommerce_stores ADD CONSTRAINT ecommerce_stores_crawl_status_valid CHECK (crawl_status IN ('pending', 'crawling', 'completed', 'failed', 'blocked'))");
        DB::statement('ALTER TABLE ecommerce_stores ADD CONSTRAINT ecommerce_stores_currency_valid CHECK (currency_code IS NULL OR currency_code = upper(currency_code))');
        DB::statement("ALTER TABLE store_products ADD CONSTRAINT store_products_status_valid CHECK (status IN ('active', 'draft', 'archived', 'unavailable'))");
        DB::statement('ALTER TABLE store_products ADD CONSTRAINT store_products_currency_valid CHECK (currency_code IS NULL OR currency_code = upper(currency_code))');
        DB::statement('ALTER TABLE store_products ADD CONSTRAINT store_products_identity_required CHECK (external_id IS NOT NULL OR handle IS NOT NULL)');

        DB::statement('CREATE INDEX websites_technologies_gin ON websites USING gin (technologies)');
        DB::statement('CREATE INDEX websites_social_links_gin ON websites USING gin (social_links)');
        DB::statement('CREATE INDEX store_products_tags_gin ON store_products USING gin (tags)');
        DB::statement('CREATE INDEX websites_name_search_idx ON websites USING gin (to_tsvector(\'simple\', coalesce(name, \'\') || \' \' || coalesce(description, \'\') || \' \' || domain))');
    }
};
