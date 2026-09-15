<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Sessão de assinatura embutida (widget, docs/fase-3/widget-embutido.md). `url` (uso único, com o token no fragmento) só vem na criação.
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class EmbeddedSigningSession implements \JsonSerializable
{
    /**
     * @param  string|null  $url  Endereço de uso único para o iframe; só na criação.
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $object,
        public readonly ?string $envelopeId,
        public readonly ?string $recipientId,
        public readonly string $origin,
        public readonly string $status,
        public readonly ?string $expiresAt,
        public readonly ?string $usedAt,
        public readonly ?string $completedAt,
        public readonly ?string $revokedAt,
        public readonly ?string $createdAt,
        public readonly ?string $url,
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
            envelopeId: $data['envelope_id'] ?? null,
            recipientId: $data['recipient_id'] ?? null,
            origin: $data['origin'] ?? null,
            status: $data['status'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            usedAt: $data['used_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            revokedAt: $data['revoked_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            url: $data['url'] ?? null,
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
