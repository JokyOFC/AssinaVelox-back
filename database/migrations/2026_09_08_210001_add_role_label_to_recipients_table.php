<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papel livre do signatário digitado no passo 2 do wizard ("Locatária", "Fiador",
 * "Testemunha"…). É rótulo de exibição, não autorização: `recipients.role` continua sendo
 * o enum RecipientRole (`signer` na Fase 1, ROUTES §2.6 / RECONCILIACAO §1).
 *
 * Registrado no incremento 1 como pendência ("recipients[].role sempre null").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('role_label', 40)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('role_label');
        });
    }
};
