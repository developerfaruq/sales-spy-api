<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title', 255);
            $table->text('message');
            $table->string('reference_type', 255);
            $table->string('reference_id', 255);
            $table->json('data')->nullable();
            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(
                ['user_id', 'type', 'reference_type', 'reference_id'],
                'in_app_notifications_reference_unique'
            );
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE in_app_notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
        DB::statement("ALTER TABLE in_app_notifications ADD CONSTRAINT in_app_notifications_type_valid CHECK (type IN ('scan_completed', 'scan_failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
