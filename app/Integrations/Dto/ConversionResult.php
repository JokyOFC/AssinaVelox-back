<?php

namespace App\Integrations\Dto;

use App\Services\Pdf\Dto\PdfInspection;

/**
 * Resultado de PdfConverter::convert(). `reasonCode` é estável (snake_case:
 * encrypted_pdf, has_signatures, invalid_pdf, unsupported_image,
 * image_too_large, libreoffice_failed, no_output...) e `reasonMessage` é
 * legível em PT-BR para documents.failure_message.
 */
final readonly class ConversionResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ConversionStatus $status,
        public string $converter,
        public ?string $outputPath = null,
        public ?PdfInspection $inspection = null,
        public ?string $reasonCode = null,
        public ?string $reasonMessage = null,
        public ?string $correlationId = null,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function ready(
        string $converter,
        string $outputPath,
        PdfInspection $inspection,
        ?string $correlationId = null,
        array $details = [],
    ): self {
        return new self(ConversionStatus::Ready, $converter, $outputPath, $inspection, null, null, $correlationId, $details);
    }

    public static function blocked(
        string $converter,
        string $reasonCode,
        string $reasonMessage,
        ?PdfInspection $inspection = null,
        ?string $correlationId = null,
    ): self {
        return new self(ConversionStatus::Blocked, $converter, null, $inspection, $reasonCode, $reasonMessage, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function failed(
        string $converter,
        string $reasonCode,
        string $reasonMessage,
        ?string $correlationId = null,
        array $details = [],
    ): self {
        return new self(ConversionStatus::Failed, $converter, null, null, $reasonCode, $reasonMessage, $correlationId, $details);
    }

    public function isReady(): bool
    {
        return $this->status === ConversionStatus::Ready;
    }

    public function isBlocked(): bool
    {
        return $this->status === ConversionStatus::Blocked;
    }

    public function isFailed(): bool
    {
        return $this->status === ConversionStatus::Failed;
    }

    public function pageCount(): ?int
    {
        return $this->inspection?->pageCount;
    }
}
