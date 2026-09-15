<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Field (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Field implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly bool $auto,
        public readonly ?string $documentId,
        public readonly float $h,
        public readonly string $id,
        public readonly ?string $label,
        public readonly string $object,
        public readonly int $page,
        public readonly ?string $recipientId,
        public readonly bool $required,
        public readonly string $type,
        public readonly float $w,
        public readonly float $x,
        public readonly float $y,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            auto: $data['auto'] ?? null,
            documentId: $data['document_id'] ?? null,
            h: $data['h'] ?? null,
            id: $data['id'] ?? null,
            label: $data['label'] ?? null,
            object: $data['object'] ?? null,
            page: $data['page'] ?? null,
            recipientId: $data['recipient_id'] ?? null,
            required: $data['required'] ?? null,
            type: $data['type'] ?? null,
            w: $data['w'] ?? null,
            x: $data['x'] ?? null,
            y: $data['y'] ?? null,
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
