<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.1 — geração documental em lote (F-BULK, docs/fase-3/geracao-em-lote.md).
 *
 * Um lote = um modelo (versão FIXADA no envio da planilha) + uma planilha CSV/XLSX guardada no
 * disco privado com sha256. O arquivo sai do disco quando o lote termina, é cancelado ou
 * descartado; o sha256 fica.
 *
 * `status`: draft (arquivo lido, colunas por mapear) | validated (pré-validação feita) |
 * running (confirmado, cota reservada, linhas em geração) | completed | canceled.
 *
 * Sem ENUM de SQL nem DEFAULT em JSON/TEXT (compatível com MySQL 8); índices com nome explícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_generations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->unsignedInteger('template_version_number');
            $table->string('status', 16)->default('draft');

            // Planilha (dado não confiável, T6). `source_file` é o caminho no disco privado;
            // `source_filename` é só o nome saneado, para exibição.
            $table->string('source_file', 255)->nullable();
            $table->string('source_filename', 200);
            $table->string('source_format', 8); // csv | xlsx
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_size_bytes');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable(); // mode: review|send|schedule, scheduled_for

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('canceled_count')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('canceled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dry_run_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'bulk_generations_org_status_index');
            $table->index(['organization_id', 'created_at'], 'bulk_generations_org_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_generations');
    }
};
