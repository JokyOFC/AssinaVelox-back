<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 §2.16 (D-HOOK) — endpoints de webhook de saída por organização.
 * docs/fase-2/webhooks.md §2. Só aditiva; compatível com MySQL 8 e SQLite.
 *
 * - `secret` e `previous_secret` guardam o segredo CIFRADO (cast `encrypted`, APP_KEY). O
 *   valor em claro só aparece uma vez, na criação/rotação.
 * - `previous_secret_expires_at`: fim da janela de convivência da rotação (dois segredos
 *   válidos até lá).
 * - `created_by_user_id`: o responsável. O endpoint só recebe eventos de envelopes que essa
 *   pessoa pode ver, e é pausado se ela perder `manage_integrations`.
 * - `api_token_id`: quem criou por REST Hook (§2.17). Sem FK de propósito: a tabela de
 *   tokens é de outra área e pode ser criada em outra ordem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 2048);
            $table->string('description', 160)->nullable();
            $table->json('events');
            $table->text('secret');
            $table->string('secret_hint', 16);
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_reason', 40)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('source', 16)->default('web');
            $table->unsignedBigInteger('api_token_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
