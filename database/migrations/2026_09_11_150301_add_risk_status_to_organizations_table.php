<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.7 — antifraude (P3-RISK, docs/fase-3/antifraude.md). Estado de risco da
 * organização: `normal` | `watch` | `restricted`. Só `restricted` tem efeito, e o único efeito
 * é suspender o ENVIO de novos envelopes (App\Services\Risk\SendingRestriction). Aditiva:
 * todas as linhas existentes nascem `normal`, e com a flag `antifraud` desligada a coluna não
 * é lida por ninguém.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('risk_status', 16)->default('normal');
            $table->timestamp('risk_status_changed_at')->nullable();

            $table->index('risk_status', 'organizations_risk_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex('organizations_risk_status_index');
            $table->dropColumn(['risk_status', 'risk_status_changed_at']);
        });
    }
};
