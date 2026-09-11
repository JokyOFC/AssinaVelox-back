<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 §2.11 (C-ID) — cache da consulta pública de CNPJ (docs/fase-2/identidade.md §3).
 *
 * Um registro por CNPJ, sem organização: o dado é cadastral e público (dados abertos da
 * Receita Federal), igual para todas as organizações, e o cache não guarda QUEM consultou.
 * O `payload` é MINIMIZADO pelo adaptador: razão social, nome fantasia, situação, endereço e
 * CNAE principal. Sócios (QSA, com nome e CPF parcial), e-mail e telefones NUNCA entram.
 *
 * `status`: `found` (TTL longo, a base muda por mês) ou `not_found` (TTL curto). Falha,
 * tempo esgotado e resposta malformada não são cacheados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnpj_lookups', function (Blueprint $table): void {
            $table->id();
            $table->char('cnpj', 14)->unique();
            $table->string('status', 16);
            $table->json('payload')->nullable();
            $table->string('source', 40);
            $table->string('source_updated', 20)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnpj_lookups');
    }
};
