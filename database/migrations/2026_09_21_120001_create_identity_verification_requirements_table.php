<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 4 §4.1 — o remetente exige de um participante a VERIFICAÇÃO FACIAL COM DOCUMENTO por um
 * provedor externo antes do aceite (docs/fase-4/verificacao-facial.md). Tabela própria, como a
 * do vídeo curto: a exigência de fotos (Fase 2 §2.10) continua exatamente como é — ligar esta
 * exigência acrescenta `selfie`, `document_front` e `document_back` àquela lista pelo serviço,
 * nunca por aqui.
 *
 * Uma linha por destinatário; sai em cascata com o destinatário ou o envelope. Só aditiva e
 * compatível com MySQL 8 (índices com nome curto: o limite é 64 caracteres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verification_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained(indexName: 'ivr_req_organization_foreign')->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained(indexName: 'ivr_req_envelope_foreign')->cascadeOnDelete();
            $table->foreignId('recipient_id')->unique('ivr_req_recipient_unique')->constrained(indexName: 'ivr_req_recipient_foreign')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', indexName: 'ivr_req_created_by_foreign')->nullOnDelete();
            $table->timestamps();

            $table->index(['envelope_id'], 'ivr_req_envelope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_requirements');
    }
};
