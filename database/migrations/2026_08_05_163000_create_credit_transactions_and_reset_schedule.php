<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('amount');
            $table->integer('balance_before');
            $table->integer('balance_after');
            $table->string('description');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('credits_reset_at')->nullable()->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->integer('credits_balance')->default(0)->change();
            $table->integer('credits_monthly_quota')->default(0)->change();
        });

        DB::table('users')
            ->where('credits_monthly_quota', -1)
            ->update(['credits_balance' => 0]);

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('credit_transactions')->insert([
                'user_id' => $user->id,
                'type' => 'opening_balance',
                'amount' => $user->credits_balance,
                'balance_before' => 0,
                'balance_after' => $user->credits_balance,
                'description' => 'Opening balance imported from the existing account',
                'idempotency_key' => "user:{$user->id}:opening-balance",
                'metadata' => json_encode([
                    'unlimited' => $user->credits_monthly_quota === -1,
                ]),
                'created_at' => now(),
            ]);
        });

        DB::table('subscriptions')
            ->whereIn('status', ['active', 'cancelled', 'trial'])
            ->where('current_period_end', '>', now())
            ->orderBy('id')
            ->each(function (object $subscription): void {
                $periodEnd = Carbon::parse($subscription->current_period_end);
                $nextReset = Carbon::parse($subscription->current_period_start)->addMonthNoOverflow();

                while ($nextReset->isPast()) {
                    $nextReset->addMonthNoOverflow();
                }

                DB::table('subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'credits_reset_at' => $nextReset->lt($periodEnd)
                            ? $nextReset
                            : $periodEnd,
                    ]);
            });

        $timestamp = now();
        DB::table('settings')->insertOrIgnore($this->creditCostSettings($timestamp));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('credits_balance')->default(50)->change();
            $table->integer('credits_monthly_quota')->default(50)->change();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('credits_reset_at');
        });

        Schema::dropIfExists('credit_transactions');
    }

    private function creditCostSettings($timestamp): array
    {
        return [
            ['key' => 'credit_cost_website_access', 'value' => '1', 'type' => 'integer', 'description' => 'Credits charged to access a website or store detail', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['key' => 'credit_cost_search_result', 'value' => '1', 'type' => 'integer', 'description' => 'Credits charged per returned search result', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['key' => 'credit_cost_export_row', 'value' => '2', 'type' => 'integer', 'description' => 'Credits charged per exported row', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['key' => 'credit_cost_deep_scan', 'value' => '5', 'type' => 'integer', 'description' => 'Credits charged for a deep store scan', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ];
    }
};
