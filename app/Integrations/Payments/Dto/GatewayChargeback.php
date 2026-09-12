<?php

namespace App\Integrations\Payments\Dto;

use DateTimeImmutable;

/**
 * Contestação consultada em GET /v1/chargebacks/{id} (docs/integracoes/mercado-pago-fase-2.md §6).
 * `payments` pode trazer mais de um pagamento (compras de carrinho). Só guarda o que a decisão
 * exige: nada do titular do cartão.
 */
final readonly class GatewayChargeback
{
    /**
     * @param  list<string>  $providerPaymentIds
     */
    public function __construct(
        public string $chargebackId,
        public array $providerPaymentIds,
        public int $amountCents,
        public string $currency,
        public ?string $reason = null,
        public ?bool $coverageApplied = null,
        public ?string $documentationStatus = null,
        public ?DateTimeImmutable $documentationDeadline = null,
        public bool $liveMode = false,
    ) {}
}
