<?php

namespace App\Services\Branding\Contracts;

use App\Models\Organization;

/**
 * Padrão enquanto não houver verificação real de domínio: nenhum domínio é verificado.
 * Não é um fake que finge sucesso — é a resposta honesta "não verificado".
 */
final class NoVerifiedSenderDomains implements VerifiedSenderDomains
{
    public function isVerified(Organization $organization, string $domain): bool
    {
        return false;
    }
}
