<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32)->default('mercadopago');
            $table->char('external_reference', 26)->unique(); // nosso identificador (ULID)
            $table->string('provider_preference_id', 191)->nullable();
            $table->string('provider_payment_id', 191)->nullable()->unique();
            $table->string('status', 32)->default('pending'); // PaymentStatus (espelho do Mercado Pago)
            $table->string('status_detail', 128)->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('payment_method_id', 64)->nullable();
            $table->string('payer_email_masked', 191)->nullable();
            $table->string('checkout_url', 2048)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('activated_at')->nullable(); // aplicação do plano, uma única vez
            $table->string('environment', 32)->default('sandbox'); // PaymentEnvironment
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('provider_preference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
