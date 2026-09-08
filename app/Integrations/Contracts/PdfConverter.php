<?php

namespace App\Integrations\Contracts;

use App\Enums\DocumentSourceType;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;

/**
 * Converte (ou apenas inspeciona) um documento de origem para o PDF que será
 * preparado para assinatura.
 *
 * Contrato de erro: problemas DO DOCUMENTO (protegido, assinado, corrompido,
 * imagem inválida) voltam como ConversionResult blocked|failed com razão.
 * Problemas DE INFRAESTRUTURA (conversor não configurado, processo que não
 * inicia, timeout) são exceções: ConverterNotConfiguredException,
 * UnsupportedSourceTypeException, PdfToolProcessingException.
 */
interface PdfConverter
{
    public function convert(ConversionRequest $request): ConversionResult;

    public function supports(DocumentSourceType|string $sourceType): bool;

    /**
     * Dependências externas presentes (binário, venv...). false => convert() lança
     * ConverterNotConfiguredException.
     */
    public function isConfigured(): bool;
}
