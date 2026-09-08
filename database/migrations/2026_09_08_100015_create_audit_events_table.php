<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela append-only: não possui updated_at e a aplicação nunca executa UPDATE/DELETE.
 * Em produção, o usuário MySQL da aplicação deve ter apenas SELECT/INSERT nesta tabela
 * (ver docs/banco-de-dados.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 32); // ActorType: user | recipient | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 64); // AuditEventType
            $table->json('payload')->nullable(); // minimizado; sem tokens/senhas/OTP
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['envelope_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
