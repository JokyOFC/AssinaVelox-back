<?php

namespace App\Integrations\Ocr;

/**
 * Resolve o motor de OCR sem exigir provedor de serviço novo (bootstrap/providers.php é
 * compartilhado): uma instância ligada no contêiner (`app()->instance(OcrEngine::class, …)`,
 * usado pelos testes) vence; senão vale `assinavelox.ocr.driver`.
 *
 *  - `tesseract` (padrão) → {@see TesseractOcrEngine} (indisponível sem o binário);
 *  - `fake` → {@see FakeOcrEngine} SÓ em `local`/`testing`; em qualquer outro ambiente vira
 *    {@see NullOcrEngine}, para que um .env copiado nunca simule OCR em produção;
 *  - qualquer outro valor → {@see NullOcrEngine}.
 */
final class OcrEngines
{
    public static function make(): OcrEngine
    {
        if (app()->bound(OcrEngine::class)) {
            return app(OcrEngine::class);
        }

        return match ((string) config('assinavelox.ocr.driver', 'tesseract')) {
            'tesseract' => app(TesseractOcrEngine::class),
            'fake' => app()->environment(['local', 'testing']) ? new FakeOcrEngine : new NullOcrEngine,
            default => new NullOcrEngine,
        };
    }
}
