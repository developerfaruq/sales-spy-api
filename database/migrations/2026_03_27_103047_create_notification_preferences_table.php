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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique() // One row per user
                ->constrained()
                ->onDelete('cascade');

            // Email notifications
            $table->boolean('email_on_export_complete')->default(true);
            $table->boolean('email_on_billing')->default(true);
            $table->boolean('email_on_new_features')->default(true);
            $table->boolean('email_on_security_alerts')->default(true);

            // In-app notifications
            $table->boolean('inapp_on_export_complete')->default(true);
            $table->boolean('inapp_on_low_credits')->default(true);
            $table->boolean('inapp_on_scan_complete')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
