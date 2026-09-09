<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca do último "Resumo diário de pendências" enviado a esta membership.
 *
 * O resumo é prometido na tela de Notificações com horário ("enviado às 08:00
 * (America/Sao_Paulo)"), e o agendador pode rodar mais de uma vez no mesmo dia — cron
 * atrasado, `withoutOverlapping` liberando, reprocessamento manual. A data do dia LOCAL da
 * organização, gravada aqui, é o que torna o envio idempotente sem depender de fila nem de
 * cache.
 *
 * Fica fora de `notification_preferences`: aquela coluna é reescrita inteira quando o
 * usuário salva a tela, e a marca seria perdida a cada mudança de preferência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->date('daily_digest_sent_on')->nullable()->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn('daily_digest_sent_on');
        });
    }
};
