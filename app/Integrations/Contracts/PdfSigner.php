<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\SignRequest;
use App\Integrations\Exceptions\SignerNotConfiguredException;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\Exceptions\PdfToolException;

/**
 * Assinatura criptográfica da EMPRESA OPERADORA (certificado A1, PAdES B-B) e
 * validação técnica de assinaturas.
 *
 * Vocabulário obrigatório (docs/arquitetura.md §2): esta assinatura identifica
 * a empresa titular do certificado; não é assinatura pessoal ICP-Brasil de cada
 * participante. Sem certificado configurado (isConfigured() = false) o envelope
 * conclui como "aceite eletrônico com evidências" e sign() lança
 * SignerNotConfiguredException — nunca se simula uma assinatura.
 */
interface PdfSigner
{
    /**
     * @throws SignerNotConfiguredException
     * @throws PdfToolException
     */
    public function sign(SignRequest $request): SignResult;

    /**
     * Valida todas as assinaturas do PDF. `trusted` só é verdadeiro com cadeia
     * até uma raiz em $trustRoots (ou nas raízes configuradas, quando vazio).
     *
     * @param  list<string>  $trustRoots  arquivos PEM/DER
     */
    public function validate(string $pdfPath, array $trustRoots = []): ValidationResult;

    public function isConfigured(): bool;
}
