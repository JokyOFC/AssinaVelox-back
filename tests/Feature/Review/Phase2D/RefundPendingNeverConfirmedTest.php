<?php

use App\Enums\PaymentStatus;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Billing\AdminBillingReport;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase2/Billing/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial D-PAY — estorno "em processamento" nunca é confirmado
|--------------------------------------------------------------------------
| RequestRefund::applyRemote() grava `pending` quando o provedor responde `in_process`/`authorized`
| e a mensagem promete "o status é atualizado quando ele confirmar". Nenhum código volta a escrever
| em `payment_refunds`: nem o webhook `payment` (SyncPaymentFromGateway), nem a conciliação, nem
| um job. O pagamento vira `refunded`, mas a linha do estorno fica `pending` para sempre — conta
| como "estorno em aberto", some da receita estornada e bloqueia qualquer outro pedido.
*/

test('estorno respondido como in_process é confirmado quando o provedor conclui', function () {
    ['gateway' => $gateway, 'payment' => $payment, 'plan' => $plan] = extendedBillingContext();
    $admin = User::factory()->platformAdmin()->create();

    $gateway->nextRefundWillBe('in_process');

    adminBillingPost($admin, 'admin.billing.payments.refund', ['payment' => $payment->ulid], [
        'reason' => 'Cobrança em duplicidade',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHas('warning', fn (string $message) => str_contains($message, 'atualizado quando ele confirmar'));

    $refund = PaymentRefund::withoutOrganizationScope()->sole();
    expect($refund->status)->toBe(PaymentRefund::STATUS_PENDING);

    // O Mercado Pago conclui o estorno e avisa pelo tópico `payment` (consulta = refunded).
    $gateway->pretendPayment('9000000001', $payment->external_reference, (int) $plan->price_cents, 'refunded', 'refunded', 'BRL', 'visa');
    postMercadoPagoWebhook('9000000001', billingSecret())->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);

    $report = app(AdminBillingReport::class);
    $revenue = collect($report->revenue(now()->subDays(30), now()->addDay(), null))->firstWhere('currency', 'BRL');

    // Defeito: a linha continua `pending` ("Em processamento no Mercado Pago") para sempre.
    expect($refund->fresh()->status)->toBe(PaymentRefund::STATUS_APPROVED)
        ->and($report->counters(null)['open_refunds'])->toBe(0)
        // e o painel diz "estornado R$ 0,00 / líquido R$ 49,00" para um pagamento devolvido por inteiro.
        ->and($revenue['refunded_cents'])->toBe((int) $plan->price_cents);
});
