<?php

namespace App\Services\Anchors;

/**
 * Parâmetros do OCR para o `pdftool find-anchors --ocr`. O caminho do binário não é segredo
 * (vai em argv); nada aqui é segredo.
 */
final readonly class OcrRequest
{
    /**
     * @param  list<int>  $pages  páginas (1-based) a ler; vazio = todas as sem texto
     */
    public function __construct(
        public string $tesseractPath,
        public string $language,
        public int $dpi,
        public int $pageTimeoutSeconds,
        public int $maxPages,
        public array $pages = [],
        public ?string $tessdataPrefix = null,
    ) {}

    /** Orçamento total do pdftool (texto + OCR), dentro do teto de 600 s da ferramenta. */
    public function timeBudgetSeconds(int $textBudget): int
    {
        $pages = max(1, min($this->maxPages, $this->pages === [] ? $this->maxPages : count($this->pages)));

        return max(1, min(600, $textBudget + $pages * $this->pageTimeoutSeconds));
    }
}
