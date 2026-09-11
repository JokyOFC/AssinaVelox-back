<?php

namespace App\Integrations\Email;

use App\Models\Organization;
use App\Models\SenderDomain;
use App\Services\Branding\Contracts\VerifiedSenderDomains;
use App\Services\Signing\Channels\ChannelFeatures;

/**
 * Implementação do contrato do C-BRAND ({@see VerifiedSenderDomains}): o remetente próprio só
 * é usado quando o domínio está VERIFICADO de verdade para esta organização.
 *
 * Responde `true` só com as três condições:
 *  1. flag `sender_domains` ligada para a organização;
 *  2. `sender_domains.status = verified` para (organização, domínio);
 *  3. `is_simulated = false` — uma verificação do simulador nunca vira remetente.
 *
 * Qualquer outra situação (pendente, falhou, simulado, flag desligada, domínio inválido)
 * é `false`: o e-mail sai pelo remetente padrão, com Reply-To da organização
 * (App\Services\Branding\ParticipantMailSender).
 */
final class SenderDomainVerification implements VerifiedSenderDomains
{
    public function isVerified(Organization $organization, string $domain): bool
    {
        if (! ChannelFeatures::senderDomains($organization)) {
            return false;
        }

        $normalized = SenderDomainRegistry::normalizeDomain($domain);

        if ($normalized === null) {
            return false;
        }

        return SenderDomain::forOrganization($organization)
            ->where('domain', $normalized)
            ->where('status', SenderDomain::STATUS_VERIFIED)
            ->where('is_simulated', false)
            ->exists();
    }
}
