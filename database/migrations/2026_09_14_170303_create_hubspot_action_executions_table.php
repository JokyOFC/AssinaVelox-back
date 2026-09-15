<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.9 — G-CONN (docs/fase-3/conectores.md §5.2). Cada execução da ação de workflow
 * "Enviar para assinatura" recebida do HubSpot.
 *
 * Aditiva. UNIQUE(portal_id, callback_id) é a idempotência: o HubSpot repete a mesma execução
 * (retentativa, replay dentro da janela) com o mesmo `callbackId` e recebe a MESMA resposta,
 * sem um segundo envelope. `response` guarda só o que foi devolvido ao HubSpot (ULID e estado).
 * `sync_*` é a atualização do negócio/contato quando o envelope conclui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_action_executions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hubspot_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('portal_id');
            $table->string('callback_id', 120);
            $table->string('object_type', 40)->nullable();
            $table->string('object_id', 40)->nullable();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            // processing | sent | awaiting_preparation | needs_review | failed
            $table->string('status', 30);
            $table->string('error_code', 64)->nullable();
            $table->json('response')->nullable();
            // not_applicable | pending | synced | failed
            $table->string('sync_status', 20)->default('not_applicable');
            $table->string('synced_value', 30)->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->string('sync_error_code', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['portal_id', 'callback_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index('envelope_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_action_executions');
    }
};
