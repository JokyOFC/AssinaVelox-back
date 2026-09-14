<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.10 (P3-AFF) — razão de comissões, em centavos com moeda.
 *
 * Uma linha `commission` por pagamento aprovado (idempotente: `idempotency_key` =
 * `payment:{id}:commission`, UNIQUE — equivale ao `payment_id UNIQUE` do roadmap). Um estorno
 * ou contestação DEPOIS da aprovação não apaga nada: gera uma linha `reversal` NEGATIVA
 * (`payment:{id}:reversal`) que entra no próximo lote; um estorno parcial gera `adjustment`
 * (`payment:{id}:adjust:{líquido}`). `amount_cents` tem sinal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('affiliate_id')->constrained('affiliates')->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('idempotency_key', 120)->unique();
            $table->bigInteger('base_amount_cents');
            $table->unsignedInteger('rate_bp');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('environment', 20);
            $table->string('status', 20);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 40)->nullable();
            $table->foreignId('payout_batch_id')->nullable()->constrained('payout_batches')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
            $table->index(['status', 'available_at']);
            $table->index(['payment_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
