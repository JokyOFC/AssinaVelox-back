<?php

namespace App\Services\Affiliates\Console;

use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\CommissionLedger;
use Illuminate\Console\Command;

/**
 * `affiliates:settle` — varredura idempotente dos pagamentos das organizações indicadas e
 * aprovação das comissões cujo prazo de estorno passou. Não paga nada.
 */
final class SettleCommissionsCommand extends Command
{
    protected $signature = 'affiliates:settle';

    protected $description = 'Recalcula comissões de afiliados (idempotente) e aprova as pendentes com prazo de estorno vencido.';

    public function handle(CommissionLedger $ledger): int
    {
        if (! AffiliatesFeature::enabled()) {
            $this->components->info('Programa de afiliados desligado (features.affiliates): nada a fazer.');

            return self::SUCCESS;
        }

        $swept = $ledger->sweep();
        $approved = $ledger->approveDue();

        $this->components->info("Pagamentos conferidos: {$swept}. Comissões aprovadas: {$approved}.");

        return self::SUCCESS;
    }
}
