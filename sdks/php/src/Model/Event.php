<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Event (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Event implements \JsonSerializable
{
    /**
     * @param  string  $label  Rótulo em PT-BR do tipo; a lista cresce com a trilha — não é enumeração fechada.
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly EventActor $actor,
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly string $object,
        public readonly string $occurredAt,
        public readonly ?string $recipientId,
        public readonly string $type,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            actor: isset($data['actor']) && is_array($data['actor']) ? EventActor::fromArray($data['actor']) : null,
            id: $data['id'] ?? null,
            kind: $data['kind'] ?? null,
            label: $data['label'] ?? null,
            object: $data['object'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
            recipientId: $data['recipient_id'] ?? null,
            type: $data['type'] ?? null,
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
