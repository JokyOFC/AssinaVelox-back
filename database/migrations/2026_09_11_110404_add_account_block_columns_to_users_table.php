<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Painel interno › Usuários da plataforma (Fase 2, roadmap §2.14): bloqueio de conta com
 * motivo e autor, e o carimbo de último acesso exibido na lista.
 *
 * Aditiva: colunas nulas, nenhum dado existente muda. Conta sem `blocked_at` = conta ativa
 * (comportamento da Fase 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('terms_version');
            $table->string('blocked_reason', 500)->nullable()->after('blocked_at');
            $table->foreignId('blocked_by_user_id')->nullable()->after('blocked_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable()->after('blocked_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blocked_by_user_id');
            $table->dropColumn(['blocked_at', 'blocked_reason', 'last_seen_at']);
        });
    }
};
