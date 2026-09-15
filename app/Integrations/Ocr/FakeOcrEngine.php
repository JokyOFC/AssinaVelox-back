<?php

namespace App\Integrations\Ocr;

use App\Services\Anchors\AnchorDetection;
use App\Services\Anchors\AnchorDetectionException;
use App\Services\Anchors\AnchorQuery;

/**
 * OCR SIMULADO — só para testes e desenvolvimento local, e identificado como tal: `name()` é
 * `fake`, `isFake()` é true, a busca grava `anchor_scans.ocr_engine = fake` e a interface diz
 * "OCR simulado (teste)". {@see OcrEngines::make()} nunca o entrega fora de `local`/`testing`.
 *
 * Devolve as ocorrências configuradas para as páginas pedidas, no formato do pdftool.
 */
final class FakeOcrEngine implements OcrEngine
{
    /** @var list<array{pdf: string, pages: list<int>, markers: bool, literals: int}> */
    public array $calls = [];

    /**
     * @param  array<int, list<array<string, mixed>>>  $matchesByPage  ocorrências (formato do pdftool, sem `page`/`source`) por página
     */
    public function __construct(
        private readonly array $matchesByPage = [],
        private readonly bool $available = true,
        private readonly ?string $failWith = null,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function availability(): OcrAvailability
    {
        return $this->available ? OcrAvailability::available() : OcrAvailability::unavailable(OcrAvailability::NOT_CONFIGURED);
    }

    public function detect(string $pdfPath, AnchorQuery $query, array $pages): AnchorDetection
    {
        $this->calls[] = ['pdf' => $pdfPath, 'pages' => $pages, 'markers' => $query->markers, 'literals' => count($query->literals)];

        if (! $this->available) {
            throw AnchorDetectionException::make('ocr_unavailable');
        }

        if ($this->failWith !== null) {
            throw AnchorDetectionException::make($this->failWith);
        }

        $pageCount = $pages === [] ? 0 : max($pages);
        $matches = [];
        $pageRows = [];

        foreach ($pages as $page) {
            $pageRows[] = ['index' => $page, 'has_text' => false, 'text_chars' => 0, 'ocr' => 'done'];

            foreach ($this->matchesByPage[$page] ?? [] as $match) {
                $matches[] = ['page' => $page, 'source' => 'ocr', 'lines' => 1, 'confidence' => 88.0, ...$match];
            }
        }

        return AnchorDetection::fromArray([
            'page_count' => $pageCount,
            'pages' => $pageRows,
            'pages_without_text' => $pages,
            'matches' => $matches,
            'truncated' => false,
            'ocr' => ['engine' => 'fake', 'pages_done' => count($pages), 'pages_failed' => 0, 'pages_skipped' => 0],
        ]);
    }
}
