<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K-TSA (revisão adversarial da onda C): o carimbo guarda se o certificado da TSA que o
 * emitiu é de TESTE (o pdftool reconhece e devolve `tsa_certificate_test`). A exibição passa
 * a tratar como teste todo carimbo com `environment != production` OU certificado de teste —
 * a mesma regra do dossiê. Aditiva; linhas antigas ficam `false` (a regra do ambiente segue
 * valendo para elas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timestamp_tokens', function (Blueprint $table) {
            $table->boolean('test_certificate')->default(false)->after('environment');
        });
    }

    public function down(): void
    {
        Schema::table('timestamp_tokens', function (Blueprint $table) {
            $table->dropColumn('test_certificate');
        });
    }
};
