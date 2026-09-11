<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Acessar como" (impersonation) — Fase 2, roadmap §2.14 / ROUTES Q15.
 *
 * Um registro por sessão de suporte: quem (platform admin), qual organização, qual usuário
 * alvo, motivo, início, expiração (30 min), fim e como terminou. A sessão é SOMENTE LEITURA
 * (App\Http\Middleware\EnforceImpersonationReadOnly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('admin_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable(); // stopped | expired | logout | invalid
            $table->unsignedInteger('pages_viewed')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'ended_at'], 'impersonations_admin_ended_index');
            $table->index(['organization_id', 'started_at'], 'impersonations_org_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
    }
};
