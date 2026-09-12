<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY. Contestações recebidas pelo tópico `chargebacks` e consultadas em
 * GET /v1/chargebacks/{id} (docs/integracoes/mercado-pago-fase-2.md §6). Uma linha por par
 * (contestação, pagamento nosso). Guarda só o que a decisão exige: valor, moeda, motivo,
 * cobertura e documentação — nunca dado do titular do cartão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_chargebacks', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_chargeback_id', 64);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->char('currency', 3)->default('BRL');
            $table->string('reason', 191)->nullable();
            $table->boolean('coverage_applied')->nullable();
            $table->string('documentation_status', 32)->nullable();
            $table->timestamp('documentation_deadline_at')->nullable();
            $table->boolean('live_mode')->default(false);
            $table->timestamp('received_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_chargeback_id', 'payment_id'], 'payment_chargebacks_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_chargebacks');
    }
};
