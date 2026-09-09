<?php

namespace App\Services\Plans;

use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Plan;

/**
 * O que cada plano habilita — hoje, a única entrada com efeito é a assinatura
 * criptográfica da operadora.
 *
 * ## O problema que esta classe resolve
 *
 * `plans.features.company_signature` nasce `false` no Grátis e `true` nos pagos, e
 * `PlanController` traduzia a chave para "Assinatura criptográfica da operadora" na
 * comparação de planos e na tela do plano vigente. Só que **nada** no código lia a flag:
 * quem decidia se o arquivo final recebia PAdES era só o certificado configurado
 * (`certificate_references` + o adaptador do pdftool). As duas pontas ficavam erradas ao
 * mesmo tempo — o cliente do plano pago comprava um item que, sem certificado (o padrão da
 * Fase 1), nunca recebia; e o cliente do Grátis, a quem o item era negado na comparação,
 * passaria a recebê-lo no instante em que a operadora configurasse o certificado.
 *
 * Agora a flag governa de verdade, e a tela só anuncia o item quando ele existe:
 *
 *  - {@see self::isOffered()} — existe certificado da operadora ativo? Sem isso o item não
 *    aparece em nenhuma tela de planos. Anunciar um recurso que a instalação não tem é
 *    exatamente o que arquitetura.md §2 proíbe para o vocabulário de assinatura;
 *  - {@see self::allows()} — o plano vigente da organização inclui o item? É o que a
 *    finalização consulta antes de assinar.
 *
 * Sem certificado, nada muda para ninguém: o envelope conclui como aceite eletrônico com
 * evidências, em qualquer plano, e a interface diz isso.
 */
class PlanFeatures
{
    public const COMPANY_SIGNATURE = 'company_signature';

    /**
     * Há certificado da operadora ativo nesta instalação?
     *
     * É o que decide se a assinatura criptográfica pode ser ANUNCIADA. A execução em si
     * depende do adaptador (`OperatorSignature::isConfigured()`), que abre o PKCS#12 — mas
     * uma tela de planos não vai abrir certificado a cada renderização.
     */
    public function isOffered(): bool
    {
        // `certificate_references.organization_id` é anulável: o certificado da operadora é
        // da plataforma, não de um inquilino, e o modelo não tem escopo global.
        return CertificateReference::query()
            ->where('is_active', true)
            ->exists();
    }

    /**
     * O plano vigente da organização inclui a assinatura criptográfica da operadora?
     *
     * Sem plano identificado a resposta é `false`: um envelope sem assinatura vigente não
     * compra nada, e concluir como aceite eletrônico com evidências é sempre correto.
     */
    public function allows(?Plan $plan): bool
    {
        return (bool) ($plan?->features[self::COMPANY_SIGNATURE] ?? false);
    }

    public function allowsForOrganization(?Organization $organization): bool
    {
        return $this->allows($organization?->currentSubscription()->with('plan')->first()?->plan);
    }

    public function allowsForEnvelope(Envelope $envelope): bool
    {
        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->first();

        return $this->allowsForOrganization($organization);
    }
}
