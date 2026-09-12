<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY (roadmap §2.20 `payment_refunds`). Um pedido de estorno por linha.
 *
 * `idempotency_key` é gravada ANTES da chamada ao provedor e reusada como `X-Idempotency-Key` em
 * qualquer repetição (T5): repetir o mesmo pedido nunca cria um segundo estorno. `status`:
 * requested | pending | approved | rejected | cancelled | unknown (timeout: consultar antes de
 * repetir) | failed (recusa definitiva do provedor). Nenhum dado de cartão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_refund_id', 64)->nullable();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('BRL');
            $table->string('kind', 16); // total | partial
            $table->string('status', 16)->default('requested');
            $table->string('provider_status', 32)->nullable();
            $table->string('reason', 500);
            $table->string('initiator', 16); // platform_admin | owner
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->char('correlation_id', 26)->nullable();
            $table->string('error', 191)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
            $table->index('provider_refund_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
