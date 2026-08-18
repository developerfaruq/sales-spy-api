<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support admin user removal and data-driven plan tiers.
 *
 * `users.deleted_at` — deleting a user must not destroy the financial audit
 * trail. `payment_orders`, `credit_transactions` and `subscriptions` all
 * reference users, so a hard delete would either cascade away revenue records or
 * fail on a foreign key. Soft deletion removes access while keeping history.
 *
 * `plans.access_rank` — LeadAccessPolicy hardcoded a slug-to-rank map, so any
 * plan whose slug was not one of free/basic/pro/enterprise silently fell back to
 * free-tier access. Moving the rank onto the row makes new tiers behave
 * correctly and leaves one source of truth instead of two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('access_rank')->default(0)->after('sort_order');
        });

        // Preserve the behaviour of the map this column replaces.
        foreach (['free' => 0, 'basic' => 1, 'pro' => 2, 'enterprise' => 3] as $slug => $rank) {
            Plan::withoutEvents(fn () => Plan::where('slug', $slug)->update(['access_rank' => $rank]));
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('access_rank');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
