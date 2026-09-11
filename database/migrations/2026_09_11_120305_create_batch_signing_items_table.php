<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.7 (C-PRES) — itens do lote (docs/fase-2/presencial-e-lote.md §3.2).
|
| Congelados na emissão do link: um item por participação pendente da MESMA organização
| remetente, com o mesmo e-mail. Documento que chegar depois não entra (não há
| consentimento para documento futuro). Cada item autorizado aponta para o SEU aceite
| (`signature_acceptance_id`), gravado pelo mesmo `RecordAcceptance` do fluxo individual, com
| a SUA sessão de assinatura, o SEU snapshot e os SEUS campos. UNIQUE(lote, participante).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_signing_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('batch_signing_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signing_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signature_acceptance_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('status', 16)->default('pending');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->string('last_error_code', 48)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_signing_session_id', 'recipient_id'], 'batch_items_session_recipient_unique');
            $table->index(['envelope_id'], 'batch_items_envelope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_signing_items');
    }
};
