<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P3-LTV — histórico de resumos finais (viabilidade §4.5 item 29). O re-carimbo LTA gera um
 * arquivo novo, com novo SHA-256: o resumo VIGENTE continua em `verification_records`
 * (e em `verification_record_documents`); aqui ficam o vigente e os anteriores, com as datas.
 * Enquanto um documento só tiver um resumo, nenhuma linha é criada e nada muda na resposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_hash_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_record_id')
                ->constrained('verification_records', indexName: 'vhh_verification_record_foreign')
                ->cascadeOnDelete();
            $table->foreignId('verification_record_document_id')
                ->nullable()
                ->constrained('verification_record_documents', indexName: 'vhh_record_document_foreign')
                ->cascadeOnDelete();
            // Posição do documento no envelope (1 para envelopes de um documento).
            $table->unsignedInteger('position')->default(1);
            $table->foreignId('document_version_id')
                ->nullable()
                ->constrained('document_versions', indexName: 'vhh_document_version_foreign')
                ->nullOnDelete();
            $table->char('sha256', 64);
            // finalized | ltv_refresh
            $table->string('reason', 32);
            $table->string('ltv_status', 16)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['verification_record_id', 'position', 'valid_from'], 'vhh_record_position_index');
            $table->index('sha256', 'vhh_sha256_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_hash_history');
    }
};
