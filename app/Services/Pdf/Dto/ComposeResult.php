<?php

namespace App\Services\Pdf\Dto;

/**
 * Saída de `pdftool compose`: {"ok":true,"page_count":N,"fields_drawn":M,"skipped":[...]}.
 */
final readonly class ComposeResult
{
    /**
     * @param  list<string>  $skipped  ids dos campos pulados (valor vazio / checkbox false)
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $pageCount,
        public int $fieldsDrawn,
        public array $skipped,
        public string $outputPath,
        public ?string $correlationId = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $outputPath, ?string $correlationId = null): self
    {
        return new self(
            pageCount: (int) ($data['page_count'] ?? 0),
            fieldsDrawn: (int) ($data['fields_drawn'] ?? 0),
            skipped: array_values(array_map('strval', (array) ($data['skipped'] ?? []))),
            outputPath: $outputPath,
            correlationId: $correlationId,
            raw: $data,
        );
    }
}
