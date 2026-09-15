<?php

namespace App\Integrations\Ocr;

use App\Services\Anchors\AnchorDetection;
use App\Services\Anchors\AnchorDetectionException;
use App\Services\Anchors\AnchorQuery;

/**
 * OCR desligado (`ocr.driver = disabled`, ou `fake` fora de local/testing): sempre
 * indisponível, e a interface diz exatamente isso.
 */
final class NullOcrEngine implements OcrEngine
{
    public function name(): string
    {
        return 'disabled';
    }

    public function isFake(): bool
    {
        return false;
    }

    public function availability(): OcrAvailability
    {
        return OcrAvailability::unavailable(OcrAvailability::NOT_CONFIGURED);
    }

    public function detect(string $pdfPath, AnchorQuery $query, array $pages): AnchorDetection
    {
        throw AnchorDetectionException::make('ocr_unavailable');
    }
}
