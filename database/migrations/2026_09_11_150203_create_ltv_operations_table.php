<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P3-LTV — livro das operações de longo prazo (assinatura B-T/B-LT/B-LTA e re-carimbo).
 * Registra a degradação explícita (R5) e dá idempotência ao re-carimbo:
 * `idempotency_key` = refresh:{registro}:{posição}:{versão de origem}. Sem segredos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ltv_operations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // sign | refresh
            $table->string('kind', 16);
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('verification_record_id')
                ->nullable()
                ->constrained('verification_records', indexName: 'ltv_operations_record_foreign')
                ->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_document_version_id')
                ->nullable()
                ->constrained('document_versions', indexName: 'ltv_operations_source_version_foreign')
                ->nullOnDelete();
            $table->foreignId('result_document_version_id')
                ->nullable()
                ->constrained('document_versions', indexName: 'ltv_operations_result_version_foreign')
                ->nullOnDelete();
            $table->char('source_sha256', 64)->nullable();
            $table->char('result_sha256', 64)->nullable();
            // running | completed | degraded | failed
            $table->string('status', 16);
            $table->string('requested_level', 8)->nullable();
            $table->string('effective_level', 8)->nullable();
            $table->json('degradations')->nullable();
            // Seriais da TSA da operadora usados e não usados (públicos; nunca o token).
            $table->json('serials')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['verification_record_id', 'kind'], 'ltv_operations_record_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ltv_operations');
    }
};
