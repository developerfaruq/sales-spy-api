<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE store_products DROP CONSTRAINT IF EXISTS store_products_numbers_valid');
        DB::statement('ALTER TABLE store_products ADD CONSTRAINT store_products_numbers_valid CHECK ((price_cents IS NULL OR price_cents >= 0) AND (compare_at_price_cents IS NULL OR compare_at_price_cents >= 0) AND variant_count >= 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore the exact predicate from 2026_08_06_120000. Re-adding an
        // inventory_quantity >= 0 clause would be stricter than the constraint
        // this migration replaced, and ADD CONSTRAINT validates existing rows,
        // so the rollback would abort on the negative inventory up() permits.
        DB::statement('ALTER TABLE store_products DROP CONSTRAINT IF EXISTS store_products_numbers_valid');
        DB::statement('ALTER TABLE store_products ADD CONSTRAINT store_products_numbers_valid CHECK ((price_cents IS NULL OR price_cents >= 0) AND (compare_at_price_cents IS NULL OR compare_at_price_cents >= 0) AND (variant_count >= 0))');
    }
};
