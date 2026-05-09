<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::table('users', function (Blueprint $table) {
            // Remove the old simple string plan column
            // Plan is now tracked through the subscriptions table
            if (Schema::hasColumn('users', 'plan')) {
                $table->dropColumn('plan');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('users', function (Blueprint $table) {
            $table->enum('plan', ['free', 'basic', 'pro', 'enterprise'])
                ->default('free')
                ->after('password');
        });
    }
};
