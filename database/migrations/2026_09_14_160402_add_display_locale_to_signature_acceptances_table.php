<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.3 — F-I18N (docs/fase-3/multilingue.md §6). Idioma em que a página pública foi
 * EXIBIDA a quem registrou o aceite.
 *
 * Nulo = flag `multilingual` desligada no aceite (a página era a PT-BR de sempre). A declaração
 * gravada em `consent_statement` e a versão em `terms_version` continuam sendo as do texto de
 * REFERÊNCIA em PT-BR — o texto em outro idioma é tradução de cortesia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_acceptances', function (Blueprint $table): void {
            $table->string('display_locale', 8)->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('signature_acceptances', function (Blueprint $table): void {
            $table->dropColumn('display_locale');
        });
    }
};
