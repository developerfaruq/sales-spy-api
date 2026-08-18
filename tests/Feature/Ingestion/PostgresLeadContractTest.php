<?php

namespace Tests\Feature\Ingestion;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class PostgresLeadContractTest extends TestCase
{
    public function test_postgres_contract_uses_jsonb_constraints_and_search_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL contract test requires the pgsql connection.');
        }

        $jsonbColumns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->whereIn('table_name', ['websites', 'ecommerce_stores', 'store_products'])
            ->whereIn('column_name', ['technologies', 'platform_metadata', 'variants'])
            ->pluck('data_type', 'column_name');

        $this->assertSame('jsonb', $jsonbColumns['technologies']);
        $this->assertSame('jsonb', $jsonbColumns['platform_metadata']);
        $this->assertSame('jsonb', $jsonbColumns['variants']);

        $timeColumns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'websites')
            ->whereIn('column_name', ['last_seen_at', 'claim_lease_expires_at'])
            ->pluck('data_type', 'column_name');

        $this->assertSame('timestamp with time zone', $timeColumns['last_seen_at']);
        $this->assertSame('timestamp with time zone', $timeColumns['claim_lease_expires_at']);

        $constraints = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', 'public')
            ->whereIn('constraint_name', [
                'websites_domain_canonical',
                'websites_crawl_status_valid',
                'ecommerce_stores_platform_valid',
                'store_products_identity_required',
                'websites_domain_dns_valid',
                'websites_numbers_valid',
            ])
            ->pluck('constraint_name');

        $this->assertCount(6, $constraints);

        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', [
                'websites_technologies_gin',
                'websites_name_search_idx',
                'store_products_tags_gin',
            ])
            ->pluck('indexname');

        $this->assertCount(3, $indexes);
    }

    public function test_postgres_accepts_raw_scraper_writes_and_rejects_noncanonical_values(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL contract test requires the pgsql connection.');
        }

        DB::beginTransaction();

        try {
            $websiteId = DB::table('websites')->insertGetId([
                'domain' => 'pgsql-contract.example.com',
                'canonical_url' => 'https://pgsql-contract.example.com',
                'website_status' => 'active',
                'is_ecommerce' => true,
                'country_code' => 'US',
                'source' => 'contract_test',
                'source_external_id' => 'pgsql-contract-1',
                'crawl_status' => 'completed',
                'technologies' => json_encode(['Shopify']),
                'source_payload' => json_encode(['worker' => 'python']),
                'discovered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $storeId = DB::table('ecommerce_stores')->insertGetId([
                'website_id' => $websiteId,
                'platform' => 'shopify',
                'platform_store_id' => 'pgsql-shop-1',
                'currency_code' => 'USD',
                'crawl_status' => 'completed',
                'platform_metadata' => json_encode(['shop_id' => 1]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('store_products')->insert([
                'ecommerce_store_id' => $storeId,
                'external_id' => 'pgsql-product-1',
                'title' => 'PostgreSQL Product',
                'status' => 'active',
                'currency_code' => 'USD',
                'price_cents' => 2500,
                'variants' => json_encode([['id' => 1, 'price_cents' => 2500]]),
                'first_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame('array', DB::selectOne(
                'SELECT jsonb_typeof(technologies) AS type FROM websites WHERE id = ?',
                [$websiteId]
            )->type);
            $this->assertDatabaseHas('store_products', [
                'ecommerce_store_id' => $storeId,
                'external_id' => 'pgsql-product-1',
                'price_cents' => 2500,
            ]);

            try {
                DB::table('websites')->insert([
                    'domain' => 'HTTPS://Invalid.example.com',
                    'source' => 'contract_test',
                    'crawl_status' => 'completed',
                    'discovered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('Expected canonical-domain constraint violation.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }
}
