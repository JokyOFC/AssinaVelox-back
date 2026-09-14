<?php

use App\Enums\PaymentStatus;
use App\Services\Affiliates\CommissionLedger;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — estorno de comissão sai como TEXTO no CSV do afiliado
|--------------------------------------------------------------------------
| AffiliatePortalController::export() passa o valor já formatado ("-10,00") por Csv::row().
| Csv::cell() prefixa apóstrofo em toda célula que começa com "-" (proteção contra fórmula),
| então o estorno vira "'-10,00": num CSV o apóstrofo NÃO é consumido pelo Excel/LibreOffice na
| importação, a célula fica texto e a soma da coluna "Valor" ignora todos os estornos — o
| extrato "exportável" (roadmap §3.10) superestima o saldo do afiliado.
*/

beforeEach(function (): void {
    enableAffiliates();
});

test('o estorno de comissão sai como número negativo no CSV do afiliado', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate);

    $payment = paymentFor($organization, 10_000, paidAt: now()->subDays(31));
    app(CommissionLedger::class)->approveDue();
    movePayment($payment, PaymentStatus::Refunded, ['refunded_cents' => 10_000]);

    $csv = $this->actingAs($affiliate->user)->get(route('affiliates.commissions.export'))->streamedContent();

    expect($csv)->toContain('Estorno de comissão')
        ->and($csv)->not->toContain("'-10,00")
        ->and($csv)->toContain(';-10,00;');
});
