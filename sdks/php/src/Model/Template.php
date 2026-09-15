<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Template (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Template implements \JsonSerializable
{
    /**
     * @param  list<TemplateRole>|null  $roles  Só no detalhe.
     * @param  list<TemplateVariable>|null  $variables  Só no detalhe.
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly ?string $category,
        public readonly ?string $description,
        public readonly string $id,
        public readonly string $name,
        public readonly string $object,
        public readonly ?array $roles,
        public readonly string $sourceType,
        public readonly string $status,
        public readonly string $updatedAt,
        public readonly bool $usable,
        public readonly ?array $variables,
        public readonly ?int $version,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            category: $data['category'] ?? null,
            description: $data['description'] ?? null,
            id: $data['id'] ?? null,
            name: $data['name'] ?? null,
            object: $data['object'] ?? null,
            roles: isset($data['roles']) && is_array($data['roles']) ? Hydrator::listOf(TemplateRole::class, $data['roles']) : null,
            sourceType: $data['source_type'] ?? null,
            status: $data['status'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            usable: $data['usable'] ?? null,
            variables: isset($data['variables']) && is_array($data['variables']) ? Hydrator::listOf(TemplateVariable::class, $data['variables']) : null,
            version: $data['version'] ?? null,
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
