<?php

namespace App\Services\Pdf\Dto;

/**
 * Resultado de `pdftool inspect` (e de `image2pdf`, que devolve o mesmo objeto
 * para o PDF gerado). `raw` preserva o JSON completo (ex.: source/normalized
 * do image2pdf, permissions de PDFs cifrados).
 */
final readonly class PdfInspection
{
    /**
     * @param  list<PdfPage>  $pages
     * @param  list<string>  $signatureFields
     * @param  array<string, string|null>  $metadata
     * @param  list<string>|null  $permissions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $pageCount,
        public bool $encrypted,
        public bool $openable,
        public bool $hasSignatures,
        public int $signatureCount,
        public array $signatureFields,
        public bool $hasAcroform,
        public bool $hasXfa,
        public ?string $pdfVersion,
        public array $metadata,
        public array $pages,
        public ?array $permissions = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $pages = [];
        foreach ((array) ($data['pages'] ?? []) as $page) {
            if (is_array($page)) {
                $pages[] = PdfPage::fromArray($page);
            }
        }

        $metadata = [];
        foreach ((array) ($data['metadata'] ?? []) as $key => $value) {
            $metadata[(string) $key] = $value === null ? null : (string) $value;
        }

        return new self(
            pageCount: (int) ($data['page_count'] ?? count($pages)),
            encrypted: (bool) ($data['encrypted'] ?? false),
            openable: (bool) ($data['openable'] ?? true),
            hasSignatures: (bool) ($data['has_signatures'] ?? false),
            signatureCount: (int) ($data['signature_count'] ?? 0),
            signatureFields: array_values(array_map('strval', (array) ($data['signature_fields'] ?? []))),
            hasAcroform: (bool) ($data['has_acroform'] ?? false),
            hasXfa: (bool) ($data['has_xfa'] ?? false),
            pdfVersion: isset($data['pdf_version']) ? (string) $data['pdf_version'] : null,
            metadata: $metadata,
            pages: $pages,
            permissions: isset($data['permissions']) && is_array($data['permissions'])
                ? array_values(array_map('strval', $data['permissions']))
                : null,
            raw: $data,
        );
    }

    /**
     * Página 1-based.
     */
    public function page(int $index): ?PdfPage
    {
        foreach ($this->pages as $page) {
            if ($page->index === $index) {
                return $page;
            }
        }

        return null;
    }

    /**
     * PDF que não pode ser preparado para assinatura: protegido por senha ou já
     * assinado digitalmente (qualquer alteração invalidaria as assinaturas).
     */
    public function isBlockedForPreparation(): bool
    {
        return $this->encrypted || $this->hasSignatures || ! $this->openable;
    }

    /**
     * Metadados por página no formato de document_versions.pages_meta.
     *
     * @return list<array<string, mixed>>
     */
    public function pagesMeta(): array
    {
        return array_map(fn (PdfPage $page): array => $page->toArray(), $this->pages);
    }
}
