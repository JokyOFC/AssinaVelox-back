<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha das ações da EQUIPE da plataforma (platform admins) — Fase 2, roadmap §2.14:
 * bloqueio de conta, "acessar como" etc. Separada de `audit_events` (eventos de negócio de
 * uma organização, `organization_id` obrigatório).
 *
 * APPEND-ONLY, como `audit_events`: sem updated_at; o model recusa update/delete e, em
 * produção, o usuário MySQL da aplicação deve ter só SELECT/INSERT nesta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_events', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 32)->nullable(); // user | organization
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable(); // minimizado; sem senha, token ou conteúdo de documento
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('occurred_at', 'platform_audit_occurred_index');
            $table->index(['action', 'occurred_at'], 'platform_audit_action_occurred_index');
            $table->index(['actor_user_id', 'occurred_at'], 'platform_audit_actor_occurred_index');
            $table->index(['organization_id', 'occurred_at'], 'platform_audit_org_occurred_index');
            $table->index(['target_type', 'target_id'], 'platform_audit_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
    }
};
