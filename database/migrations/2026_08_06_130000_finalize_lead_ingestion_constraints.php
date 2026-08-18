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

        // websites_domain_dns_valid is owned by the claims/time-contract
        // migration and is already in its final form, so it is not touched here.
        DB::statement('ALTER TABLE websites ADD CONSTRAINT websites_numbers_valid CHECK ((estimated_monthly_traffic IS NULL OR estimated_monthly_traffic >= 0) AND (domain_age_days IS NULL OR domain_age_days >= 0) AND crawl_attempts >= 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE websites DROP CONSTRAINT IF EXISTS websites_numbers_valid');
    }
};
