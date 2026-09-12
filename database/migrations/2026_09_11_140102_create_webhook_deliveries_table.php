<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 §2.16 (D-HOOK) — entregas de webhook (uma por endpoint × evento).
 * docs/fase-2/webhooks.md §3–§5. Só aditiva; compatível com MySQL 8 e SQLite.
 *
 * - `ulid` é o id da ENTREGA (cabeçalho X-AssinaVelox-Delivery-Id): igual em todas as
 *   tentativas e no reenvio manual, para o receptor deduplicar.
 * - Idempotência: único (endpoint, tipo, event_id). O mesmo evento nunca vira duas entregas
 *   para o mesmo endpoint, nem se o gancho rodar duas vezes.
 * - `payload` é o corpo BRUTO (string JSON): as tentativas assinam exatamente os mesmos bytes.
 * - `history`: tentativas (status, código, duração, trecho truncado e redigido da resposta).
 * - `locked_until`: trava otimista do worker que está tentando agora (evita tentativa dupla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained('envelopes')->nullOnDelete();
            $table->char('event_id', 26);
            $table->string('event_type', 60);
            $table->longText('payload');
            $table->string('status', 16)->default('pending');
            $table->boolean('is_test')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->string('last_error', 60)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('history')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['webhook_endpoint_id', 'event_type', 'event_id'], 'webhook_deliveries_idempotency_unique');
            $table->index(['status', 'next_retry_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
