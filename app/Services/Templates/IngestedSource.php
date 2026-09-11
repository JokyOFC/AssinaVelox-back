<?php

namespace App\Services\Templates;

/**
 * Arquivo de origem de um modelo já inspecionado e gravado no disco (TemplateSourceIntake).
 */
final class IngestedSource
{
    /**
     * @param  list<array<string, mixed>>|null  $pagesMeta
     * @param  list<string>  $placeholders
     */
    public function __construct(
        public readonly TemplateSourceType $type,
        public readonly string $storagePath,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly ?int $pageCount,
        public readonly ?array $pagesMeta,
        public readonly array $placeholders,
    ) {}

    /**
     * @return array{storage_disk: string|null, storage_path: string|null, original_filename: string|null, mime_type: string|null, size_bytes: int|null, sha256: string|null, page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}
     */
    public function toSource(): array
    {
        return [
            'storage_disk' => TemplateStorage::DISK,
            'storage_path' => $this->storagePath,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'page_count' => $this->pageCount,
            'pages_meta' => $this->pagesMeta,
            'placeholders' => $this->placeholders,
        ];
    }
}
