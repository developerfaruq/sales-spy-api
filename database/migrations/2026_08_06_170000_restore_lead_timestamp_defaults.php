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

        DB::statement('ALTER TABLE websites ALTER COLUMN discovered_at SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE store_products ALTER COLUMN first_seen_at SET DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE websites ALTER COLUMN discovered_at DROP DEFAULT');
        DB::statement('ALTER TABLE store_products ALTER COLUMN first_seen_at DROP DEFAULT');
    }
};
