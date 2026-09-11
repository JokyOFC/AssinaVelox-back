<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K-TSA (roadmap §2.13, Q12, Q23; docs/fase-2/carimbo-e-dossie.md §4) — pedidos de dossiê ZIP.
 *
 * `kind = single`: um envelope, idempotente por (envelope, versão final, formato, política de
 * exibição, carimbo) — `idempotency_key` UNIQUE por organização. `kind = bulk`: o "Baixar" em
 * lote, um ZIP externo com um dossiê por envelope (`envelope_ids`).
 *
 * O arquivo gerado mora no disco privado e é APAGADO depois de `expires_at` (`purged_at`); a
 * linha fica como registro de que houve exportação. Nenhum segredo: nem o link de download é
 * gravado (ele é uma URL assinada pela APP_KEY, com expiração).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_exports', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->foreignId('envelope_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('envelope_ids')->nullable();
            $table->string('idempotency_key', 191);
            // pending | building | ready | failed | expired
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('envelope_count')->default(1);
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('manifest_sha256', 64)->nullable();
            // granted | disabled | unavailable | not_applicable
            $table->string('timestamp_status', 16)->nullable();
            $table->unsignedBigInteger('timestamp_token_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key'], 'dossier_exports_idempotency_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_exports');
    }
};
