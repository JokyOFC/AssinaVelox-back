<?php

use App\Models\Organization;
use App\Models\Referral;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';
require_once __DIR__.'/../../Phase3/Risk/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — cliente indicado vai para "observação" por causa do IP de OUTRA organização
|--------------------------------------------------------------------------
| `duplicate_ip` compara o IP do cadastro com o de OUTRA indicação do mesmo afiliado (outra
| organização). O programa já segura a comissão (`held`) — a medida certa contra o afiliado —,
| mas AffiliateRiskSignals também grava `affiliate_self_referral` (50 pontos ≥ limiar 30) NA
| ORGANIZAÇÃO INDICADA, que entra em `watch`, ganha um caso na fila e, em
| /revisao-de-seguranca, lê que "a indicação ... parece ter partido da própria conta". O cenário
| legítimo (clientes cadastrados no escritório do contador/consultor) é o do próprio
| SelfReferralTest ("Mesmo escritório de contabilidade, contas legítimas.").
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
    config()->set('assinavelox.features.antifraud', true);
    Notification::fake();
});

test('segunda organização cadastrada do mesmo IP que outro cliente do afiliado não entra em observação de risco', function () {
    $affiliate = makeAffiliate();
    $cookie = referralCookieValue($affiliate->code);

    signupWithReferral('compras@padaria-um-exemplo.com.br', $cookie, '198.51.100.60');
    signupWithReferral('contato@oficina-dois-exemplo.com.br', $cookie, '198.51.100.60');

    $second = Referral::query()->orderByDesc('id')->firstOrFail();
    expect($second->status)->toBe(Referral::STATUS_HELD);

    expect(riskStatusOf(Organization::query()->findOrFail($second->organization_id)))->toBe('normal');
});
