<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.19 (K-RET) — recibo de exclusão (o `deletion_receipts` do roadmap) e, para
| envelopes já publicados, o registro mínimo que a verificação pública consulta depois da
| exclusão ("tombstone").
|
| Prova a exclusão SEM guardar o conteúdo: categoria, momento, contagens e, conforme a decisão
| configurada (`assinavelox.retention.verification_after_purge`), só os resumos SHA-256 finais.
| Nunca título, nome de arquivo, organização por extenso, participante, e-mail ou IP.
|
| `pending_paths` existe só enquanto o recibo está `pending` (os caminhos no disco privado —
| apenas ULIDs — colhidos ANTES de apagar as linhas): uma execução interrompida entre o commit
| e a remoção dos arquivos é retomada pela próxima, e a lista é zerada ao concluir.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_deletions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('category', 32);
            $table->string('subject_type', 32); // envelope | identity_captures | dossiers | audit_trail
            $table->char('subject_ulid', 26)->nullable();
            $table->char('verification_code', 24)->nullable()->unique();
            $table->string('envelope_status', 32)->nullable();
            $table->timestamp('reference_at')->nullable();
            $table->json('final_hashes')->nullable();
            $table->json('manifest')->nullable();
            $table->json('pending_paths')->nullable();
            $table->string('status', 16)->default('pending'); // pending | completed
            $table->string('trigger', 16)->default('retention'); // retention | manual
            $table->foreignId('retention_run_id')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_ulid'], 'retention_deletions_subject_unique');
            $table->index(['organization_id', 'created_at'], 'retention_deletions_org_created_idx');
            $table->index(['status'], 'retention_deletions_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_deletions');
    }
};
