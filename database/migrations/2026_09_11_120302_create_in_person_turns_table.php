<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.6 (C-PRES) — a VEZ de cada participante numa sessão presencial
| (docs/fase-2/presencial-e-lote.md §2.3).
|
| Uma linha por participante chamado no dispositivo. É a evidência do "presencial": qual
| sessão (e portanto qual anfitrião e dispositivo), qual sessão de assinatura autenticada
| pelo PRÓPRIO participante (`signing_session_id`) e qual aceite resultou
| (`signature_acceptance_id`). Uma vez encerrada (aceite, bloqueio, expiração), a vez não
| reabre: o próximo participante recebe outra linha, outro segredo e outra sessão.
|
| `turn_secret_digest`: SHA-256 do segredo da vez, guardado só na sessão Laravel do
| dispositivo; zerado ao encerrar, para que nem uma cópia antiga do cookie reabra a vez.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_person_turns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('in_person_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signing_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signature_acceptance_id')->nullable()->constrained()->nullOnDelete();
            $table->char('turn_secret_digest', 64)->nullable()->unique();
            $table->string('status', 16)->default('active');
            $table->string('close_reason', 24)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['in_person_session_id', 'status'], 'in_person_turns_session_status_idx');
            $table->index(['recipient_id'], 'in_person_turns_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_person_turns');
    }
};
