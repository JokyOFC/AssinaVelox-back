<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-SSO) — domínios de e-mail da organização para o login corporativo
 * (docs/fase-3/sso.md §4). Só aditiva; compatível com MySQL 8 e SQLite.
 *
 * Um domínio só vale depois de verificado por registro TXT com o token. `verified_domain`
 * repete o domínio SÓ quando verificado (nulo antes): o UNIQUE garante que um domínio
 * verificado pertença a UMA única organização, e várias podem ter a mesma reivindicação
 * pendente sem colidir (nulos não colidem em MySQL nem em SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_domains', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('domain', 253);
            $table->string('verified_domain', 253)->nullable()->unique();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'domain']);
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_domains');
    }
};
