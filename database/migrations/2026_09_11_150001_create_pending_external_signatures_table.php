<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.4 (P3-EXT) — reserva de revisão para uma assinatura feita FORA do servidor
 * (componente local A3 ou serviço que devolve CMS). docs/fase-3/assinatura-externa-a3.md.
 *
 * Uma linha por preparação: participante + documento + revisão-base. Guarda o digest
 * entregue ao componente, o hash da revisão-base, o prazo curto (TTL) e o estado. Nunca
 * chave, senha, PIN ou identificador de sessão do token: o servidor não os recebe.
 *
 * `reservation_key` = `document_id` enquanto a reserva está ATIVA e nulo depois: o índice
 * único garante no banco que um documento tem no máximo uma reserva ativa (sem revisões
 * irmãs), além do lock do envelope no código. Nulos múltiplos são aceitos no MySQL 8 e no
 * SQLite. Consumo único: a linha sai de `pending` uma única vez (update condicional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_external_signatures', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_signature_request_id')
                ->constrained('participant_signature_requests', indexName: 'pending_ext_sig_request_foreign')
                ->cascadeOnDelete();
            $table->foreignId('base_document_version_id')
                ->constrained('document_versions', indexName: 'pending_ext_sig_base_version_foreign')
                ->cascadeOnDelete();
            $table->char('base_sha256', 64);
            $table->string('field_name', 100);

            // PendingExternalSignatureStatus: pending | embedding | applied | rejected | expired | superseded | discarded
            $table->string('status', 32)->default('pending');
            // raw (assinatura bruta + certificado) | cms (CMS/PKCS#7 pronto)
            $table->string('mode', 16);
            // LocalSignerComponent: simulated | nexu | ...
            $table->string('component', 32);
            $table->boolean('is_simulated')->default(false);

            // O digest entregue ao componente (hex). Não é segredo, mas só sai na resposta da
            // preparação; nunca em log, evento ou fila.
            $table->char('digest_hex', 64);
            $table->char('state_sha256', 64);
            $table->char('pending_sha256', 64);
            $table->unsignedBigInteger('pending_size');

            // Fatos PÚBLICOS do certificado anunciado (CPF só mascarado).
            $table->char('certificate_fingerprint_sha256', 64);
            $table->string('certificate_subject', 512)->nullable();
            $table->string('certificate_issuer', 512)->nullable();
            $table->string('certificate_serial', 128)->nullable();
            $table->timestamp('certificate_not_before')->nullable();
            $table->timestamp('certificate_not_after')->nullable();
            $table->boolean('is_test_certificate')->default(false);
            $table->json('certificate_facts')->nullable();
            $table->json('chain_trust')->nullable();

            $table->unsignedBigInteger('reservation_key')->nullable()->unique('pending_ext_sig_reservation_unique');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->foreignId('signed_document_version_id')->nullable()
                ->constrained('document_versions', indexName: 'pending_ext_sig_signed_version_foreign')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['envelope_id', 'status'], 'pending_ext_sig_envelope_status_index');
            $table->index(['participant_signature_request_id', 'document_id'], 'pending_ext_sig_request_document_index');
            $table->index(['status', 'expires_at'], 'pending_ext_sig_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_external_signatures');
    }
};
