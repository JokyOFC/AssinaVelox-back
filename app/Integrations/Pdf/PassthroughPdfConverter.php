<?php

namespace App\Integrations\Pdf;

use App\Enums\DocumentSourceType;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;
use App\Integrations\Exceptions\ConverterNotConfiguredException;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\UnsupportedSourceTypeException;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * PDF de origem: não converte, apenas inspeciona (`pdftool inspect`).
 *
 * blocked quando o PDF está criptografado (mesmo abrindo com senha de usuário
 * vazia), já tem assinaturas digitais ou é inválido — o original é preservado
 * e o documento não pode ser preparado. ready copia o arquivo para outputPath
 * (quando diferente da entrada) e devolve o inspect.
 */
class PassthroughPdfConverter implements PdfConverter
{
    public const NAME = 'pdf_passthrough';

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(DocumentSourceType|string $sourceType): bool
    {
        return ImageToPdfConverter::normalizeType($sourceType) === DocumentSourceType::Pdf->value;
    }

    public function isConfigured(): bool
    {
        return $this->client->isAvailable();
    }

    public function convert(ConversionRequest $request): ConversionResult
    {
        if (! $this->supports($request->sourceType)) {
            throw UnsupportedSourceTypeException::for($request->sourceTypeValue(), self::NAME);
        }
        if (! $this->isConfigured()) {
            throw ConverterNotConfiguredException::for(self::NAME, 'pdftool indisponível (venv em tools/pdftool/.venv).');
        }

        $correlationId = $request->correlationId ?? (string) Str::ulid();

        try {
            $inspection = $this->client->inspect($request->inputPath, $correlationId);
        } catch (PdfToolInputRejectedException $exception) {
            $this->logger->info('PassthroughPdfConverter: PDF rejeitado pelo inspect', [
                'reason_code' => $exception->errorCode,
                'correlation_id' => $correlationId,
            ]);

            if ($exception->errorCode === 'missing_input') {
                return ConversionResult::failed(self::NAME, $exception->errorCode, ConversionMessages::for($exception->errorCode), $correlationId);
            }

            return ConversionResult::blocked(self::NAME, $exception->errorCode, ConversionMessages::for($exception->errorCode), null, $correlationId);
        }

        if ($inspection->encrypted) {
            return ConversionResult::blocked(self::NAME, 'encrypted_pdf', ConversionMessages::for('encrypted_pdf'), $inspection, $correlationId);
        }

        if ($inspection->hasSignatures) {
            return ConversionResult::blocked(
                self::NAME,
                'has_signatures',
                ConversionMessages::for('has_signatures').sprintf(' (%d encontrada(s).)', $inspection->signatureCount),
                $inspection,
                $correlationId,
            );
        }

        if (! $inspection->openable || $inspection->pageCount < 1) {
            return ConversionResult::blocked(self::NAME, 'not_openable', ConversionMessages::for('not_openable'), $inspection, $correlationId);
        }

        if ($this->samePath($request->inputPath, $request->outputPath) === false && ! @copy($request->inputPath, $request->outputPath)) {
            throw new IntegrationException('Não foi possível copiar o PDF para o destino da conversão.');
        }

        return ConversionResult::ready(self::NAME, $request->outputPath, $inspection, $correlationId);
    }

    private function samePath(string $a, string $b): bool
    {
        $ra = realpath($a);
        $rb = realpath($b);

        if ($ra !== false && $rb !== false) {
            return $ra === $rb;
        }

        return str_replace('\\', '/', $a) === str_replace('\\', '/', $b);
    }
}
