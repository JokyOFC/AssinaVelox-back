<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.19 (K-RET) — trilha própria da retenção e da preservação (append-only).
|
| Por que não `audit_events`: o catálogo `AuditEventType` é um enum fora da área deste item e
| a coluna é lida com cast de enum em várias telas; um valor novo fora do enum quebraria a
| leitura. Os eventos daqui sobrevivem à exclusão do envelope (`envelope_id` nullOnDelete +
| `subject_ulid`), o que a trilha de `audit_events` não faz. Contrato para espelhar em
| `audit_events` quando o enum ganhar os casos: docs/fase-2/retencao-e-preservacao.md §9.
|
| Payload minimizado: ULIDs, contagens, categoria, motivo digitado do bloqueio (limitado).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legal_hold_id')->nullable()->constrained()->nullOnDelete();
            $table->char('subject_ulid', 26)->nullable();
            $table->string('event_type', 48);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'occurred_at'], 'retention_events_org_occurred_idx');
            $table->index(['event_type', 'occurred_at'], 'retention_events_type_occurred_idx');
            $table->index(['envelope_id'], 'retention_events_envelope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_events');
    }
};
