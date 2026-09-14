<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.7 — observações de curta duração do antifraude (P3-RISK). Só existe para contar
 * o que nenhuma tabela do domínio guarda: cadastros por prefixo de IP e por dispositivo
 * declarado (regra `serial_signup`). Guarda apenas o HMAC do sujeito, nunca o IP ou o
 * identificador bruto, e é podada depois de `assinavelox.risk.observation_retention_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_observations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->char('subject_key', 64);
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('observed_at');

            $table->index(['kind', 'subject_key', 'observed_at'], 'risk_observations_lookup_index');
            $table->index('observed_at', 'risk_observations_observed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_observations');
    }
};
