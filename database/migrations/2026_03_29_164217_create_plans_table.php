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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // free, basic, pro, enterprise
            $table->string('name');           // Free, Basic, Pro, Enterprise
            $table->integer('monthly_price')->default(0);  // in USD cents (0 = free)
            $table->integer('yearly_price')->default(0);   // in USD cents
            $table->integer('monthly_quota')->default(50); // credits per month (-1 = unlimited)
            $table->json('features');         // array of feature strings for the pricing page
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // controls display order
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
