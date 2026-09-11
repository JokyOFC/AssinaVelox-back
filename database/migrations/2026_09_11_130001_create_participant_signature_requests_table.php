<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.12 (K-A1) — pedido de assinatura com o certificado A1 do PRÓPRIO participante.
 *
 * Uma linha por participante e envelope. Guarda a escolha, o consentimento específico
 * (versão + texto resolvido) e os fatos PÚBLICOS do certificado inspecionado. **Nunca** o PFX,
 * a senha ou material de chave: enquanto espera o worker, o conjunto PFX + senha fica
 * cifrado num arquivo temporário identificado por `sealed_ulid` (com `sealed_expires_at`),
 * apagado ao ser consumido — o banco só conhece o identificador.
 *
 * Aditiva e compatível com MySQL 8 (docs/fase-2/a1-do-participante.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_signature_requests', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            // ParticipantSignatureRequestStatus: requested | queued | applying | applied | failed | expired | withdrawn
            $table->string('status', 32)->default('requested');

            // Consentimento específico ("autorizo o uso deste certificado…"): versão + texto resolvido.
            $table->string('consent_version', 64)->nullable();
            $table->text('consent_statement')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_ip', 45)->nullable();

            // Fatos públicos do certificado (pdftool inspect-cert). CPF só mascarado.
            $table->string('subject', 512)->nullable();
            $table->string('subject_cn', 255)->nullable();
            $table->string('issuer', 512)->nullable();
            $table->string('issuer_cn', 255)->nullable();
            $table->string('serial_number', 128)->nullable();
            $table->char('fingerprint_sha256', 64)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->string('holder_cpf_masked', 20)->nullable();
            $table->boolean('is_test_certificate')->default(false);
            $table->json('certificate_facts')->nullable();

            // Material cifrado temporário: só o identificador e o prazo.
            $table->char('sealed_ulid', 26)->nullable()->unique();
            $table->timestamp('sealed_expires_at')->nullable();
            // Prazo para o participante aplicar o certificado depois de o conteúdo congelar.
            $table->timestamp('window_expires_at')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'recipient_id'], 'participant_sig_requests_envelope_recipient_unique');
            $table->index(['envelope_id', 'status'], 'participant_sig_requests_envelope_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_signature_requests');
    }
};
