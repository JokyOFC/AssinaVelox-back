<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — cobrança: aviso perdido que nunca é reprocessado
|--------------------------------------------------------------------------
|
| DEFEITO. Duas decisões se combinam mal:
|
| 1. `SyncMercadoPagoPayment` é `ShouldBeUnique` com
|    `uniqueId() = 'mercadopago-payment:'.$providerPaymentId` e `uniqueFor() = 300`
|    (app/Jobs/Billing/SyncMercadoPagoPayment.php:57-65). O Mercado Pago entrega
|    `payment.created` e `payment.updated` do MESMO pagamento em sequência — a própria
|    docblock do job diz que é para isso que a unicidade existe. Só que
|    `PendingDispatch::shouldDispatch()` (vendor/laravel/framework .../PendingDispatch.php:215)
|    **descarta a mensagem em silêncio** quando o lock já está tomado: o segundo aviso
|    nunca vira job.
|
| 2. `WebhookReceipts::shouldProcess()` (app/Services/Billing/WebhookReceipts.php:70)
|    só reprocessa recibo `failed`. Um recibo que ficou em `received` — porque o job foi
|    descartado pela trava, ou porque o worker morreu entre a retirada da fila e o
|    `handle()`, ou porque a fila foi limpa — é considerado "já em processamento" para
|    sempre. Todas as reentregas do provedor (0, 15 e 30 min, 6 h, 48 h e 96 h — a única
|    rede de segurança que ele oferece) recebem `200 {"duplicate": true}` e não fazem
|    nada.
|
| Consequência: o aviso de aprovação pode ser perdido de forma permanente. O pagamento
| local fica `pending`, `ActivateSubscription` nunca roda, o cliente pagou e continua sem
| plano — e nada nos avisa, porque o recibo não está `failed`, está `received`.
|
| O teste `Billing/WebhookProcessingTest::"payment.created e payment.updated do mesmo
| pagamento ativam uma única vez"` roda com fila `sync`, onde o primeiro job termina (e
| solta o lock) antes de o segundo aviso chegar; por isso a corrida real não aparece lá.
*/

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookProcessingStatus;
use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Models\PaymentWebhookReceipt;
use App\Services\Billing\SyncPaymentFromGateway;
use App\Services\Billing\WebhookReceipts;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Billing/Support/BillingHelpers.php';

beforeEach(fn () => $this->withoutVite());

it('reprocessa na reentrega o aviso cujo job foi descartado pela trava de unicidade', function () {
    $secret = billingSecret();
    useFakeGateway();

    ['organization' => $organization] = createOrganizationWithOwner();
    $plan = paidPlan();
    $subscription = subscribeOrganization($organization, $plan);
    pendingPaymentFor($organization, $plan, $subscription);

    Queue::fake();

    // `payment.created` chega primeiro: recibo gravado, job enfileirado, trava tomada.
    postMercadoPagoWebhook('9001', $secret, action: 'payment.created')->assertOk();

    expect(Queue::pushed(SyncMercadoPagoPayment::class))->toHaveCount(1);

    // `payment.updated` do mesmo pagamento chega logo em seguida — outro fingerprint,
    // outro recibo, o MESMO `uniqueId`. A mensagem é descartada em silêncio.
    postMercadoPagoWebhook('9001', $secret, action: 'payment.updated')->assertOk();

    /** @var PaymentWebhookReceipt $updated */
    $updated = PaymentWebhookReceipt::query()
        ->where('event_fingerprint', 'payment:9001:payment.updated')
        ->firstOrFail();

    expect($updated->processing_status)->toBe(WebhookProcessingStatus::Received);

    // O provedor reentrega o mesmo aviso 15 minutos depois — a rede de segurança dele.
    postMercadoPagoWebhook('9001', $secret, action: 'payment.updated')->assertOk();

    expect(Queue::pushed(SyncMercadoPagoPayment::class))->toHaveCount(
        3,
        'o aviso perdido nunca é reprocessado: toda reentrega é respondida como duplicata',
    );
});

it('não deixa um pagamento aprovado sem ativação quando o job do aviso se perde', function () {
    $secret = billingSecret();
    $gateway = useFakeGateway();

    ['organization' => $organization] = createOrganizationWithOwner();
    $plan = paidPlan();
    $subscription = subscribeOrganization($organization, $plan, 'past_due');
    $payment = pendingPaymentFor($organization, $plan, $subscription);

    $gateway->pretendPayment('9001', $payment->external_reference, (int) $payment->amount_cents, status: 'approved');

    Queue::fake();

    // O aviso de aprovação chega, é autenticado e vira recibo + job.
    postMercadoPagoWebhook('9001', $secret, action: 'payment.updated')->assertOk();

    expect(Queue::pushed(SyncMercadoPagoPayment::class))->toHaveCount(1);

    // ... e o job MORRE antes do `handle()`: worker levado por OOM ou SIGKILL, `queue:flush`,
    // Redis reiniciado sem persistência. Não há `failed()`, não há rastro: o recibo fica em
    // `received` para sempre. (`Queue::fake()` reproduz isso literalmente — nada roda.)
    expect(PaymentWebhookReceipt::query()->firstOrFail()->processing_status)
        ->toBe(WebhookProcessingStatus::Received);

    // O provedor reentrega o aviso de aprovação quantas vezes a política dele manda
    // (0, 15 e 30 min, 6 h, 48 h, 96 h). Nenhuma delas produz trabalho novo.
    foreach (range(1, 5) as $ignored) {
        postMercadoPagoWebhook('9001', $secret, action: 'payment.updated')->assertOk();
    }

    foreach (Queue::pushed(SyncMercadoPagoPayment::class)->skip(1) as $job) {
        $job->handle(app(SyncPaymentFromGateway::class), app(WebhookReceipts::class));
    }

    $payment->refresh();
    $subscription->refresh();

    expect($payment->status)->toBe(
        PaymentStatus::Approved,
        'o pagamento aprovado no provedor nunca foi sincronizado: o aviso de aprovação sumiu',
    );

    expect($payment->activated_at)->not->toBeNull(
        'o cliente pagou e a assinatura nunca foi ativada',
    );

    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});
