<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.19 (K-RET) — política de retenção por organização (docs/fase-2/retencao-e-preservacao.md).
|
| Uma linha por organização, com um prazo (em dias) por categoria. NULL = "não apagar
| automaticamente" nesta categoria. O roadmap sugeria uma linha por escopo; uma linha por
| organização deixa a validação cruzada (trilha >= documentos) numa única escrita atômica.
| Nasce inativa (`is_active = false`) e só é aplicada com a flag `retention_policies`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('completed_days')->nullable();
            $table->unsignedInteger('terminal_days')->nullable();
            $table->unsignedInteger('draft_days')->nullable();
            $table->unsignedInteger('identity_capture_days')->nullable();
            $table->unsignedInteger('dossier_days')->nullable();
            $table->unsignedInteger('audit_trail_days')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
