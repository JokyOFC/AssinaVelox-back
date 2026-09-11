<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.3 — resumos publicados POR DOCUMENTO do envelope.
 *
 * O registro de verificação continua um por envelope (`verification_records`, código público
 * único). As colunas dele descrevem o PRIMEIRO documento (compatibilidade com a Fase 1); esta
 * tabela filha publica cada documento com seus quatro resumos e a versão final.
 *
 * Como o registro-pai, é reescrita (UPDATE) apenas pela retentativa da finalização quando o
 * arquivo final de um documento precisou ser reconstruído — nunca DELETE pela aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_record_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('verification_record_id')
                ->constrained('verification_records', indexName: 'verification_record_documents_record_foreign')
                ->cascadeOnDelete();
            $table->foreignId('document_id')
                ->nullable()
                ->constrained('documents', indexName: 'verification_record_documents_document_foreign')
                ->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('name', 200);
            $table->char('original_sha256', 64)->nullable();
            $table->char('sent_sha256', 64)->nullable();
            $table->char('consolidated_sha256', 64)->nullable();
            $table->char('final_sha256', 64);
            $table->foreignId('final_document_version_id')
                ->nullable()
                ->constrained('document_versions', indexName: 'verification_record_documents_final_foreign')
                ->nullOnDelete();
            $table->unsignedInteger('page_count')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['verification_record_id', 'position'], 'verification_record_documents_record_position_unique');
            $table->index('final_sha256', 'verification_record_documents_final_sha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_record_documents');
    }
};
