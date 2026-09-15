<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * TemplateVariable (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class TemplateVariable implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly ?string $defaultValue,
        public readonly ?string $helpText,
        public readonly string $key,
        public readonly string $label,
        public readonly mixed $options,
        public readonly bool $required,
        public readonly string $type,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            defaultValue: $data['default_value'] ?? null,
            helpText: $data['help_text'] ?? null,
            key: $data['key'] ?? null,
            label: $data['label'] ?? null,
            options: $data['options'] ?? null,
            required: $data['required'] ?? null,
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
