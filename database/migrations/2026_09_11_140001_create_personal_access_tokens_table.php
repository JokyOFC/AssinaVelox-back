<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.15 (D-API, docs/fase-2/api-v1.md) — tokens da API v1.
 *
 * É a tabela padrão do Sanctum (`personal_access_tokens`: o token é guardado SÓ como hash
 * SHA-256 em `token`) acrescida do que o roadmap §2.15 reservou: a organização dona do token,
 * quem o criou, um prefixo de exibição, o último IP de uso e a revogação.
 *
 * Aditiva: o projeto nunca publicou a migration do Sanctum. Se uma instalação já tiver a
 * tabela (ex.: `install:api` rodado à mão), só as colunas novas são acrescentadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name', 120);
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->char('ulid', 26)->nullable()->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            // O token nunca sobrevive a quem o criou (ele age com as permissões dessa pessoa).
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('token_prefix', 16)->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
