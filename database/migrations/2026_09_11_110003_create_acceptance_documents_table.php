<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.3 — o que um aceite cobriu, POR DOCUMENTO.
 *
 * Um participante registra UM aceite (`signature_acceptances`, UNIQUE(recipient_id) mantido)
 * que cobre o conjunto de documentos do envelope. Esta tabela registra, para cada documento
 * coberto, a versão exata apresentada, o seu SHA-256 e o snapshot dos campos daquele
 * documento com os valores gravados.
 *
 * Append-only como `signature_acceptances`: a aplicação só insere e lê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signature_acceptance_id')
                ->constrained('signature_acceptances', indexName: 'acceptance_documents_acceptance_foreign')
                ->cascadeOnDelete();
            $table->foreignId('document_id')
                ->constrained('documents', indexName: 'acceptance_documents_document_foreign')
                ->cascadeOnDelete();
            $table->foreignId('document_version_id')
                ->constrained('document_versions', indexName: 'acceptance_documents_version_foreign')
                ->cascadeOnDelete();
            $table->foreignId('organization_id')
                ->constrained('organizations', indexName: 'acceptance_documents_organization_foreign')
                ->restrictOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->char('document_sha256', 64);
            $table->json('fields_snapshot')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['signature_acceptance_id', 'document_id'], 'acceptance_documents_acceptance_document_unique');
            $table->index('document_id', 'acceptance_documents_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_documents');
    }
};
