<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_records', function (Blueprint $table) {
            $table->id();
            $table->char('code', 24)->unique(); // = envelopes.verification_code
            $table->foreignId('envelope_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('final_document_version_id')
                ->nullable()
                ->constrained('document_versions', indexName: 'verification_records_final_version_foreign')
                ->nullOnDelete();
            $table->char('original_sha256', 64)->nullable();
            $table->char('sent_sha256', 64)->nullable();
            $table->char('consolidated_sha256', 64)->nullable();
            $table->char('final_sha256', 64); // calculado DEPOIS da assinatura; nunca dentro do PDF
            $table->string('signature_status', 32)->default('none'); // SignatureStatus: none | company_a1
            $table->string('signature_profile', 32)->nullable(); // ex.: PAdES-B-B
            $table->foreignId('certificate_reference_id')
                ->nullable()
                ->constrained('certificate_references', indexName: 'verification_records_certificate_foreign')
                ->nullOnDelete();
            $table->json('validation_result')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->index('final_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_records');
    }
};
