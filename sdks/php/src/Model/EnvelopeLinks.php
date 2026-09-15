<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Só no detalhe.
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class EnvelopeLinks implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly string $events,
        public readonly ?string $evidenceFile,
        public readonly string $fields,
        public readonly string $originalFile,
        public readonly string $recipients,
        public readonly string $self,
        public readonly ?string $signedFile,
        public readonly ?string $verification,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            events: $data['events'] ?? null,
            evidenceFile: $data['evidence_file'] ?? null,
            fields: $data['fields'] ?? null,
            originalFile: $data['original_file'] ?? null,
            recipients: $data['recipients'] ?? null,
            self: $data['self'] ?? null,
            signedFile: $data['signed_file'] ?? null,
            verification: $data['verification'] ?? null,
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
