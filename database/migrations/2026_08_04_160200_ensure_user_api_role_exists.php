<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('roles')->insertOrIgnore([
            'name' => 'user',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The role may be assigned to users, so rolling back must not delete it.
    }
};
