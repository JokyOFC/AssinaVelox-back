<?php

namespace App\Integrations\Payments;

use App\Enums\PaymentEnvironment;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Dto\CheckoutPreference;
use App\Integrations\Payments\Dto\GatewayMerchantOrder;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;

/**
 * O que o Checkout Pro precisa além do contrato genérico `PaymentGateway`
 * (App\Integrations\Contracts) — que ficou intencionalmente mínimo no incremento 1 e
 * não é área deste trabalho.
 *
 * `findPreferenceByExternalReference()` existe por causa da **ambiguidade de timeout**:
 * quando `createCheckoutPreference()` não conclui, não sabemos se o provedor criou a
 * preferência. Antes de criar outra, consultamos por `external_reference` — nunca
 * recriamos às cegas.
 */
interface CheckoutProGateway extends PaymentGateway
{
    /**
     * Preferência já existente para o nosso `external_reference`, ou null.
     *
     * @throws PaymentGatewayException
     */
    public function findPreferenceByExternalReference(string $externalReference): ?CheckoutPreference;

    /**
     * `GET /merchant_orders/{id}` — agrega os pagamentos de uma mesma preferência.
     *
     * @throws PaymentGatewayException
     */
    public function getMerchantOrder(string $merchantOrderId): GatewayMerchantOrder;

    /**
     * Ambiente do adaptador. Sandbox e produção nunca se misturam: o valor é gravado em
     * `payments.environment` e conferido contra o `live_mode` que a API devolve.
     */
    public function environment(): PaymentEnvironment;

    /**
     * Este adaptador é um dublê (nenhuma chamada real de rede)?
     */
    public function isFake(): bool;
}
