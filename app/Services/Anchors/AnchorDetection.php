<?php

namespace App\Services\Anchors;

/**
 * Resultado do `pdftool find-anchors` (ou de um motor de OCR), já conferido.
 */
final readonly class AnchorDetection
{
    /**
     * @param  array<int, array{index: int, has_text: bool, text_chars: int, ocr: string, ocr_error: string|null}>  $pages  por número de página
     * @param  list<int>  $pagesWithoutText
     * @param  list<AnchorMatch>  $matches
     * @param  array<string, mixed>|null  $ocr
     */
    public function __construct(
        public int $pageCount,
        public array $pages,
        public array $pagesWithoutText,
        public array $matches,
        public bool $truncated,
        public ?array $ocr = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $pageCount = is_int($data['page_count'] ?? null) ? max(0, (int) $data['page_count']) : 0;

        $pages = [];

        foreach (is_array($data['pages'] ?? null) ? $data['pages'] : [] as $raw) {
            if (! is_array($raw) || ! is_int($raw['index'] ?? null)) {
                continue;
            }

            $index = (int) $raw['index'];

            if ($index < 1 || ($pageCount > 0 && $index > $pageCount)) {
                continue;
            }

            $pages[$index] = [
                'index' => $index,
                'has_text' => (bool) ($raw['has_text'] ?? false),
                'text_chars' => is_int($raw['text_chars'] ?? null) ? (int) $raw['text_chars'] : 0,
                'ocr' => is_string($raw['ocr'] ?? null) ? (string) $raw['ocr'] : 'not_requested',
                'ocr_error' => is_string($raw['ocr_error'] ?? null) && preg_match('/^[a-z_]{1,32}$/', (string) $raw['ocr_error']) === 1
                    ? (string) $raw['ocr_error']
                    : null,
            ];
        }

        $withoutText = [];

        foreach (is_array($data['pages_without_text'] ?? null) ? $data['pages_without_text'] : [] as $page) {
            if (is_int($page) && $page >= 1 && ($pageCount === 0 || $page <= $pageCount)) {
                $withoutText[] = $page;
            }
        }

        $matches = [];

        foreach (is_array($data['matches'] ?? null) ? $data['matches'] : [] as $raw) {
            if (is_array($raw) && ($match = AnchorMatch::tryFromArray($raw, $pageCount)) !== null) {
                $matches[] = $match;
            }
        }

        return new self(
            pageCount: $pageCount,
            pages: $pages,
            pagesWithoutText: array_values(array_unique($withoutText)),
            matches: $matches,
            truncated: (bool) ($data['truncated'] ?? false),
            ocr: is_array($data['ocr'] ?? null) ? $data['ocr'] : null,
        );
    }

    /**
     * Páginas em que o OCR terminou (`done`).
     *
     * @return list<int>
     */
    public function ocrDonePages(): array
    {
        return array_values(array_map(
            static fn (array $page): int => $page['index'],
            array_filter($this->pages, static fn (array $page): bool => $page['ocr'] === 'done'),
        ));
    }

    /**
     * @return list<int>
     */
    public function ocrFailedPages(): array
    {
        return array_values(array_map(
            static fn (array $page): int => $page['index'],
            array_filter($this->pages, static fn (array $page): bool => $page['ocr'] === 'failed'),
        ));
    }
}
