<?php

namespace App\Integrations\Dto;

use App\Services\Pdf\Dto\VisibleStamp;

/**
 * Pedido de assinatura criptográfica da empresa operadora (PAdES B-B) sobre um
 * PDF já consolidado (campos achatados + página de evidências). Nunca carrega
 * o certificado nem a passphrase: isso é configuração do PdfSigner.
 */
final readonly class SignRequest
{
    public function __construct(
        public string $inputPath,
        public string $outputPath,
        public ?string $fieldName = null,
        public ?string $reason = null,
        public ?string $location = null,
        public ?string $contact = null,
        public ?VisibleStamp $visible = null,
        public ?string $correlationId = null,
    ) {}
}
