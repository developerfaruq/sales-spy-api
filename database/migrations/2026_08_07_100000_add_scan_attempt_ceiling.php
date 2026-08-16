<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Recovery scans expired leases; keep that lookup index-backed.
        DB::statement(
            'CREATE INDEX store_scan_requests_recovery_idx
             ON store_scan_requests (status, claim_lease_expires_at, attempts)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS store_scan_requests_recovery_idx');
        }

        Schema::table('store_scan_requests', function (Blueprint $table): void {
            $table->dropColumn('attempts');
        });
    }
};
