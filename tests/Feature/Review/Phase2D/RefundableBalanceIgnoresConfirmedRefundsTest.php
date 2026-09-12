<?php

use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Billing\AdminBillingReport;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase2/Billing/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial D-PAY — saldo estornável ignora estornos já confirmados
|--------------------------------------------------------------------------
| Payment::refundableCents() diz "valor cobrado − estornos confirmados", mas só lê
| `payments.refunded_cents`, que é preenchido pela CONSULTA posterior ao estorno. Quando essa
| consulta falha (caminho documentado `billing.refund.sync_deferred`), a linha do estorno está
| `approved` e o saldo local continua cheio. RequestRefund então:
|  - aceita um novo valor acima do saldo real (a checagem "limites respeitados antes da chamada"
|    não vale);
|  - trata o pedido sem valor como INTEGRAL (corpo `{}`), grava amount_cents = valor cheio, e o
|    provedor devolve só o restante — a soma dos estornos "estornados" passa do valor pago e a
|    receita estornada do painel fica maior que a bruta.
*/

beforeEach(function (): void {
    ['gateway' => $this->gateway, 'payment' => $this->payment] = extendedBillingContext();
    $this->admin = User::factory()->platformAdmin()->create();

    // Estorno parcial de R$ 30,00 aplicado e confirmado pelo provedor…
    $remote = $this->gateway->refundPayment('9000000001', 3_000, 'first-partial-key');

    // …e registrado aqui como `approved`, mas a consulta GET /v1/payments/{id} logo depois falhou
    // (RequestRefund::syncPayment → `billing.refund.sync_deferred`): refunded_cents segue 0.
    $row = new PaymentRefund;
    $row->forceFill([
        'organization_id' => $this->payment->organization_id,
        'payment_id' => $this->payment->id,
        'provider' => 'fake',
        'provider_refund_id' => $remote->refundId,
        'provider_status' => 'approved',
        'amount_cents' => 3_000,
        'currency' => 'BRL',
        'kind' => PaymentRefund::KIND_PARTIAL,
        'status' => PaymentRefund::STATUS_APPROVED,
        'reason' => 'Desconto concedido',
        'initiator' => PaymentRefund::INITIATOR_PLATFORM_ADMIN,
        'requested_by_user_id' => $this->admin->id,
        'idempotency_key' => (string) Str::uuid(),
        'requested_at' => now(),
        'confirmed_at' => now(),
    ])->save();

    expect($this->payment->fresh()->refunded_cents)->toBe(0);
});

test('o saldo estornável desconta os estornos já confirmados', function () {
    // 4.900 pagos − 3.000 confirmados = 1.900.
    expect($this->payment->fresh()->refundableCents())->toBe(1_900);
});

test('um segundo parcial acima do saldo real é recusado sem chamar o provedor', function () {
    $before = count($this->gateway->refundRequests());

    adminBillingPost($this->admin, 'admin.billing.payments.refund', ['payment' => $this->payment->ulid], [
        'amount_cents' => 3_000,
        'reason' => 'Mais um desconto',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHas('error', fn (string $message) => str_contains($message, 'R$ 19,00'));

    expect($this->gateway->refundRequests())->toHaveCount($before);
});

test('pedido "integral" depois de um parcial confirmado não infla o total estornado', function () {
    adminBillingPost($this->admin, 'admin.billing.payments.refund', ['payment' => $this->payment->ulid], [
        'reason' => 'Cancelamento do contrato',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $approved = (int) PaymentRefund::withoutOrganizationScope()
        ->where('payment_id', $this->payment->id)
        ->where('status', PaymentRefund::STATUS_APPROVED)
        ->sum('amount_cents');

    $revenue = collect(app(AdminBillingReport::class)->revenue(now()->subDays(30), now()->addDay(), null))
        ->firstWhere('currency', 'BRL');

    // Defeito: 3.000 + 4.900 = 7.900 "estornados" de um pagamento de 4.900; líquido negativo.
    expect($approved)->toBeLessThanOrEqual(4_900)
        ->and($revenue['refunded_cents'])->toBeLessThanOrEqual($revenue['gross_cents'])
        ->and($revenue['net_cents'])->toBeGreaterThanOrEqual(0);
});
