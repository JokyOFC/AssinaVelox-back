<?php

use App\Integrations\Payments\MercadoPagoGateway;
use App\Models\User;
use App\Services\Billing\ReconcilePayments;
use App\Services\Billing\RefreshPaymentMethods;
use App\Services\Billing\RequestRefund;
use App\Services\Billing\SyncChargebackFromGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Nenhum dado de cartão ou do pagador nas tabelas novas
|--------------------------------------------------------------------------
| As respostas simuladas trazem de propósito o que o provedor PODERIA devolver (primeiros e
| últimos dígitos, titular, e-mail e CPF do pagador). Nada disso pode sobrar em banco.
*/

test('estorno, contestação, conciliação e meios não guardam cartão nem pagador', function () {
    Http::preventStrayRequests();
    enableExtendedPayments();
    config()->set('assinavelox.mercadopago.driver', 'mercadopago');
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');
    config()->set('assinavelox.mercadopago.retry_delay_ms', 0);

    ['organization' => $organization] = createOrganizationWithOwner();
    $plan = paidPlan();
    $subscription = subscribeOrganization($organization, $plan);
    $payment = activatedPaymentFor($organization, $plan, $subscription, null, '8001', provider: MercadoPagoGateway::NAME);

    $sensitive = [
        'card' => ['first_six_digits' => '450995', 'last_four_digits' => '3704', 'cardholder' => ['name' => 'APRO TITULAR', 'identification' => ['number' => '19119119100']]],
        'payer' => ['email' => 'comprador@exemplo.com', 'identification' => ['type' => 'CPF', 'number' => '19119119100']],
        'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126600014br.gov.bcb.pix']],
    ];
    $remotePayment = fn (string $status) => [
        'id' => 8001, 'status' => $status, 'status_detail' => $status === 'refunded' ? 'refunded' : 'accredited',
        'external_reference' => $payment->external_reference, 'transaction_amount' => 49, 'currency_id' => 'BRL',
        'live_mode' => false, 'payment_method_id' => 'visa', 'payment_type_id' => 'credit_card', ...$sensitive,
    ];

    Http::fake([
        'api.mercadopago.com/v1/payments/8001/refunds' => Http::response(['id' => 91, 'payment_id' => 8001, 'amount' => 49, 'status' => 'approved', ...$sensitive], 201),
        'api.mercadopago.com/v1/payments/8001' => Http::response($remotePayment('refunded')),
        'api.mercadopago.com/v1/payments/search*' => Http::response(['results' => [$remotePayment('refunded')], 'paging' => ['total' => 1, 'limit' => 30, 'offset' => 0]]),
        'api.mercadopago.com/v1/chargebacks/*' => Http::response(['id' => 'CB9', 'payments' => [8001], 'amount' => 49, 'currency' => 'BRL', 'live_mode' => false, ...$sensitive]),
        'api.mercadopago.com/v1/payment_methods' => Http::response([['id' => 'visa', 'name' => 'Visa', 'payment_type_id' => 'credit_card', 'status' => 'active', ...$sensitive]]),
    ]);

    $admin = User::factory()->platformAdmin()->create();
    app(RequestRefund::class)->handle($payment, null, 'Estorno de teste', $admin, 'platform_admin', (string) Str::uuid());
    app(SyncChargebackFromGateway::class)->handle('CB9');
    app(ReconcilePayments::class)->run('schedule');
    app(RefreshPaymentMethods::class)->handle($admin);

    $stored = json_encode([
        DB::table('payments')->get(),
        DB::table('payment_refunds')->get(),
        DB::table('payment_chargebacks')->get(),
        DB::table('reconciliation_runs')->get(),
        DB::table('reconciliation_items')->get(),
        DB::table('payment_method_checks')->get(),
        DB::table('fiscal_invoices')->get(),
        DB::table('audit_events')->get(),
    ]);

    foreach (['450995', '3704', 'APRO TITULAR', 'comprador@exemplo.com', '19119119100', 'br.gov.bcb.pix'] as $forbidden) {
        expect($stored)->not->toContain($forbidden);
    }

    expect(DB::table('payment_refunds')->count())->toBe(1)
        ->and(DB::table('payment_chargebacks')->count())->toBe(1)
        ->and(DB::table('payment_method_checks')->count())->toBe(1);
});
