<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.15 (D-API) — chaves `Idempotency-Key` por token (docs/fase-2/api-v1.md §7).
 *
 * Uma linha por (token, chave): a impressão digital do pedido (método, rota, parâmetros,
 * corpo e arquivos) e, depois de concluído, a resposta armazenada por 24 h. O UNIQUE
 * (token, chave) é o que decide a corrida: só uma requisição consegue reservar a chave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);
            $table->string('method', 10);
            $table->string('route', 120);
            // processing | completed
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['personal_access_token_id', 'idempotency_key'], 'api_idempotency_token_key_unique');
            $table->index(['personal_access_token_id', 'expires_at'], 'api_idempotency_token_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
