<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\Organization;
use App\Models\Referral;
use App\Services\Risk\RiskSignals;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ponte do programa de afiliados para o antifraude (Fase 3 §3.7, onda H).
 *
 * Contrato: `App\Services\Risk\RiskSignals::record(string $ruleCode, Organization $organization,
 * array $evidence, ?Envelope $envelope = null, ?string $subjectKey = null)`. A regra usada é
 * `affiliate_self_referral` (catálogo fechado do antifraude, `RiskRule`); a possível conta
 * duplicada entra na mesma regra com `match` = `duplicate_ip`. A evidência só leva as chaves que
 * o antifraude aceita para essa regra — ULIDs, códigos de regra e booleanos, nunca e-mail ou IP.
 *
 * `same_email_domain` NÃO é enviada: embora conste do catálogo da regra, o minimizador do
 * antifraude (`RiskEvidence::FORBIDDEN_KEY`, padrão `e-?mail`) descarta toda chave com "email"
 * no nome. A coincidência de e-mail/domínio chega pelo `match` (`same_email`, `same_domain`).
 *
 * Esta ponte só REGISTRA o sinal. Quem decide qualquer ação sobre a organização é o antifraude
 * (máximo: restringir ENVIO até revisão humana). O programa de afiliados, por si, só barra ou
 * segura a COMISSÃO. Nunca lança: a indicação é decidida mesmo se o antifraude falhar.
 */
final class AffiliateRiskSignals
{
    public const RULE = 'affiliate_self_referral';

    /**
     * @param  list<string>  $reasons  códigos de Referral::REASON_*
     */
    public function report(Organization $organization, Affiliate $affiliate, Referral $referral, array $reasons): void
    {
        $evidence = [
            'affiliate' => $affiliate->ulid,
            'referral' => $referral->ulid,
            'match' => implode(',', $reasons),
            'same_user' => in_array(Referral::REASON_SAME_USER, $reasons, true),
            'same_ip' => in_array(Referral::REASON_SAME_IP, $reasons, true) || in_array(Referral::REASON_DUPLICATE_IP, $reasons, true),
        ];

        // `duplicate_ip` sozinho não diz nada sobre a organização indicada — o dado é o IP de
        // OUTRA organização do mesmo afiliado (ex.: clientes cadastrados no escritório do
        // contador). O efeito é segurar a COMISSÃO; o sinal fica registrado sem pontuar a
        // organização (revisão adversarial I-3A). As regras de autoindicação pontuam.
        $scoresOrganization = array_diff($reasons, [Referral::REASON_DUPLICATE_IP]) !== [];

        try {
            RiskSignals::record(self::RULE, $organization, $evidence, null, 'affiliate:'.$affiliate->ulid, $scoresOrganization ? null : 0);
        } catch (Throwable $exception) {
            Log::error('affiliates.risk_signal_failed', [
                'rule' => self::RULE,
                'organization_id' => $organization->getKey(),
                'referral' => $referral->ulid,
                'exception' => $exception::class,
            ]);
        }
    }
}
