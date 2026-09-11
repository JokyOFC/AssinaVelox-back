<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.3 — apresentação de CADA documento a uma sessão de assinatura.
 *
 * `signing_sessions.document_presented_at` (Fase 1) marca que os bytes do documento saíram do
 * servidor para aquela sessão. Com N documentos a marca precisa ser por documento: o aceite só
 * é gravado depois de TODOS terem sido entregues à sessão (RecordAcceptance). O que isto prova
 * é a ENTREGA do arquivo, não a leitura — mesma ressalva da Fase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_session_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signing_session_id')
                ->constrained('signing_sessions', indexName: 'signing_session_documents_session_foreign')
                ->cascadeOnDelete();
            $table->foreignId('document_id')
                ->constrained('documents', indexName: 'signing_session_documents_document_foreign')
                ->cascadeOnDelete();
            $table->foreignId('document_version_id')
                ->constrained('document_versions', indexName: 'signing_session_documents_version_foreign')
                ->cascadeOnDelete();
            $table->foreignId('organization_id')
                ->constrained('organizations', indexName: 'signing_session_documents_organization_foreign')
                ->restrictOnDelete();
            $table->timestamp('presented_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['signing_session_id', 'document_id'], 'signing_session_documents_session_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_session_documents');
    }
};
