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
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // SPY-2026-XXXXX
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained();

            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->integer('amount_usd_cents'); // amount in cents
            $table->string('currency')->default('USDT');
            $table->string('network')->default('TRC20');

            $table->enum('status', [
                'pending',               // order created, waiting for user to pay
                'awaiting_verification', // user submitted TXID, waiting for admin
                'approved',              // admin approved, subscription activated
                'rejected',              // admin rejected
                'expired',               // user never submitted payment
            ])->default('pending');

            // Payment proof
            $table->string('txid')->nullable();           // blockchain transaction hash
            $table->string('proof_image_url')->nullable(); // Cloudinary URL
            $table->string('proof_image_public_id')->nullable();

            // Admin action
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Auto-expire orders after 24 hours if no payment submitted
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
