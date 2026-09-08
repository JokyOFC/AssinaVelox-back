<?php

namespace App\Integrations\Dto;

use DateTimeImmutable;

/**
 * Recibo de um envio (e-mail, SMS, WhatsApp). `providerMessageId` permite
 * correlacionar webhooks de entrega/bounce; `error` é legível e sem segredos.
 */
final readonly class DeliveryReceipt
{
    /**
     * @param  array<string, mixed>  $meta  dados extras do provedor (sem segredos)
     */
    public function __construct(
        public DeliveryReceiptStatus $status,
        public string $provider,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public ?string $correlationId = null,
        public ?DateTimeImmutable $sentAt = null,
        public array $meta = [],
    ) {}

    public static function queued(string $provider, ?string $providerMessageId = null, ?string $correlationId = null): self
    {
        return new self(DeliveryReceiptStatus::Queued, $provider, $providerMessageId, null, $correlationId);
    }

    public static function sent(string $provider, ?string $providerMessageId = null, ?string $correlationId = null, ?DateTimeImmutable $sentAt = null): self
    {
        return new self(DeliveryReceiptStatus::Sent, $provider, $providerMessageId, null, $correlationId, $sentAt ?? new DateTimeImmutable);
    }

    public static function failed(string $provider, string $error, ?string $correlationId = null, ?string $providerMessageId = null): self
    {
        return new self(DeliveryReceiptStatus::Failed, $provider, $providerMessageId, $error, $correlationId);
    }

    public static function unknown(string $provider, ?string $error = null, ?string $correlationId = null): self
    {
        return new self(DeliveryReceiptStatus::Unknown, $provider, null, $error, $correlationId);
    }

    public function isFailure(): bool
    {
        return $this->status->isFailure();
    }
}
