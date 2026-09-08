<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('original_filename', 255);
            $table->string('source_type', 32); // DocumentSourceType: pdf | docx | image
            $table->string('processing_status', 32)->default('uploaded'); // DocumentProcessingStatus
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            // FK adicionada abaixo, depois de document_versions existir.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->timestamps();

            // Fase 1: exatamente 1 documento por envelope (regra de serviço + índice para a consulta).
            $table->index(['envelope_id', 'processing_status']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('kind', 32); // DocumentVersionKind
            $table->string('storage_disk', 64);
            $table->string('storage_path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('page_count')->nullable();
            // por página: width_pt, height_pt, rotation, mediabox[4], cropbox[4]
            $table->json('pages_meta')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('has_signatures')->default(false);
            $table->string('created_by_type', 32)->nullable(); // ActorType
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'version_number']);
            $table->index(['document_id', 'kind']);
            $table->index('sha256');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete();
        });

        Schema::table('envelopes', function (Blueprint $table) {
            $table->foreign('sent_document_version_id', 'envelopes_sent_version_foreign')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete();
            $table->foreign('final_document_version_id', 'envelopes_final_version_foreign')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropForeign('envelopes_sent_version_foreign');
            $table->dropForeign('envelopes_final_version_foreign');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
