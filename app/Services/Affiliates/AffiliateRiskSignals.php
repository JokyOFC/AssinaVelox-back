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
 * o antifraude aceita para essa regra — ULIDs e booleanos, nunca e-mail ou IP.
 *
 * Esta ponte só REGISTRA o sinal. Quem decide qualquer ação sobre a organização é o antifraude
 * (máximo: restringir ENVIO até revisão humana). O programa de afiliados, por si, só barra ou
 * segura a COMISSÃO. Nunca lança: a indicação é decidida mesmo se o antifraude falhar.
 *
 * Não é `final` de propósito: os testes trocam a ponte por um dublê do contrato.
 */
class AffiliateRiskSignals
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
            'same_email_domain' => in_array(Referral::REASON_SAME_DOMAIN, $reasons, true) || in_array(Referral::REASON_SAME_EMAIL, $reasons, true),
        ];

        try {
            RiskSignals::record(self::RULE, $organization, $evidence, null, 'affiliate:'.$affiliate->ulid);
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
