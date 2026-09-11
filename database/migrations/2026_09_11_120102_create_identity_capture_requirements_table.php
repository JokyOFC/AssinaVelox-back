<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 §2.10 (C-ID) — o que o remetente exige de cada participante ANTES do aceite:
 * foto do rosto e/ou do documento (docs/fase-2/identidade.md §5).
 *
 * Tabela própria (e não coluna em `recipients`) para não mexer no modelo nem na
 * sincronização de destinatários: a linha sai em cascata com o destinatário ou o envelope.
 * `kinds` é a lista fechada `selfie | document_front | document_back`, validada no serviço.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_capture_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('kinds');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['envelope_id'], 'icr_envelope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_capture_requirements');
    }
};
