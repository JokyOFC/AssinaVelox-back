<?php

namespace App\Integrations\Pdf;

use App\Enums\DocumentSourceType;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\UnsupportedSourceTypeException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Escolhe o PdfConverter pelo tipo de origem via config('pdftool.converters')
 * (mapa fixo em config, resolvido pelo container) e expõe convert().
 */
class PdfConverterManager implements PdfConverter
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, class-string<PdfConverter>>
     */
    public function map(): array
    {
        $map = [];
        foreach ((array) $this->config->get('pdftool.converters', []) as $type => $class) {
            if (is_string($type) && is_string($class) && $class !== '') {
                /** @var class-string<PdfConverter> $class */
                $map[strtolower($type)] = $class;
            }
        }

        return $map;
    }

    public function supports(DocumentSourceType|string $sourceType): bool
    {
        return isset($this->map()[ImageToPdfConverter::normalizeType($sourceType)]);
    }

    /**
     * Todos os conversores mapeados estão configurados.
     */
    public function isConfigured(): bool
    {
        foreach ($this->map() as $type => $class) {
            if (! $this->converterFor($type)->isConfigured()) {
                return false;
            }
        }

        return true;
    }

    public function converterFor(DocumentSourceType|string $sourceType): PdfConverter
    {
        $type = ImageToPdfConverter::normalizeType($sourceType);
        $class = $this->map()[$type] ?? null;

        if ($class === null) {
            throw UnsupportedSourceTypeException::for($type, self::class);
        }

        $converter = $this->container->make($class);
        if (! $converter instanceof PdfConverter) {
            throw new IntegrationException(sprintf('%s não implementa %s.', $class, PdfConverter::class));
        }

        return $converter;
    }

    public function convert(ConversionRequest $request): ConversionResult
    {
        $converter = $this->converterFor($request->sourceType);

        $this->logger->debug('PdfConverterManager: conversor selecionado', [
            'source_type' => $request->sourceTypeValue(),
            'converter' => $converter::class,
            'correlation_id' => $request->correlationId,
        ]);

        return $converter->convert($request);
    }

    /**
     * Estado por tipo, para diagnóstico (pdftool:selftest).
     *
     * @return array<string, array{converter: string, configured: bool}>
     */
    public function status(): array
    {
        $status = [];
        foreach ($this->map() as $type => $class) {
            $status[$type] = [
                'converter' => $class,
                'configured' => $this->converterFor($type)->isConfigured(),
            ];
        }

        return $status;
    }
}
