<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.7 (C-PRES) — link de assinatura em lote (docs/fase-2/presencial-e-lote.md §3).
|
| Um link por pessoa (e-mail) e por organização remetente. Só o digest do token do link
| (`token_digest`) e do token da autenticação no navegador (`session_token_digest`) são
| gravados; o e-mail entra como HMAC (`email_digest`), porque o lote nunca procura
| envelopes "por e-mail" depois de emitido: os itens ficam congelados em
| `batch_signing_items` no momento da emissão (nada de documento futuro).
|
| `anchor_recipient_id`: participante a partir do qual o remetente emitiu o link; é para o
| e-mail DELE que o código vai. Se o e-mail dele mudar, o `email_digest` deixa de bater e o
| link morre.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_signing_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('anchor_recipient_id')->nullable()->constrained('recipients')->nullOnDelete();
            $table->char('email_digest', 64);
            $table->char('token_digest', 64)->unique();
            $table->char('session_token_digest', 64)->nullable()->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email_digest'], 'batch_sessions_org_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_signing_sessions');
    }
};
