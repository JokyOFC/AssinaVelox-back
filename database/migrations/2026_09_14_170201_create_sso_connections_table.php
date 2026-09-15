<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-SSO) — conexão de login corporativo da organização (docs/fase-3/sso.md §3).
 * Só aditiva; compatível com MySQL 8 e SQLite.
 *
 * - UMA conexão por organização (UNIQUE organization_id): o domínio do e-mail leva à
 *   organização e dela à conexão, sem ambiguidade. Trocar de protocolo = remover e criar outra.
 * - `oidc_client_secret` guarda o segredo CIFRADO (cast `encrypted`, APP_KEY); nunca sai em
 *   prop, log, evento, fila ou exceção.
 * - `saml_idp_certificates`: certificados PÚBLICOS de assinatura do IdP (JSON, PEM). O SP não
 *   tem chave privada: AuthnRequest sai sem assinatura e assertion cifrada não é aceita.
 * - Os domínios (domain_hints do roadmap) ficam em `sso_domains`, verificados por TXT.
 * - `enforce`: SSO obrigatório para membros; owners mantêm o "break-glass" (senha + 2FA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('protocol', 8);
            $table->string('name', 120);
            // draft | active | disabled
            $table->string('status', 16)->default('draft');

            $table->string('oidc_issuer', 500)->nullable();
            $table->string('oidc_client_id', 255)->nullable();
            $table->text('oidc_client_secret')->nullable();
            $table->string('oidc_id_token_alg', 8)->nullable();

            $table->string('saml_idp_entity_id', 500)->nullable();
            $table->string('saml_idp_sso_url', 1000)->nullable();
            $table->text('saml_idp_certificates')->nullable();
            $table->string('saml_metadata_url', 1000)->nullable();
            $table->boolean('saml_allow_idp_initiated')->default(false);

            $table->boolean('jit_provisioning')->default(false);
            $table->string('jit_role', 16)->default('member');
            $table->boolean('enforce')->default(false);
            // keep = o 2FA do usuário continua valendo | trust_idp = a organização confia no MFA do IdP
            $table->string('two_factor_policy', 16)->default('keep');

            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
    }
};
