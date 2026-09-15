<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.3 (F-FLOW) — etapas condicionais do envelope (docs/fase-3/etapas-e-delegacao.md §2).
 *
 * Uma linha por etapa. O "index" do roadmap chama-se `step_index` porque INDEX é palavra
 * reservada do MySQL. `condition` é o JSON de um esquema FECHADO (StepCondition): nunca
 * expressão, código ou regex. `status`: pending (não alcançada) | active (alcançada, condição
 * verdadeira ou ausente) | skipped (condição falsa). `evaluation` guarda a regra e os valores
 * avaliados no momento em que a etapa foi alcançada — é evidência, não cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_steps', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_index');
            $table->string('name', 80)->nullable();
            $table->json('condition')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('evaluated_at')->nullable();
            $table->json('evaluation')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'step_index']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_steps');
    }
};
