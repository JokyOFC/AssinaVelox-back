<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.4 — posição de exibição do participante, separada da vez de assinar.
 *
 * Até aqui a lista de participantes era ordenada por `order_index`, que é a VEZ na
 * ordem de assinatura. Com papéis, o visualizador não tem vez (`order_index = 0`) e
 * passava a aparecer no topo da lista em todas as telas, acima do primeiro signatário,
 * desfazendo a ordem em que o remetente montou a lista. O mesmo acontecia, antes dos
 * papéis, ao alternar entre paralelo e sequencial: em paralelo todos ficam na vez 1 e a
 * ordem arrumada pelo remetente se perdia.
 *
 * `position` guarda a ordem da lista (1..N) e passa a ser a ordenação de exibição;
 * `order_index` continua sendo a única fonte da vez de assinar.
 *
 * Preenchimento: para cada envelope, numera os participantes pela ordenação que valia
 * até agora (`order_index`, depois `id`). Assim nenhum envelope existente muda de ordem
 * na tela. Feito em PHP, por envelope, para funcionar igual em MySQL e SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0)->after('order_index');
            $table->index(['envelope_id', 'position'], 'recipients_envelope_position_index');
        });

        DB::table('recipients')
            ->select('envelope_id')
            ->distinct()
            ->orderBy('envelope_id')
            ->chunk(500, function ($envelopes): void {
                foreach ($envelopes as $envelope) {
                    $ids = DB::table('recipients')
                        ->where('envelope_id', $envelope->envelope_id)
                        ->orderBy('order_index')
                        ->orderBy('id')
                        ->pluck('id');

                    foreach ($ids->values() as $index => $id) {
                        DB::table('recipients')->where('id', $id)->update(['position' => $index + 1]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->dropIndex('recipients_envelope_position_index');
            $table->dropColumn('position');
        });
    }
};
