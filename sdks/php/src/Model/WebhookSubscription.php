<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Assinatura de REST Hook (um endpoint de webhook ligado ao token que a criou). `secret` só vem na criação (201); nas listagens é null e, quando a assinatura já existia (200), não vem.
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class WebhookSubscription implements \JsonSerializable
{
    /**
     * @param  list<string>  $events
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $object,
        public readonly string $targetUrl,
        public readonly array $events,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly ?string $pausedReason,
        public readonly string $secretHint,
        public readonly string $signatureHeader,
        public readonly string $createdAt,
        public readonly ?string $secret,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            object: $data['object'] ?? null,
            targetUrl: $data['target_url'] ?? null,
            events: $data['events'] ?? [],
            status: $data['status'] ?? null,
            statusLabel: $data['status_label'] ?? null,
            pausedReason: $data['paused_reason'] ?? null,
            secretHint: $data['secret_hint'] ?? null,
            signatureHeader: $data['signature_header'] ?? null,
            createdAt: $data['created_at'] ?? null,
            secret: $data['secret'] ?? null,
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
