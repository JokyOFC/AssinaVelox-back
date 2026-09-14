<?php

use App\Enums\MembershipRole;
use App\Models\Commission;
use App\Services\Affiliates\CommissionLedger;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — autoindicação contornável: o afiliado administra a organização indicada
|--------------------------------------------------------------------------
| As regras de autoindicação só rodam NO CADASTRO (Attribution::selfReferralReasons). A
| comissão (CommissionLedger::createCommission / approveDue) nunca confere se o afiliado é
| membro ativo (owner/admin) da organização que paga. Basta cadastrar a organização com outro
| e-mail/IP e depois entrar nela como administrador: o afiliado passa a receber comissão sobre
| os próprios pagamentos — exatamente o que RiskRule::AffiliateSelfReferral descreve
| ("partido da própria conta ou de quem a administra").
*/

beforeEach(function (): void {
    enableAffiliates();
});

test('afiliado que é administrador ativo da organização indicada não recebe comissão sobre os pagamentos dela', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate);

    // Depois da atribuição, o próprio afiliado entra como administrador da organização.
    attachMember($organization, MembershipRole::Admin, user: $affiliate->user);

    paymentFor($organization, 50_000, paidAt: now()->subDays(31));
    app(CommissionLedger::class)->approveDue();

    expect(Commission::query()
        ->where('affiliate_id', $affiliate->id)
        ->whereIn('status', [Commission::STATUS_PENDING, Commission::STATUS_APPROVED])
        ->count())->toBe(0);
});
