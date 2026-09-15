<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.9 — G-CONN (docs/fase-3/conectores.md §3). Registro de cada importação de arquivo
 * do Google Drive ou do Dropbox: origem, id externo, hash e quem importou.
 *
 * Aditiva. NUNCA guarda token, link de download nem conteúdo: só o que identifica a origem. O
 * arquivo em si entra pelo MESMO caminho de um upload (DocumentIntake) e vira `documents` +
 * `document_versions(kind=original)`; esta linha aponta para ele. Importação recusada também
 * fica registrada (status `rejected` + código), sem documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            // google_drive | dropbox (App\Services\CloudImport\CloudProvider).
            $table->string('provider', 20);
            // Id do arquivo no provedor (Drive: fileId; Dropbox: "id:…"). Não é segredo.
            $table->string('external_id', 255)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // completed | rejected.
            $table->string('status', 20);
            $table->string('rejection_code', 64)->nullable();
            // true = simulador identificado (testes/local), nunca um provedor real.
            $table->boolean('simulated')->default(false);
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['envelope_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_imports');
    }
};
