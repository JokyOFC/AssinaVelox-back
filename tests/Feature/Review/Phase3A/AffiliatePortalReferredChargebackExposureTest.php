<?php

use App\Enums\PaymentStatus;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — o portal do afiliado revela que o CLIENTE indicado contestou um pagamento
|--------------------------------------------------------------------------
| docs/fase-3/afiliados.md §7: do indicado o portal mostra "só o nome da organização, datas,
| estado". Mas o extrato (AffiliatePresenter::commissionRows → `reason_label`) e o CSV dizem
| "Pagamento contestado" — o afiliado (terceiro) fica sabendo que a organização indicada abriu
| um chargeback no cartão, dado financeiro do cliente que ele não precisa para entender por que
| a comissão foi revertida ("Revertida" basta).
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
});

test('o portal e o CSV do afiliado não revelam que a organização indicada contestou o pagamento', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate);

    $payment = paymentFor($organization, 10_000, paidAt: now());
    movePayment($payment, PaymentStatus::ChargedBack);

    $page = $this->actingAs($affiliate->user)->get(route('affiliates.index'));
    $page->assertOk();

    $csv = $this->actingAs($affiliate->user)->get(route('affiliates.commissions.export'))->streamedContent();

    expect(json_encode($page->viewData('page')['props'], JSON_UNESCAPED_UNICODE))->not->toContain('contestado')
        ->and($csv)->not->toContain('contestado');
});
