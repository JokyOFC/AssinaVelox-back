<?php

namespace App\Services\Billing;

use App\Integrations\Payments\Preapproval\PreapprovalGatewayFactory;
use App\Integrations\Payments\Preapproval\PreapprovalUnavailable;

/**
 * Situação das assinaturas recorrentes (preapproval, classe B) para o painel interno e a
 * documentação: habilitado ou não, se é o simulador e a lista do que falta decidir e testar.
 */
final class RecurringBillingStatus
{
    public function __construct(private readonly PreapprovalGatewayFactory $factory) {}

    /**
     * @return array{enabled: bool, simulated: bool, provider: string, message: string|null, pending: list<string>}
     */
    public function summary(): array
    {
        $gateway = $this->factory->make();

        return [
            'enabled' => $gateway->isEnabled(),
            'simulated' => $gateway->isSimulated(),
            'provider' => $gateway->name(),
            'message' => $gateway->unavailableReason(),
            'pending' => PreapprovalUnavailable::PENDING,
        ];
    }
}
