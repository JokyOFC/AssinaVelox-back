<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-SSO) — pedidos SAML (AuthnRequest) emitidos e ainda não respondidos
 * (docs/fase-3/sso.md §7). Só aditiva; compatível com MySQL 8 e SQLite.
 *
 * A resposta do IdP chega por POST entre sites: o cookie de sessão (SameSite=Lax) não vem. O
 * InResponseTo é conferido contra esta tabela (ID guardado só como hash) e ligado ao navegador
 * que iniciou por `browser_binding_hash` (hash do cookie próprio). Uso único: `consumed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_saml_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sso_connection_id')->constrained('sso_connections')->cascadeOnDelete();
            $table->char('request_id_hash', 64)->unique();
            $table->char('browser_binding_hash', 64);
            // login | test
            $table->string('mode', 8)->default('login');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_saml_requests');
    }
};
