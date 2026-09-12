<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.15 (D-API) — registro mínimo das requisições da API v1 (aba "Logs").
 *
 * Só metadados: token, rota (nome e padrão, nunca a URL com parâmetros), status, duração e
 * correlação. Sem corpo, sem cabeçalhos, sem IP e sem dado pessoal. Retenção curta
 * (`assinavelox.api.request_logs.retention_days`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('method', 10);
            $table->string('route', 120)->nullable();
            $table->string('path_pattern', 191)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('correlation_id', 64)->nullable();
            $table->boolean('idempotent_replay')->default(false);
            $table->timestamp('occurred_at');

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['personal_access_token_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
