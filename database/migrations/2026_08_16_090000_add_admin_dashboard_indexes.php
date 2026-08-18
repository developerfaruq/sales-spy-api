<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support the admin dashboard aggregate queries.
 *
 * AdminMetricsService groups and date-filters over these columns on every
 * dashboard load, and none of them had an index. The audit log reader also
 * filters `user_activities` by `type` alone, which the existing composite
 * `(user_id, type)` index cannot serve.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresIndexes() as $name => $definition) {
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}");
            }

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->index('created_at');
            $table->index('email_verified_at');
        });

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->index(['status', 'created_at']);
        });

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->index(['type', 'created_at']);
        });

        Schema::table('user_activities', function (Blueprint $table): void {
            $table->index(['type', 'created_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->index(['status', 'plan_id']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys($this->postgresIndexes()) as $name) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }

            return;
        }

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'plan_id']);
        });

        Schema::table('user_activities', function (Blueprint $table): void {
            $table->dropIndex(['type', 'created_at']);
        });

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->dropIndex(['type', 'created_at']);
        });

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['email_verified_at']);
            $table->dropIndex(['created_at']);
        });
    }

    /** @return array<string, string> */
    private function postgresIndexes(): array
    {
        return [
            'users_created_at_index' => 'users (created_at)',
            'users_email_verified_at_index' => 'users (email_verified_at)',
            'payment_orders_status_created_at_index' => 'payment_orders (status, created_at)',
            'credit_transactions_type_created_at_index' => 'credit_transactions (type, created_at)',
            'user_activities_type_created_at_index' => 'user_activities (type, created_at)',
            'subscriptions_status_plan_id_index' => 'subscriptions (status, plan_id)',
        ];
    }
};
