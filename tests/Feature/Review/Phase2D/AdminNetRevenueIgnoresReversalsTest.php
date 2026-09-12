<?php

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Billing\AdminBillingReport;

require_once __DIR__.'/../../Phase2/Billing/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial D-PAY — "receita líquida" conta dinheiro que já voltou
|--------------------------------------------------------------------------
| AdminBillingReport::revenue(): bruto = pagamentos approved|refunded|charged_back|in_mediation;
| estornado = só linhas `payment_refunds` aprovadas. Resultado:
|  - contestação (charged_back) perdida: o dinheiro saiu da conta, mas entra inteiro no líquido;
|  - estorno feito fora do app (painel do Mercado Pago, que a conciliação/webhook registram como
|    `refunded` com `refunded_cents` cheio): sem linha em `payment_refunds`, entra inteiro no
|    líquido.
| O painel interno mostra "Receita líquida" maior que o dinheiro efetivamente retido.
*/

function reversedPayment(PaymentStatus $status, int $refundedCents): Payment
{
    ['organization' => $organization] = createOrganizationWithOwner();
    $plan = paidPlan();
    $subscription = subscribeOrganization($organization, $plan);

    return Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'provider' => 'fake',
        'status' => $status,
        'status_detail' => $status->value,
        'provider_payment_id' => (string) random_int(1_000_000, 9_999_999),
        'amount_cents' => 4_900,
        'currency' => 'BRL',
        'environment' => PaymentEnvironment::Sandbox,
        'paid_at' => now()->subDays(2),
        'activated_at' => now()->subDays(2),
        'refunded_cents' => $refundedCents,
    ]);
}

test('contestação perdida não conta como receita líquida', function () {
    enableExtendedPayments();
    reversedPayment(PaymentStatus::ChargedBack, 0);

    $line = collect(app(AdminBillingReport::class)->revenue(now()->subDays(30), now()->addDay(), null))->firstWhere('currency', 'BRL');

    expect($line['gross_cents'])->toBe(4_900)
        // Defeito: líquido 4.900.
        ->and($line['net_cents'])->toBe(0);
});

test('estorno integral feito fora do app (refunded pela consulta) não conta como receita líquida', function () {
    enableExtendedPayments();
    reversedPayment(PaymentStatus::Refunded, 4_900);

    $line = collect(app(AdminBillingReport::class)->revenue(now()->subDays(30), now()->addDay(), null))->firstWhere('currency', 'BRL');

    // Defeito: estornado 0 e líquido 4.900, embora o próprio pagamento diga refunded_cents = 4.900.
    expect($line['refunded_cents'])->toBe(4_900)
        ->and($line['net_cents'])->toBe(0);
});
