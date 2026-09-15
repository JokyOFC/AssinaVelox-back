<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.3 (F-FLOW) — delegação auditada (docs/fase-3/etapas-e-delegacao.md §3).
 *
 * Um pedido por linha. `status`: pending (aguarda a confirmação do remetente) | effective (o
 * delegado foi criado e convidado) | rejected (o remetente recusou; o original continua) |
 * void (o pedido perdeu o objeto: o original assinou, recusou ou a coleta terminou antes da
 * decisão). O delegado é um NOVO `recipients` (to_recipient_id) — o aceite dele é dele.
 * `ip_address` é TRUNCADO (/24 no IPv4, /48 no IPv6); o nome e o e-mail propostos ficam aqui
 * até a delegação valer, porque o participante só nasce depois da confirmação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_recipient_id')->constrained('recipients')->cascadeOnDelete();
            $table->foreignId('to_recipient_id')->nullable()->constrained('recipients')->nullOnDelete();
            $table->string('to_name', 160);
            $table->string('to_email');
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('chain_depth')->default(1);
            $table->timestamp('requested_at');
            $table->timestamp('delegated_at')->nullable();
            $table->timestamp('approved_by_sender_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'status']);
            $table->index(['organization_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
