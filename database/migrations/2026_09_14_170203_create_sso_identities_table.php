<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-SSO) — vínculo entre o sujeito do provedor de identidade e o usuário do
 * painel (docs/fase-3/sso.md §6). Só aditiva; compatível com MySQL 8 e SQLite.
 *
 * O sujeito (`sub` do OIDC, NameID persistente do SAML) é guardado só como SHA-256 com o id da
 * conexão: o índice fica curto e o valor bruto não é necessário. Um usuário tem no máximo um
 * sujeito por conexão — e-mail reaproveitado no IdP com outro sujeito é recusado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sso_connection_id')->constrained('sso_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('subject_hash', 64);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['sso_connection_id', 'subject_hash']);
            $table->unique(['sso_connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};
