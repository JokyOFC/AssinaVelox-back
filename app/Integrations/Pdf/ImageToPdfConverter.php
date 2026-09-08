<?php

namespace App\Integrations\Pdf;

use App\Enums\DocumentSourceType;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;
use App\Integrations\Exceptions\ConverterNotConfiguredException;
use App\Integrations\Exceptions\UnsupportedSourceTypeException;
use App\Services\Pdf\Exceptions\ImageRejectedException;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ImageNormalizer;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Imagem (PNG/JPEG/WEBP) → PDF de uma página.
 *
 * 1. Normalização em PHP com GD (ImageNormalizer): MIME real, recusa SVG e
 *    outros formatos, limites de 4000 px / 40 MP, orientação EXIF, reencode
 *    sem metadados. A imagem original nunca chega ao pdftool.
 * 2. `pdftool image2pdf` (que normaliza de novo com Pillow) gera o PDF e devolve
 *    o inspect do resultado.
 */
class ImageToPdfConverter implements PdfConverter
{
    public const NAME = 'image_to_pdf';

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly ImageNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(DocumentSourceType|string $sourceType): bool
    {
        return self::normalizeType($sourceType) === DocumentSourceType::Image->value;
    }

    public function isConfigured(): bool
    {
        return $this->client->isAvailable() && extension_loaded('gd');
    }

    public function convert(ConversionRequest $request): ConversionResult
    {
        if (! $this->supports($request->sourceType)) {
            throw UnsupportedSourceTypeException::for($request->sourceTypeValue(), self::NAME);
        }
        if (! $this->isConfigured()) {
            throw ConverterNotConfiguredException::for(self::NAME, 'pdftool indisponível (venv em tools/pdftool/.venv) ou extensão GD ausente.');
        }

        $correlationId = $request->correlationId ?? (string) Str::ulid();
        $workDir = $this->client->temporaryDirectory('img-');

        try {
            try {
                $normalized = $this->normalizer->normalize($request->inputPath, $workDir->path());
            } catch (ImageRejectedException $exception) {
                $this->logger->info('ImageToPdfConverter: imagem recusada na normalização', [
                    'reason_code' => $exception->errorCode,
                    'correlation_id' => $correlationId,
                ]);

                return ConversionResult::failed(self::NAME, $exception->errorCode, $exception->getMessage(), $correlationId);
            }

            $page = (string) $request->option('page', 'A4');

            try {
                $inspection = $this->client->imageToPdf($normalized['path'], $request->outputPath, $page, $correlationId);
            } catch (PdfToolInputRejectedException $exception) {
                return ConversionResult::failed(
                    self::NAME,
                    $exception->errorCode,
                    ConversionMessages::for($exception->errorCode),
                    $correlationId,
                );
            }

            return ConversionResult::ready(self::NAME, $request->outputPath, $inspection, $correlationId, [
                'source' => [
                    'mime' => $normalized['source_mime'],
                    'width_px' => $normalized['source_width'],
                    'height_px' => $normalized['source_height'],
                ],
                'normalized' => [
                    'format' => $normalized['format'],
                    'width_px' => $normalized['width'],
                    'height_px' => $normalized['height'],
                ],
                'page_size' => strtolower($page),
            ]);
        } finally {
            $workDir->delete();
        }
    }

    public static function normalizeType(DocumentSourceType|string $sourceType): string
    {
        return $sourceType instanceof DocumentSourceType ? $sourceType->value : strtolower(trim($sourceType));
    }
}
