<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.4 (P3-EXT) — por qual MEIO o participante optou por assinar com o próprio
 * certificado. Aditiva: `signature_method` nulo = A1 por arquivo (Fase 2 §2.12, o
 * comportamento de hoje); `local_component` = assinatura feita fora do servidor (componente
 * local A3 ou serviço que devolve CMS). `signing_component` = componente declarado na última
 * preparação (`simulated`, `nexu`, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_signature_requests', function (Blueprint $table) {
            $table->string('signature_method', 32)->nullable();
            $table->string('signing_component', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('participant_signature_requests', function (Blueprint $table) {
            $table->dropColumn(['signature_method', 'signing_component']);
        });
    }
};
