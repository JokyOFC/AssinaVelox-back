<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.3 (F-FLOW) — o envelope conduz a vez pelas etapas (`signing_steps`) em vez de só
 * pelo `signing_order`. Falso por padrão: sem etapas, `current_order` funciona como antes.
 * Coluna (e não chave de `settings`) porque é lida em todo acesso do signatário e em cada
 * aceite, sob lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->boolean('uses_signing_steps')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropColumn('uses_signing_steps');
        });
    }
};
