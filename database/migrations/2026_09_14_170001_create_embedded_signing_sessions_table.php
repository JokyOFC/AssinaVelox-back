<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-EMBED) — sessão de assinatura embutida (docs/fase-3/widget-embutido.md §3).
 *
 * Uma linha por URL de uso único criada pela API v1 para UM participante de UM envelope, presa
 * a UMA origem exata (`allowed_origin`). Nenhum token é gravado em claro:
 *
 * - `token_digest`: SHA-256 do token da URL (vai no fragmento `#t=`, nunca chega ao servidor
 *   pelo endereço). Serve uma vez: a troca grava `used_at` e emite o token de execução.
 * - `runtime_token_digest`: SHA-256 do token de execução, que o widget guarda só em memória e
 *   envia no cabeçalho `Authorization`. Vive `runtime_expires_at`.
 * - `session_state`: estado da sessão do participante que, no fluxo por e-mail, mora na sessão
 *   Laravel do navegador (token da `signing_sessions`, portão do PIN). CIFRADO com a APP_KEY
 *   (cast `encrypted:array`) — o widget embutido não depende de cookie de terceiro.
 * - `idempotency_key_digest` + `request_fingerprint`: repetição segura do POST de criação
 *   (mesma chave → mesma sessão com URL nova, enquanto não usada).
 *
 * `used_ip` chega TRUNCADO (/24 no IPv4, /48 no IPv6): o IP completo do aceite continua em
 * `signature_acceptances`, que é a evidência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedded_signing_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('recipients')->cascadeOnDelete();
            $table->foreignId('access_link_id')->constrained('recipient_access_links')->cascadeOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('allowed_origin', 255);
            $table->char('token_digest', 64)->unique();
            $table->char('runtime_token_digest', 64)->nullable()->unique();
            $table->text('session_state')->nullable();
            $table->char('idempotency_key_digest', 64)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('runtime_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('used_ip', 64)->nullable();
            $table->string('used_user_agent', 500)->nullable();
            $table->timestamps();

            $table->unique(['api_token_id', 'idempotency_key_digest'], 'embedded_sessions_idempotency_unique');
            $table->index(['recipient_id', 'revoked_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedded_signing_sessions');
    }
};
