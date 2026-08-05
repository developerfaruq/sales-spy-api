<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->integer('credits_balance')->default(50)->change();
            $table->integer('credits_monthly_quota')->default(50)->change();
        });

        Schema::table('oauth_providers', function (Blueprint $table): void {
            $table->unique(['provider', 'provider_id']);
        });

        $freePlan = DB::table('plans')->where('slug', 'free')->first(['id', 'monthly_quota']);

        if ($freePlan) {
            $freeUserIds = DB::table('subscriptions')
                ->where('plan_id', $freePlan->id)
                ->whereIn('status', ['active', 'cancelled', 'trial'])
                ->where('current_period_end', '>', now())
                ->select('user_id');

            DB::table('users')
                ->whereIn('id', $freeUserIds)
                ->update(['credits_monthly_quota' => $freePlan->monthly_quota]);

            DB::table('users')
                ->whereIn('id', $freeUserIds)
                ->where('credits_balance', '>', $freePlan->monthly_quota)
                ->update(['credits_balance' => $freePlan->monthly_quota]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_providers', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'provider_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->integer('credits_balance')->default(500)->change();
            $table->integer('credits_monthly_quota')->default(500)->change();
        });
    }
};
