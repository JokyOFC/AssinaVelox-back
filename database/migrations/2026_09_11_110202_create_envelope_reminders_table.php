<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.5 — registro dos lembretes automáticos (B-REM). Aditiva.
|
| Uma linha por (destinatário, número do lembrete). O UNIQUE é a trava de idempotência:
| duas execuções do comando, ou duas retentativas do mesmo job, não produzem dois lembretes
| com o mesmo número — a segunda esbarra na chave e não faz nada.
|
|  - status `sent`: o lembrete saiu (link novo emitido, e-mail enfileirado);
|  - status `skipped`: o job chegou e o envelope/destinatário já não aceitava lembrete
|    (estado terminal, recusou, deixou de ser a vez) — `reason` diz por quê.
|
| FKs: envelope e destinatário em CASCADE (a linha não é evidência; a evidência é o
| `reminder.sent` na trilha), organização em RESTRICT como as demais. A exclusão da
| organização (OrganizationPurge) apaga `recipients` antes de `organizations`, e o cascade
| leva estas linhas junto.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 16); // sent | skipped
            $table->string('reason', 40)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->unsignedBigInteger('access_link_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['recipient_id', 'sequence'], 'envelope_reminders_recipient_sequence_unique');
            $table->index(['envelope_id', 'status'], 'envelope_reminders_envelope_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_reminders');
    }
};
