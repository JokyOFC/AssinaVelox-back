<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-SSO) — `memberships.auth_via` (roadmap §3.9). Só aditiva; compatível com
 * MySQL 8 e SQLite. Nulo = acesso por senha/convite (o padrão, como na Fase 1); `sso` = a
 * membership foi criada pelo provisionamento JIT ou o último acesso foi pelo login corporativo.
 * `last_sso_login_at` registra esse último acesso. Nenhuma linha existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->string('auth_via', 16)->nullable();
            $table->timestamp('last_sso_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn(['auth_via', 'last_sso_login_at']);
        });
    }
};
