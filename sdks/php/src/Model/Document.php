<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Document (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Document implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly string $createdAt,
        public readonly ?DocumentFailure $failure,
        public readonly string $id,
        public readonly string $name,
        public readonly string $object,
        public readonly string $originalFilename,
        public readonly ?int $pages,
        public readonly int $position,
        public readonly string $processingLabel,
        public readonly string $processingStatus,
        public readonly bool $ready,
        public readonly DocumentSha256 $sha256,
        public readonly ?int $sizeBytes,
        public readonly string $sourceType,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            createdAt: $data['created_at'] ?? null,
            failure: isset($data['failure']) && is_array($data['failure']) ? DocumentFailure::fromArray($data['failure']) : null,
            id: $data['id'] ?? null,
            name: $data['name'] ?? null,
            object: $data['object'] ?? null,
            originalFilename: $data['original_filename'] ?? null,
            pages: $data['pages'] ?? null,
            position: $data['position'] ?? null,
            processingLabel: $data['processing_label'] ?? null,
            processingStatus: $data['processing_status'] ?? null,
            ready: $data['ready'] ?? null,
            sha256: isset($data['sha256']) && is_array($data['sha256']) ? DocumentSha256::fromArray($data['sha256']) : null,
            sizeBytes: $data['size_bytes'] ?? null,
            sourceType: $data['source_type'] ?? null,
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
