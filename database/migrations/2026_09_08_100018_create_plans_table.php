<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // free | professional | enterprise
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('billing_period', 32)->default('monthly'); // PlanBillingPeriod
            $table->unsignedInteger('envelope_quota')->nullable(); // null = ilimitado
            $table->unsignedInteger('user_quota')->nullable(); // null = ilimitado
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_sandbox')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
