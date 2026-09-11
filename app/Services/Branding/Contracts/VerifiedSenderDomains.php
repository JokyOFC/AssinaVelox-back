<?php

namespace App\Services\Branding\Contracts;

use App\Models\Organization;

/**
 * Consulta de domínio de remetente verificado (contrato com o C-CAN — `sender_domains`).
 *
 * A marca só usa um remetente próprio (`From`) quando o domínio do endereço estiver
 * **verificado no provedor de e-mail do proprietário**. Essa verificação depende da API
 * desse provedor, que não tem documentação disponível (docs/fases-2-3-viabilidade.md,
 * regra fixa 1; roadmap T4). Até existir uma implementação real ligada no container, vale
 * {@see NoVerifiedSenderDomains}: nenhum domínio é verificado e os e-mails saem do
 * remetente padrão da plataforma, com `Reply-To` da organização.
 *
 * Quem implementar (C-CAN) liga no container:
 * `$this->app->bind(VerifiedSenderDomains::class, SenderDomainVerification::class)`.
 */
interface VerifiedSenderDomains
{
    /**
     * O domínio (minúsculas, sem `@`) está verificado para ESTA organização?
     * Inconclusivo (timeout, erro do provedor) deve responder `false`, nunca `true`.
     */
    public function isVerified(Organization $organization, string $domain): bool;
}
