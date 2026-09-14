<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.5 (P3-GOV) — pedido de assinatura FORA da plataforma com devolução do PDF
 * (fluxo alternativo do gov.br: o participante assina no portal e devolve o arquivo).
 *
 * Uma linha por participante e DOCUMENTO do envelope (cada documento é assinado à parte no
 * portal). Guarda a revisão RESERVADA (`expected_*`: a versão exata entregue ao participante,
 * com o SHA-256 e o tamanho), o prazo da reserva e, depois de aceita, os fatos PÚBLICOS da
 * assinatura (CPF só mascarado) e o rótulo honesto (`signature_kind`). Nada de segredo:
 * nenhuma credencial do participante passa por aqui.
 *
 * Nome e colunas centrais seguem o roadmap §3.5 (recipient_id, provider,
 * expected_revision_sha256, status, expires_at). Aditiva e compatível com MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_signature_requests', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Só `govbr_portal` nesta fase (o participante assina no portal e devolve o PDF).
            $table->string('provider', 32)->default('govbr_portal');
            // ExternalSignatureRequestStatus: requested | pending | completed | expired | withdrawn | closed
            $table->string('status', 32)->default('requested');

            // Revisão reservada: os bytes exatos entregues ao participante.
            $table->foreignId('expected_document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->char('expected_revision_sha256', 64)->nullable();
            $table->unsignedBigInteger('expected_revision_size')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Prazo total para concluir (depois dele o pedido vence e o envelope segue sem ele).
            $table->timestamp('window_expires_at')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();

            // Resultado aceito.
            $table->foreignId('signed_document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            // GovBrSignatureKind: participant_govbr | participant_external_unverified
            $table->string('signature_kind', 40)->nullable();
            $table->boolean('trusted')->default(false);
            $table->string('field_name', 128)->nullable();
            $table->string('signer_subject', 512)->nullable();
            $table->string('signer_issuer', 512)->nullable();
            $table->string('signer_serial', 128)->nullable();
            $table->char('signer_fingerprint_sha256', 64)->nullable();
            $table->timestamp('signer_not_before')->nullable();
            $table->timestamp('signer_not_after')->nullable();
            $table->string('holder_name', 255)->nullable();
            $table->string('holder_cpf_masked', 20)->nullable();
            // match | mismatch | unknown (nunca o CPF).
            $table->string('holder_cpf_match', 16)->nullable();
            $table->boolean('is_test_certificate')->default(false);
            $table->json('validation_result')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'recipient_id', 'document_id'], 'ext_sig_requests_env_rec_doc_unique');
            $table->index(['envelope_id', 'status'], 'ext_sig_requests_env_status_index');
            $table->index(['document_id', 'status'], 'ext_sig_requests_doc_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_signature_requests');
    }
};
