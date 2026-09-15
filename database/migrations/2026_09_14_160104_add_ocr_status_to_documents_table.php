<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.2 (F-ANCHOR) — `documents.ocr_status`: not_needed | pending | done | failed |
 * unavailable. docs/fase-3/ancoras-e-ocr.md §6.
 *
 * Nulo = nunca avaliado (o padrão, e o único valor com as flags `field_anchors`/`ocr`
 * desligadas). Coluna aditiva e anulável: nenhum documento existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('ocr_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('ocr_status');
        });
    }
};
