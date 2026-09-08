<?php

namespace App\Integrations\Dto;

use DateTimeImmutable;

/**
 * Pagamento consultado na API do gateway (fonte da verdade após um webhook).
 * `status` espelha fielmente o gateway (App\Enums\PaymentStatus); a aplicação
 * do plano só acontece com `approved` vindo desta consulta, nunca do payload
 * do webhook.
 */
final readonly class GatewayPayment
{
    /**
     * @param  array<string, mixed>  $raw  resposta sem dados sensíveis do pagador
     */
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public ?string $statusDetail,
        public ?string $externalReference,
        public int $amountCents,
        public string $currency,
        public bool $liveMode,
        public ?string $paymentMethodId = null,
        public ?string $payerEmailMasked = null,
        public ?DateTimeImmutable $approvedAt = null,
        public array $raw = [],
    ) {}

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
