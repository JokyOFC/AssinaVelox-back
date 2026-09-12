<?php

namespace App\Integrations\Fiscal;

use App\Integrations\Exceptions\IntegrationException;

/**
 * Emissão de NFS-e indisponível (roadmap §2.21, classe B). A mensagem lista o que falta; nunca
 * carrega dado do tomador nem segredo.
 */
final class FiscalProviderUnavailable extends IntegrationException
{
    public static function sefinDisabled(): self
    {
        return new self('NFS-e pelo Sistema Nacional (Sefin Nacional/ADN) está desabilitada: '.implode(' ', SefinNacionalFiscalInvoiceProvider::MISSING));
    }
}
