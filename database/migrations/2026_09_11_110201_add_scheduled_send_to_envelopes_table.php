<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.5 — envio agendado (B-REM). Aditiva.
|
| `scheduled_send_at` (UTC): quando o envelope `ready` deve ser enviado. Sem novo status —
| o enum público de `envelopes.status` não muda (roadmap §2.5, recomendação).
| `scheduled_send_audit_id`: id do `audit_events` `envelope.scheduled` que criou o
| agendamento. Serve de marco: qualquer edição registrada DEPOIS dele cancela o agendamento
| (ver App\Services\Envelopes\Sending\ScheduledSend). Sem FK de propósito: a trilha é
| append-only e a coluna é só um marcador de posição.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->timestamp('scheduled_send_at')->nullable();
            $table->unsignedBigInteger('scheduled_send_audit_id')->nullable();

            $table->index('scheduled_send_at', 'envelopes_scheduled_send_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropIndex('envelopes_scheduled_send_at_index');
            $table->dropColumn(['scheduled_send_at', 'scheduled_send_audit_id']);
        });
    }
};
