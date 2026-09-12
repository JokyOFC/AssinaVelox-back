<?php

namespace App\Integrations\Payments\Preapproval;

/**
 * Estado de uma assinatura recorrente. `status` documentado: pending | authorized | paused |
 * cancelled. `simulated = true` em tudo que vem do simulador — nunca uma assinatura real.
 */
final readonly class PreapprovalResult
{
    public function __construct(
        public string $preapprovalId,
        public string $status,
        public string $externalReference,
        public ?string $initPoint,
        public bool $simulated,
    ) {}
}
