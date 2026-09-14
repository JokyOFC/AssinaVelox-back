<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.4 (P3-EXT) — o que cada assinatura de participante É (roadmap T1).
 *
 * - `signature_status`: nulo = A1 por arquivo (Fase 2, comportamento de hoje);
 *   `participant_a3` = certificado A3 em token/cartão por componente local REAL;
 *   `participant_external` = feita fora do servidor quando a origem não é um token
 *   comprovado (inclusive o simulador).
 * - `signing_component`: componente que produziu a assinatura (`simulated`, `nexu`, ...).
 * - `is_simulated`: produzida pelo simulador — nenhum token foi usado. Nunca vira A3.
 * - `pending_external_signature_id`: a reserva consumida (rastreabilidade).
 *
 * Aditiva; as linhas existentes ficam com os valores padrão (A1, não simulada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_signatures', function (Blueprint $table) {
            $table->string('signature_status', 32)->nullable();
            $table->string('signing_component', 32)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->foreignId('pending_external_signature_id')->nullable()
                ->constrained('pending_external_signatures', indexName: 'participant_signatures_pending_ext_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('participant_signatures', function (Blueprint $table) {
            $table->dropForeign('participant_signatures_pending_ext_foreign');
            $table->dropColumn(['signature_status', 'signing_component', 'is_simulated', 'pending_external_signature_id']);
        });
    }
};
