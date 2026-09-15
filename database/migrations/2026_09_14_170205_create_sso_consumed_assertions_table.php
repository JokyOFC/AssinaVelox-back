<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-SSO) — proteção contra replay de assertion SAML (docs/fase-3/sso.md §7). O
 * toolkit (onelogin/php-saml) não faz isso: a aplicação guarda o ID de cada assertion aceita
 * até ela expirar (NotOnOrAfter + margem). UNIQUE(conexão, hash) torna a checagem atômica.
 * Só aditiva; compatível com MySQL 8 e SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_consumed_assertions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sso_connection_id')->constrained('sso_connections')->cascadeOnDelete();
            $table->char('assertion_id_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['sso_connection_id', 'assertion_id_hash']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_consumed_assertions');
    }
};
