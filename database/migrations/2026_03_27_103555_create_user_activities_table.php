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
        Schema::create('user_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            $table->string('type'); // login, logout, profile_update, password_change, export, search, scan
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable(); // 45 chars covers IPv6
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable(); // Any extra data (device name, export count, etc.)
            $table->timestamp('created_at')->useCurrent();

            // Index for fast user-specific queries
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_activities');
    }
};
