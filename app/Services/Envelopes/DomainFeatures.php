<?php

namespace App\Services\Envelopes;

use App\Models\Organization;
use App\Models\Plan;

/**
 * Flags de ativação da Fase 2, onda A — domínio do envelope (roadmap §1, T8).
 *
 * Cada flag só vale quando as DUAS fontes dizem sim:
 *
 * 1. configuração global da instalação (`config('assinavelox.features.{flag}')`, padrão
 *    `false`) — o interruptor da operadora;
 * 2. `plans.features.{flag}` do plano vigente da organização — o que o cliente comprou.
 *
 * A flag liga a INTERFACE e as regras de preparo (aceitar um segundo arquivo, aceitar um
 * papel diferente de `signer`). Ela não substitui a autorização: quem pode editar um envelope
 * continua sendo decidido pelas Policies.
 *
 * O que NÃO depende da flag: um envelope já enviado com N documentos ou papéis mistos é
 * conduzido até o fim (aceite, finalização, verificação) mesmo que a flag seja desligada
 * depois. Desligar a flag impede criar coisas novas; nunca abandona um envelope no meio.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área deste agente): as chaves
 * `multi_document` e `participant_roles` devem vir de {@see self::multiDocument()} e
 * {@see self::participantRoles()} para a organização corrente.
 */
final class DomainFeatures
{
    public const MULTI_DOCUMENT = 'multi_document';

    public const PARTICIPANT_ROLES = 'participant_roles';

    /** Teto padrão de documentos por envelope com a flag ligada. */
    public const DEFAULT_MAX_DOCUMENTS = 10;

    public static function multiDocument(?Organization $organization): bool
    {
        return self::enabled(self::MULTI_DOCUMENT, $organization);
    }

    public static function participantRoles(?Organization $organization): bool
    {
        return self::enabled(self::PARTICIPANT_ROLES, $organization);
    }

    /**
     * Quantos documentos um envelope aceita para esta organização: 1 com a flag desligada
     * (regra da Fase 1), `assinavelox.multi_document.max_documents` com ela ligada.
     */
    public static function maxDocuments(?Organization $organization): int
    {
        if (! self::multiDocument($organization)) {
            return 1;
        }

        return max(1, (int) config('assinavelox.multi_document.max_documents', self::DEFAULT_MAX_DOCUMENTS));
    }

    /**
     * @return array{multi_document: bool, participant_roles: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        return [
            self::MULTI_DOCUMENT => self::multiDocument($organization),
            self::PARTICIPANT_ROLES => self::participantRoles($organization),
        ];
    }

    public static function enabled(string $flag, ?Organization $organization): bool
    {
        if ((bool) config('assinavelox.features.'.$flag, false) !== true) {
            return false;
        }

        if ($organization === null) {
            return false;
        }

        /** @var Plan|null $plan */
        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        return ($plan?->features[$flag] ?? false) === true;
    }
}
