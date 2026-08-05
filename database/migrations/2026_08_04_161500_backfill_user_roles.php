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
        $roleId = DB::table('roles')
            ->where('name', 'user')
            ->where('guard_name', 'api')
            ->value('id');

        if (! $roleId) {
            throw new RuntimeException('The user role for the api guard does not exist.');
        }

        DB::table('users')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', 'App\\Models\\User');
            })
            ->select('id')
            ->orderBy('id')
            ->each(function (object $user) use ($roleId): void {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $user->id,
                ]);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing role assignments must not be removed during rollback.
    }
};
