<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 §2.10 (C-ID) — captura SIMPLES de foto do rosto e do documento
 * (docs/fase-2/identidade.md §5). Não é biometria: nada aqui compara rostos, detecta
 * vivacidade ou lê o documento.
 *
 * A imagem já chega normalizada (reencodada com GD, sem EXIF/GPS, lado máximo limitado) e é
 * gravada CIFRADA no disco privado `documents`, sob `orgs/{org}/envelopes/{env}/identity/`.
 * A linha guarda só o que a evidência precisa: tipo, SHA-256 dos bytes normalizados,
 * dimensões, tamanho e momento. `storage_path` fica nulo depois da exclusão pela política de
 * retenção (`purged_at`): o registro de que houve a captura continua; a imagem, não.
 *
 * Antes do aceite a linha pertence à sessão de assinatura (`signing_session_id`); o aceite a
 * vincula (`signature_acceptance_id`). Uma captura refeita antes do aceite substitui a
 * anterior do mesmo tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_captures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signing_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signature_acceptance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->string('storage_path', 255)->nullable();
            $table->char('sha256', 64);
            $table->string('mime_type', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->string('source', 16)->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'kind'], 'identity_captures_recipient_kind_idx');
            $table->index(['envelope_id'], 'identity_captures_envelope_idx');
            $table->index(['captured_at'], 'identity_captures_captured_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_captures');
    }
};
