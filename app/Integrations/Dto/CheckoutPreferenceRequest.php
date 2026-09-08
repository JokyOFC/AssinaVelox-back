<?php

namespace App\Integrations\Dto;

use DateTimeImmutable;

/**
 * Criação de uma preferência de checkout (Mercado Pago Checkout Pro).
 * `externalReference` é o nosso identificador (payments.external_reference,
 * ULID) e é a única chave usada para reconciliar o retorno do gateway.
 * Valores em centavos inteiros.
 */
final readonly class CheckoutPreferenceRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $externalReference,
        public string $title,
        public int $amountCents,
        public string $successUrl,
        public string $failureUrl,
        public string $pendingUrl,
        public ?string $notificationUrl = null,
        public string $currency = 'BRL',
        public int $quantity = 1,
        public ?string $description = null,
        public ?string $payerEmail = null,
        public ?string $statementDescriptor = null,
        public ?DateTimeImmutable $expiresAt = null,
        public array $metadata = [],
        public ?string $correlationId = null,
    ) {}
}
