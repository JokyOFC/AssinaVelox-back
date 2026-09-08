<?php

namespace App\Integrations\Dto;

use App\Enums\DocumentSourceType;

/**
 * Pedido de conversão para PDF. Caminhos absolutos no disco local (o
 * orquestrador baixa do disco `documents` para um diretório temporário antes).
 */
final readonly class ConversionRequest
{
    /**
     * @param  array<string, mixed>  $options  ex.: ['page' => 'A4'] para imagens
     */
    public function __construct(
        public string $inputPath,
        public string $outputPath,
        public DocumentSourceType|string $sourceType,
        public ?string $mimeType = null,
        public ?string $originalFilename = null,
        public ?string $correlationId = null,
        public array $options = [],
    ) {}

    public function sourceTypeValue(): string
    {
        return $this->sourceType instanceof DocumentSourceType
            ? $this->sourceType->value
            : strtolower(trim($this->sourceType));
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
