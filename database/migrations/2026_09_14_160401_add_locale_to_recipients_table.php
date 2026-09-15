<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.3 — F-I18N (docs/fase-3/multilingue.md §3). Idioma e fuso por participante.
 *
 * Aditiva: `locale` nasce `pt_BR` para todas as linhas (as existentes incluídas), e `timezone`
 * é opcional (nulo = fuso da organização). O valor de `locale` é sempre validado contra a lista
 * fechada de App\Support\Locale\SignerLocale; nunca é usado para montar caminho de arquivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->string('locale', 8)->default('pt_BR')->after('auth_method');
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->dropColumn(['locale', 'timezone']);
        });
    }
};
