<?php

namespace App\Integrations\Pdf;

use App\Enums\DocumentSourceType;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;
use App\Integrations\Exceptions\ConverterNotConfiguredException;
use App\Integrations\Exceptions\IntegrationException;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * FAKE — somente desenvolvimento e testes. Não converte nada: copia o PDF
 * fixture (pdftool.fake.fixture_path) para o destino e registra um WARNING em
 * cada uso. Aparece como "fake" no nome do conversor e em details.fake=true
 * para que nunca seja confundido com uma conversão real. Não use em produção.
 */
class FakePdfConverter implements PdfConverter
{
    public const NAME = 'fake';

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function fixturePath(): string
    {
        return (string) $this->config->get('pdftool.fake.fixture_path', '');
    }

    public function supports(DocumentSourceType|string $sourceType): bool
    {
        return DocumentSourceType::tryFrom(ImageToPdfConverter::normalizeType($sourceType)) !== null;
    }

    public function isConfigured(): bool
    {
        return is_file($this->fixturePath()) && $this->client->isAvailable();
    }

    public function convert(ConversionRequest $request): ConversionResult
    {
        if (! $this->isConfigured()) {
            throw ConverterNotConfiguredException::for(self::NAME, 'fixture PDF ausente (pdftool.fake.fixture_path) ou pdftool indisponível.');
        }

        $correlationId = $request->correlationId ?? (string) Str::ulid();

        $this->logger->warning('FakePdfConverter em uso: o PDF gerado é um FIXTURE, não uma conversão real do documento.', [
            'source_type' => $request->sourceTypeValue(),
            'original_filename' => $request->originalFilename,
            'fixture' => basename($this->fixturePath()),
            'correlation_id' => $correlationId,
        ]);

        if (! @copy($this->fixturePath(), $request->outputPath)) {
            throw new IntegrationException('FakePdfConverter: não foi possível copiar o fixture para o destino.');
        }

        $inspection = $this->client->inspect($request->outputPath, $correlationId);

        return ConversionResult::ready(self::NAME, $request->outputPath, $inspection, $correlationId, [
            'fake' => true,
            'fixture' => basename($this->fixturePath()),
        ]);
    }
}
