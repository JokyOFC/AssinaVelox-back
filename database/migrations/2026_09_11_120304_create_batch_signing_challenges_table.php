<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.7 (C-PRES) — código de uso único do lote (docs/fase-2/presencial-e-lote.md §3.3).
|
| Mesmas garantias de `auth_challenges`: código de 6 dígitos por CSPRNG, só o HMAC gravado
| (`Challenges::hashCode`, sal = ULID da linha), 10 minutos, 5 tentativas, uso único, um
| código vivo por vez. Tabela própria porque o código do lote não pertence a um envelope nem
| a uma sessão de assinatura: ele abre o lote, e cada item ganha depois a SUA sessão.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_signing_challenges', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('batch_signing_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('delivery_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['batch_signing_session_id'], 'batch_challenges_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_signing_challenges');
    }
};
