<?php

namespace App\Integrations\Payments\Preapproval;

use App\Services\Billing\BillingSettings;
use Illuminate\Contracts\Foundation\Application;

/**
 * Escolhe o adaptador de assinaturas recorrentes. Em produção é SEMPRE o adaptador desabilitado,
 * qualquer que seja `MERCADOPAGO_PREAPPROVAL_DRIVER`; fora dela, `simulated` liga o simulador
 * identificado e qualquer outro valor mantém o desabilitado.
 */
final class PreapprovalGatewayFactory
{
    public function __construct(
        private readonly Application $app,
        private readonly BillingSettings $settings,
    ) {}

    public function make(): PreapprovalGateway
    {
        $production = $this->app->environment('production');

        if ($production || $this->settings->preapprovalDriver() !== 'simulated') {
            return new MercadoPagoPreapprovalGateway;
        }

        return new SimulatedPreapprovalGateway(production: false);
    }
}
