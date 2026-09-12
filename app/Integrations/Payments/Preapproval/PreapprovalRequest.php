<?php

namespace App\Integrations\Payments\Preapproval;

/**
 * Pedido de assinatura recorrente sem plano associado (status `pending`: o comprador escolhe o
 * meio no checkout do link). Valor em centavos inteiros; moeda explícita.
 */
final readonly class PreapprovalRequest
{
    /**
     * @param  'days'|'months'  $frequencyType
     */
    public function __construct(
        public string $externalReference,
        public string $reason,
        public int $amountCents,
        public string $payerEmail,
        public string $backUrl,
        public string $currency = 'BRL',
        public int $frequency = 1,
        public string $frequencyType = 'months',
    ) {}
}
