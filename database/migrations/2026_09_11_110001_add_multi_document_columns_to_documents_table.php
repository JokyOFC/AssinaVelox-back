<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.3 — envelope com múltiplos documentos (docs/fase-2/multi-documento-e-papeis.md).
 *
 * - `position`: ordem de apresentação do arquivo dentro do envelope (1..N). Na Fase 1 há um
 *   único documento e ele fica em 1 (o default).
 * - `sent_version_id`: versão congelada NO ENVIO para este documento. É a versão que o
 *   participante vê, que o aceite referencia e sobre a qual os campos foram posicionados.
 *   `envelopes.sent_document_version_id` continua preenchido com a do PRIMEIRO documento
 *   (compatibilidade com todo o código e dados da Fase 1).
 * - `final_version_id`: versão final (consolidado + evidências + assinatura da operadora,
 *   quando aplicada) deste documento. `envelopes.final_document_version_id` continua sendo a
 *   do primeiro.
 *
 * Por que colunas em `documents` e não uma tabela `envelope_sent_documents`: a relação é 1:1
 * por documento e imutável depois do envio; uma tabela extra só acrescentaria um JOIN e um
 * segundo lugar onde a mesma verdade poderia divergir. É também o desenho do roadmap §2.3.
 *
 * Migration aditiva: nada existente muda de tipo ou de valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(1)->after('organization_id');
            $table->unsignedBigInteger('sent_version_id')->nullable()->after('current_version_id');
            $table->unsignedBigInteger('final_version_id')->nullable()->after('sent_version_id');

            $table->index(['envelope_id', 'position'], 'documents_envelope_position_index');

            $table->foreign('sent_version_id', 'documents_sent_version_foreign')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete();

            $table->foreign('final_version_id', 'documents_final_version_foreign')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('documents_sent_version_foreign');
            $table->dropForeign('documents_final_version_foreign');
            $table->dropIndex('documents_envelope_position_index');
            $table->dropColumn(['position', 'sent_version_id', 'final_version_id']);
        });
    }
};
