<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.5 (P3-GOV) — cada DEVOLUÇÃO de arquivo assinado fora da plataforma, aceita ou
 * recusada. Registro somente de inclusão (sem `updated_at`): quem enviou, o SHA-256 e o
 * tamanho do que chegou, contra qual revisão reservada, o resultado e o motivo da recusa.
 *
 * O arquivo recusado NÃO é guardado (só o resumo); o aceito vira `document_versions`. As
 * conferências (`checks`) não carregam CPF nem nome — só códigos e fatos técnicos.
 * Aditiva e compatível com MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_signature_returns', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_signature_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->char('received_sha256', 64)->nullable();
            $table->unsignedBigInteger('received_size')->default(0);
            $table->char('expected_revision_sha256', 64)->nullable();
            // accepted | rejected
            $table->string('outcome', 16);
            $table->string('rejection_code', 64)->nullable();
            $table->json('checks')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['external_signature_request_id', 'created_at'], 'ext_sig_returns_request_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_signature_returns');
    }
};
