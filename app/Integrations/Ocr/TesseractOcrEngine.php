<?php

namespace App\Integrations\Ocr;

use App\Services\Anchors\AnchorDetection;
use App\Services\Anchors\AnchorDetectionException;
use App\Services\Anchors\AnchorFinder;
use App\Services\Anchors\AnchorQuery;
use App\Services\Anchors\OcrRequest;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ProcessEnvironment;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * OCR com o binário `tesseract` (Fase 3 §3.2, classe B).
 *
 * O pdftool rasteriza as páginas sem texto com pypdfium2 e chama o `tesseract` por
 * `subprocess` (argumentos em lista, sem shell, timeout por página, ambiente mínimo, sem
 * rede; o Tesseract não abre conexões). Aqui só se decide SE dá para usar e com quais
 * parâmetros.
 *
 * Disponibilidade — verificação real, nunca presumida:
 *  1. `assinavelox.ocr.tesseract_path` configurado;
 *  2. o arquivo existe;
 *  3. `tesseract --list-langs` roda dentro do tempo e lista o idioma configurado (`por`).
 * O resultado fica em cache por `ocr.probe_cache_minutes` (a chave inclui o caminho e a data
 * de modificação do binário: trocar o binário invalida o cache).
 */
class TesseractOcrEngine implements OcrEngine
{
    public function __construct(
        private readonly AnchorFinder $finder,
        private readonly PdfToolClient $pdftool,
        private readonly Repository $config,
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'tesseract';
    }

    public function isFake(): bool
    {
        return false;
    }

    public function availability(): OcrAvailability
    {
        $path = trim((string) $this->config->get('assinavelox.ocr.tesseract_path', ''));

        if ($path === '') {
            return OcrAvailability::unavailable(OcrAvailability::NOT_CONFIGURED);
        }

        if (! is_file($path)) {
            return OcrAvailability::unavailable(OcrAvailability::BINARY_MISSING);
        }

        $language = $this->language();
        $key = 'anchors:ocr:tesseract-probe:'.sha1($path.'|'.(string) @filemtime($path).'|'.$language);
        $minutes = max(1, (int) $this->config->get('assinavelox.ocr.probe_cache_minutes', 10));

        $reason = $this->cache->remember($key, now()->addMinutes($minutes), fn (): string => $this->probe($path, $language));

        return $reason === 'ok' ? OcrAvailability::available() : OcrAvailability::unavailable((string) $reason);
    }

    public function detect(string $pdfPath, AnchorQuery $query, array $pages): AnchorDetection
    {
        if (! $this->availability()->available) {
            throw AnchorDetectionException::make('ocr_unavailable');
        }

        $prefix = trim((string) $this->config->get('assinavelox.ocr.tessdata_prefix', ''));

        return $this->finder->find($pdfPath, $query, new OcrRequest(
            tesseractPath: trim((string) $this->config->get('assinavelox.ocr.tesseract_path')),
            language: $this->language(),
            dpi: max(72, min(400, (int) $this->config->get('assinavelox.ocr.dpi', 200))),
            pageTimeoutSeconds: max(1, min(600, (int) $this->config->get('assinavelox.ocr.page_timeout_seconds', 60))),
            maxPages: max(0, (int) $this->config->get('assinavelox.ocr.max_pages', 20)),
            pages: array_values(array_filter($pages, static fn (int $page): bool => $page >= 1)),
            tessdataPrefix: $prefix === '' ? null : $prefix,
        ));
    }

    private function language(): string
    {
        $language = strtolower(trim((string) $this->config->get('assinavelox.ocr.language', 'por')));

        return preg_match('/^[a-z_]{3,20}(\+[a-z_]{3,20}){0,3}$/', $language) === 1 ? $language : 'por';
    }

    /**
     * `tesseract --list-langs`: "ok" ou o motivo da indisponibilidade.
     */
    private function probe(string $path, string $language): string
    {
        $workDir = $this->pdftool->temporaryDirectory('ocr-probe-');

        try {
            $prefix = trim((string) $this->config->get('assinavelox.ocr.tessdata_prefix', ''));
            $process = new Process(
                [$path, '--list-langs'],
                $workDir->path(),
                ProcessEnvironment::minimal($workDir->path(), $prefix === '' ? [] : ['TESSDATA_PREFIX' => $prefix]),
                null,
                max(1, (int) $this->config->get('assinavelox.ocr.probe_timeout_seconds', 10)),
            );
            $process->run();

            if ($process->getExitCode() !== 0) {
                return OcrAvailability::PROBE_FAILED;
            }

            $installed = array_map(
                static fn (string $line): string => strtolower(trim($line)),
                preg_split('/\r?\n/', $process->getOutput().PHP_EOL.$process->getErrorOutput()) ?: [],
            );

            foreach (explode('+', $language) as $needed) {
                if (! in_array($needed, $installed, true)) {
                    return OcrAvailability::LANGUAGE_MISSING;
                }
            }

            return 'ok';
        } catch (Throwable $exception) {
            $this->logger->warning('ocr: verificação do tesseract falhou', ['error' => $exception::class]);

            return OcrAvailability::PROBE_FAILED;
        } finally {
            $workDir->delete();
        }
    }
}
