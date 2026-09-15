<?php

namespace App\Integrations\Ocr;

use App\Services\Anchors\AnchorDetection;
use App\Services\Anchors\AnchorDetectionException;
use App\Services\Anchors\AnchorQuery;

/**
 * Contrato do OCR de páginas escaneadas (Fase 3 §3.2, classe B — docs/fase-3/ancoras-e-ocr.md §6).
 *
 * O motor lê as páginas pedidas e devolve as âncoras encontradas, no MESMO formato da busca
 * no texto do PDF (caixas normalizadas ao CropBox exibido). A saída do OCR é dado não
 * confiável (roadmap T6): só é comparada com os marcadores e textos procurados, e o que ela
 * produz são SUGESTÕES que exigem revisão explícita do remetente.
 *
 * Implementações: {@see TesseractOcrEngine} (binário por processo isolado),
 * {@see FakeOcrEngine} (identificado, só testes/local) e {@see NullOcrEngine} (desligado).
 */
interface OcrEngine
{
    /** `tesseract`, `fake` ou `disabled` — gravado em `anchor_scans.ocr_engine`. */
    public function name(): string;

    /** Motor de teste: a interface avisa "OCR simulado" e nunca vale como leitura real. */
    public function isFake(): bool;

    /** Verificação REAL de disponibilidade (binário presente e idioma instalado). */
    public function availability(): OcrAvailability;

    /**
     * @param  list<int>  $pages  páginas (1-based) a ler
     *
     * @throws AnchorDetectionException
     */
    public function detect(string $pdfPath, AnchorQuery $query, array $pages): AnchorDetection;
}
