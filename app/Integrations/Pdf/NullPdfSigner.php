<?php

namespace App\Integrations\Pdf;

use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
use App\Integrations\Exceptions\SignerNotConfiguredException;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Sem certificado configurado. isConfigured() = false; sign() sempre lança
 * SignerNotConfiguredException e NUNCA produz um arquivo "assinado" — o envelope
 * conclui como aceite eletrônico com evidências (signature_status=none).
 * validate() continua disponível (não exige certificado).
 */
class NullPdfSigner implements PdfSigner
{
    public const NAME = 'null';

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return false;
    }

    public function sign(SignRequest $request): SignResult
    {
        $this->logger->info('NullPdfSigner: assinatura criptográfica não disponível; envelope segue como aceite eletrônico com evidências.', [
            'correlation_id' => $request->correlationId,
        ]);

        throw SignerNotConfiguredException::make(
            'Defina COMPANY_CERT_PFX_PATH e a variável de passphrase (COMPANY_CERT_PASSPHRASE_ENV) para habilitar a assinatura A1.',
        );
    }

    public function validate(string $pdfPath, array $trustRoots = []): ValidationResult
    {
        if ($trustRoots === []) {
            foreach ((array) $this->config->get('pdftool.trust_roots', []) as $root) {
                if (is_string($root) && trim($root) !== '') {
                    $trustRoots[] = trim($root);
                }
            }
        }

        return $this->client->validate($pdfPath, $trustRoots);
    }
}
