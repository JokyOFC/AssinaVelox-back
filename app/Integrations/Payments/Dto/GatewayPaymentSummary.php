<?php

namespace App\Integrations\Payments\Dto;

use DateTimeImmutable;

/**
 * Um item de GET /v1/payments/search (conciliação). Só os campos comparados: status, valor e
 * moeda, mais as chaves de casamento. Nada do pagador, nada de cartão.
 */
final readonly class GatewayPaymentSummary
{
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public ?string $statusDetail,
        public ?string $externalReference,
        public int $amountCents,
        public string $currency,
        public bool $liveMode,
        public ?string $paymentTypeId = null,
        public ?DateTimeImmutable $lastUpdatedAt = null,
    ) {}
}
