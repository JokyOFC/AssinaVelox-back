<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.1 — linhas de um lote de geração (F-BULK).
 *
 * `payload` guarda SÓ as colunas mapeadas da linha válida (valores das variáveis, nome/e-mail
 * de cada papel), cifrado pelo cast `encrypted:array`, e é apagado assim que o envelope nasce
 * ou a linha é cancelada (minimização). Linhas inválidas não guardam valor nenhum: só
 * `errors` (lista de {column, header, message}, mensagens sem o valor digitado).
 *
 * `status`: valid | invalid (pré-validação) → pending (lote confirmado) → queued →
 * processing → created | failed | canceled.
 * `outcome` (só em created): ready | draft | sent | scheduled | not_sent.
 *
 * `envelope_id` é UNIQUE: uma linha nunca aponta para dois envelopes e um envelope nunca vem
 * de duas linhas (idempotência por linha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_generation_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_index'); // linha da planilha (cabeçalho = 1)
            $table->longText('payload')->nullable();
            $table->string('status', 16);
            $table->json('errors')->nullable();
            $table->foreignId('envelope_id')->nullable()->unique('bulk_generation_rows_envelope_unique')->constrained()->nullOnDelete();
            $table->string('outcome', 16)->nullable();
            $table->string('outcome_message', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['bulk_generation_id', 'row_index'], 'bulk_generation_rows_batch_line_unique');
            $table->index(['bulk_generation_id', 'status'], 'bulk_generation_rows_batch_status_index');
            $table->index(['organization_id', 'status'], 'bulk_generation_rows_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_generation_rows');
    }
};
