<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\CheckoutPreference;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Dto\GatewayPayment;
use App\Integrations\Exceptions\IntegrationException;

/**
 * Gateway de pagamento (Mercado Pago Checkout Pro). Apenas o contrato na
 * Fase 1 — implementações MercadoPagoGateway e FakePaymentGateway ficam para o
 * incremento 5 (docs/integracoes/mercado-pago.md).
 *
 * Regras: confirmação de pagamento só por webhook autenticado seguido de
 * getPayment() na API; o payload do webhook nunca é fonte da verdade.
 */
interface PaymentGateway
{
    /**
     * @throws IntegrationException
     */
    public function createCheckoutPreference(CheckoutPreferenceRequest $request): CheckoutPreference;

    /**
     * @throws IntegrationException
     */
    public function getPayment(string $providerPaymentId): GatewayPayment;

    public function isConfigured(): bool;

    /**
     * Nome curto gravado em payments.provider (ex.: mercadopago).
     */
    public function name(): string;
}
