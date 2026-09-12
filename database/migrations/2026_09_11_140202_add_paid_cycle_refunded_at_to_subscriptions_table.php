<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY. Marca que o ciclo pago corrente foi estornado por inteiro (política
 * conservadora, docs/fase-2/pagamentos-e-fiscal.md §4): a assinatura não renova e volta ao Grátis
 * ao fim do período, sem passar por `past_due` (não há dívida). Um novo pagamento aprovado limpa
 * a marca. Nula em todas as linhas existentes: com a flag desligada nada muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('paid_cycle_refunded_at')->nullable()->after('cancel_at_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('paid_cycle_refunded_at');
        });
    }
};
