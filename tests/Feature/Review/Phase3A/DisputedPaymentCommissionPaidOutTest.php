<?php

use App\Enums\PaymentStatus;
use App\Models\Commission;
use App\Models\User;
use App\Services\Affiliates\CommissionLedger;
use App\Services\Affiliates\PayoutBatches;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — comissão de pagamento EM DISPUTA entra no lote de repasse
|--------------------------------------------------------------------------
| O roadmap §3.10 paga comissão "sobre pagamentos aprovados". Uma disputa (`in_mediation`)
| aberta depois do prazo de 30 dias (o Mercado Pago aceita contestação muito depois) não muda
| nada na comissão já `approved`: PayoutBatches::build() só olha `commissions.status` e nunca o
| estado do pagamento. A comissão é repassada enquanto o pagamento está contestado; se virar
| `charged_back`, sobra um lançamento negativo que só "abate" se o afiliado voltar a vender.
*/

beforeEach(function (): void {
    enableAffiliates();
});

test('comissão aprovada cujo pagamento entrou em disputa não entra no lote até a disputa terminar', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate);

    $payment = paymentFor($organization, 20_000, paidAt: now()->subDays(31));
    app(CommissionLedger::class)->approveDue();
    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_APPROVED);

    // O cliente abre uma contestação: o pagamento deixa de estar aprovado.
    movePayment($payment, PaymentStatus::InMediation);

    $admin = User::factory()->platformAdmin()->create();

    try {
        app(PayoutBatches::class)->build('BRL', now(), $admin);
    } catch (ValidationException) {
        // "Nenhum afiliado tem saldo aprovado a repassar" também é um resultado aceitável.
    }

    expect(Commission::query()->sole()->payout_batch_id)->toBeNull();
});
