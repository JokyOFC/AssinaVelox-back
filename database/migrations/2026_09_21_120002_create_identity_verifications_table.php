<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 4 §4.1 — cada TENTATIVA de verificação facial com documento enviada a um provedor
 * externo (docs/fase-4/verificacao-facial.md). A linha guarda o que a evidência precisa e
 * nada além disso:
 *
 * - `reference` (ULID nosso) é o `user_reference` enviado ao provedor e o que o webhook devolve;
 *   `provider_verification_id` é o protocolo DELE, quando existe.
 * - `status`: `queued` (na fila, ainda não enviada) → `pending` (o provedor está analisando) →
 *   `approved` | `rejected` | `expired` (conclusivos, nunca mudam) ou `inconclusive` (falha
 *   técnica ou prazo; não consome tentativa e pode ser refeita). Lista fechada, validada no serviço.
 * - `provider_result` é o `details` já limpo pelo adaptador: sem imagem, sem dado lido do
 *   documento, sem segredo. `captures` lista `{capture_ulid, kind, sha256}` das imagens enviadas
 *   — NUNCA bytes nem caminho no disco.
 * - `consented_at`/`consent_version`: o participante autorizou o envio antes de enviar; a versão
 *   é o SHA-256 do texto exibido. `attempt` é o número desta tentativa para o participante.
 * - `polled_at`: última consulta ao provedor (evita consultar a cada recarga da página).
 *
 * Só aditiva; índices e chaves com nome curto (MySQL 8 recusa identificadores acima de 64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique('iv_ulid_unique');
            $table->foreignId('organization_id')->constrained(indexName: 'iv_organization_foreign')->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained(indexName: 'iv_envelope_foreign')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained(indexName: 'iv_recipient_foreign')->cascadeOnDelete();
            $table->foreignId('signing_session_id')->nullable()->constrained(indexName: 'iv_signing_session_foreign')->nullOnDelete();
            $table->foreignId('signature_acceptance_id')->nullable()->constrained(indexName: 'iv_signature_acceptance_foreign')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_verification_id', 120)->nullable();
            $table->char('reference', 26)->unique('iv_reference_unique');
            $table->string('document_type', 20);
            $table->string('status', 20);
            $table->string('reason_code', 60)->nullable();
            $table->json('provider_result')->nullable();
            $table->json('captures');
            $table->unsignedSmallInteger('attempt');
            $table->timestamp('consented_at');
            $table->string('consent_version', 64);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->index(['provider_verification_id'], 'iv_provider_verification_idx');
            $table->index(['status'], 'iv_status_idx');
            $table->index(['recipient_id', 'status'], 'iv_recipient_status_idx');
            $table->index(['envelope_id'], 'iv_envelope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
