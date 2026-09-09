<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingSettings;
use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Console\Command;

/**
 * Inadimplência das assinaturas (RECONCILIACAO §4 Q20).
 *
 * O Checkout Pro não faz cobrança recorrente: cada ciclo é um pagamento avulso. Quando o
 * ciclo vence sem pagamento aprovado, este comando aplica, em ordem:
 *
 * - `active` → `past_due` depois de `ASSINAVELOX_BILLING_GRACE_DAYS` (3 por padrão) do
 *   fim do período. Isso **bloqueia novos envios** e não mexe em mais nada: leitura,
 *   download, verificação pública e envelopes em andamento continuam;
 * - `past_due` → `expired` depois de `ASSINAVELOX_BILLING_EXPIRED_DAYS` (15), com a
 *   organização voltando ao plano Grátis num ciclo novo;
 * - renovação do ciclo das assinaturas **sem cobrança recorrente** (plano Grátis): o
 *   período avança e o consumo do ciclo é zerado, que é o que faz a cota anunciada como
 *   mensal ser de fato mensal.
 *
 * Idempotente: rodar duas vezes no mesmo dia não muda nada duas vezes. Toda a regra está
 * em `App\Services\Billing\SubscriptionLifecycle`; este comando é só a casca agendável.
 */
class BillingDunningCommand extends Command
{
    protected $signature = 'billing:dunning {--limit= : máximo de assinaturas por etapa nesta execução}';

    protected $description = 'Aplica a inadimplência das assinaturas: past_due após a carência e expired depois, voltando ao plano Grátis.';

    public function handle(SubscriptionLifecycle $lifecycle, BillingSettings $settings): int
    {
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        $result = $lifecycle->runDunning($limit);

        $this->info(sprintf(
            'Inadimplência aplicada: %d assinatura(s) em atraso (carência de %d dia(s)), %d expirada(s) (após %d dia(s)) e %d ciclo(s) gratuito(s) renovado(s).',
            $result['past_due'],
            $settings->graceDays(),
            $result['expired'],
            $settings->expiredDays(),
            $result['renewed'],
        ));

        return self::SUCCESS;
    }
}
