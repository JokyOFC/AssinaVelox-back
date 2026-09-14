<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.7 — sinais do antifraude (P3-RISK). APPEND-ONLY: sem updated_at; o model recusa
 * update/delete.
 *
 * `evidence` é minimizada por App\Services\Risk\RiskEvidence (lista fechada de chaves por
 * regra, só escalares curtos, IP truncado; nunca conteúdo de documento, e-mail, telefone ou
 * CPF). `subject_key` é HMAC-SHA256 do sujeito (link, prefixo de IP, dispositivo declarado),
 * nunca o valor bruto. `fingerprint` torna a gravação idempotente por regra/sujeito/janela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_signals', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_code', 64);
            $table->unsignedSmallInteger('score');
            $table->char('subject_key', 64)->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->json('evidence')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'id'], 'risk_signals_org_id_index');
            $table->index(['organization_id', 'occurred_at'], 'risk_signals_org_occurred_index');
            $table->index(['rule_code', 'occurred_at'], 'risk_signals_rule_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_signals');
    }
};
