<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.6 (C-PRES) — sessão presencial em tablet (docs/fase-2/presencial-e-lote.md §2).
|
| Um membro da organização (o ANFITRIÃO, com permissão de enviar o envelope) abre a sessão
| para um envelope já enviado; o dispositivo passa a mostrar a fila de participantes. A
| sessão NÃO autentica ninguém: cada participante confirma o próprio código (e o PIN, se
| houver) e registra o próprio aceite. O anfitrião é quem atestou a presença, nunca o autor.
|
| `device_secret_digest`: SHA-256 do segredo que vive só na sessão Laravel do dispositivo
| (nunca em URL, log ou trilha). `current_turn_id` aponta para a vez aberta em
| `in_person_turns` (sem FK para não criar dependência circular entre as duas tabelas).
| Expira por inatividade (`last_activity_at` + `in_person.idle_minutes`) e por teto
| absoluto (`expires_at`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_person_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_label', 80);
            $table->char('device_secret_digest', 64)->nullable()->unique();
            $table->string('status', 16)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 24)->nullable();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('current_turn_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'in_person_sessions_org_status_idx');
            $table->index(['envelope_id', 'status'], 'in_person_sessions_envelope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_person_sessions');
    }
};
