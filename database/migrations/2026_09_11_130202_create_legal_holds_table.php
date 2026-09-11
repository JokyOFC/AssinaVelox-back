<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.19 (K-RET) — bloqueio de exclusão por preservação ("legal hold").
|
| Escopo: `envelope`, `folder` (a pasta e as subpastas, avaliadas no momento da verificação)
| ou `organization` (tudo). Ativo = não liberado, já iniciado e sem `ends_at` vencido.
| As FKs de envelope/pasta são nullOnDelete: o registro do bloqueio é histórico e sobrevive ao
| objeto (depois de liberado); `subject_ulid` guarda a referência. Enquanto ATIVO, nenhum
| caminho de exclusão apaga o objeto — quem garante isso é App\Services\Retention\LegalHolds.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16); // envelope | folder | organization
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();
            $table->char('subject_ulid', 26)->nullable();
            $table->text('reason');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'released_at'], 'legal_holds_org_released_idx');
            $table->index(['envelope_id'], 'legal_holds_envelope_idx');
            $table->index(['folder_id'], 'legal_holds_folder_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
    }
};
