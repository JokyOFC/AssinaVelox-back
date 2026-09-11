<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.12 (K-A1) — uma assinatura criptográfica aplicada com o certificado do
 * participante, POR DOCUMENTO (roadmap: `participant_signatures`).
 *
 * `base_document_version_id` é ÚNICO: duas assinaturas nunca podem partir da mesma revisão.
 * É a garantia no banco de que não existem revisões irmãs (roadmap §2.12, "por que
 * participação paralela não permite dois workers na mesma revisão"), além do lock por
 * envelope no código. `(document_id, revision_index)` também é único.
 *
 * `certificate_reference_id` fica reservado (nulo): o certificado do participante não é
 * registrado em `certificate_references`, que continua sendo a tabela dos certificados da
 * operadora; os fatos públicos do certificado moram aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_signatures', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_signature_request_id')
                ->constrained('participant_signature_requests', indexName: 'participant_signatures_request_foreign')
                ->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_acceptance_id')->nullable()
                ->constrained('signature_acceptances', indexName: 'participant_signatures_acceptance_foreign')
                ->nullOnDelete();
            $table->foreignId('certificate_reference_id')->nullable()
                ->constrained('certificate_references', indexName: 'participant_signatures_certificate_foreign')
                ->nullOnDelete();
            $table->foreignId('base_document_version_id')
                ->constrained('document_versions', indexName: 'participant_signatures_base_version_foreign')
                ->cascadeOnDelete();
            $table->foreignId('signed_document_version_id')
                ->constrained('document_versions', indexName: 'participant_signatures_signed_version_foreign')
                ->cascadeOnDelete();
            $table->unsignedInteger('revision_index');
            $table->string('field_name', 100);
            $table->string('profile', 32)->default('PAdES-B-B');
            $table->string('subject', 512)->nullable();
            $table->string('issuer', 512)->nullable();
            $table->string('serial_number', 128)->nullable();
            $table->char('fingerprint_sha256', 64)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->json('validation_result')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique('base_document_version_id', 'participant_signatures_base_unique');
            $table->unique(['document_id', 'revision_index'], 'participant_signatures_revision_unique');
            $table->unique(['participant_signature_request_id', 'document_id'], 'participant_signatures_request_document_unique');
            $table->index(['envelope_id', 'recipient_id'], 'participant_signatures_envelope_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_signatures');
    }
};
