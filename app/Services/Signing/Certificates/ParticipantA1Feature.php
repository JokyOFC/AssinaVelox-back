<?php

namespace App\Services\Signing\Certificates;

use App\Enums\AcceptanceAction;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Recipient;

/**
 * Flag `participant_a1` (Fase 2 §2.12, roadmap T8). Nasce DESLIGADA.
 *
 * Duas fontes, as duas precisam dizer sim:
 *
 * 1. `assinavelox.participant_a1.enabled` — interruptor global da instalação;
 * 2. `plans.features.participant_a1` do plano vigente da organização.
 *
 * A flag liga as rotas novas do signatário (`sign.certificate.*`). Um envelope que já tem
 * pedidos de assinatura com certificado é conduzido até o fim mesmo que a flag seja
 * desligada depois: desligar impede pedidos novos, nunca abandona um envelope no meio
 * (mesma regra de `DomainFeatures`). Os pedidos pendentes vencem pelo prazo normal.
 */
final class ParticipantA1Feature
{
    public const FLAG = 'participant_a1';

    public static function globallyEnabled(): bool
    {
        return filter_var(config('assinavelox.participant_a1.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function enabledFor(?Organization $organization): bool
    {
        if (! self::globallyEnabled() || $organization === null) {
            return false;
        }

        /** @var Plan|null $plan */
        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        return ($plan?->features[self::FLAG] ?? false) === true;
    }

    /**
     * Raízes de confiança para validar as assinaturas de participantes (e a da operadora no
     * mesmo arquivo): `pdftool.trust_roots` + `assinavelox.participant_a1.trust_roots`. Sem
     * raiz, a validação afirma integridade e diz "cadeia não verificada".
     *
     * @return list<string>
     */
    public static function trustRoots(): array
    {
        $roots = [];

        foreach ([(array) config('pdftool.trust_roots', []), (array) config('assinavelox.participant_a1.trust_roots', [])] as $list) {
            foreach ($list as $root) {
                if (is_string($root) && trim($root) !== '') {
                    $roots[] = trim($root);
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Só quem registra aceite de signatário ou de testemunha assina com certificado.
     * Aprovador e visualizador não têm assinatura (Fase 2 §2.4).
     */
    public static function allowsRecipient(Recipient $recipient): bool
    {
        return in_array($recipient->role->acceptanceAction(), [AcceptanceAction::Sign, AcceptanceAction::Witness], true);
    }
}
