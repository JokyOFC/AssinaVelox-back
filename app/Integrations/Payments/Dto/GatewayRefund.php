<?php

namespace App\Integrations\Payments\Dto;

use DateTimeImmutable;

/**
 * Estorno devolvido por POST/GET /v1/payments/{id}/refunds (docs/integracoes/mercado-pago.md §7).
 * `status` espelha o provedor: approved | in_process | rejected | cancelled | authorized.
 * Valor em centavos inteiros.
 */
final readonly class GatewayRefund
{
    public function __construct(
        public string $refundId,
        public string $providerPaymentId,
        public int $amountCents,
        public string $status,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
