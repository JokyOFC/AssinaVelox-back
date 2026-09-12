<?php

use App\Enums\PaymentStatus;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\User;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Cancelamento — só de pagamento pendente
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner,
        'plan' => $this->plan, 'subscription' => $this->subscription, 'payment' => $this->payment] = extendedBillingContext();
});

test('pendente sem pagamento no provedor: cancela só aqui, sem chamada', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    $this->actingAs($this->owner)->post(route('billing.payments.cancel', $pending->ulid))->assertSessionHas('success');

    $pending->refresh();
    expect($pending->status)->toBe(PaymentStatus::Cancelled)
        ->and($pending->status_detail)->toBe('cancelled_before_payment')
        ->and($pending->cancelled_at)->not->toBeNull()
        ->and($this->gateway->cancelRequests())->toBe([]);
});

test('pendente com Pix gerado: PUT no provedor com chave estável e status pela consulta', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $pending->forceFill(['provider' => 'fake', 'provider_payment_id' => '7770001'])->save();
    $this->gateway->pretendPayment('7770001', $pending->external_reference, (int) $this->plan->price_cents, 'pending', 'pending_waiting_transfer', 'BRL', 'pix');

    $this->actingAs($this->owner)->post(route('billing.payments.cancel', $pending->ulid))->assertSessionHas('success');

    expect($this->gateway->cancelRequests())->toBe([['payment' => '7770001', 'idempotency_key' => 'cancel-'.$pending->ulid]]);

    $pending->refresh();
    expect($pending->status)->toBe(PaymentStatus::Cancelled)
        ->and($pending->status_detail)->toBe('by_collector')
        ->and($pending->cancelled_at)->not->toBeNull();
});

test('pagamento aprovado não é cancelado (desfaz-se por estorno)', function () {
    $this->actingAs($this->owner)->post(route('billing.payments.cancel', $this->payment->ulid))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Só pagamentos pendentes'));

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Approved)
        ->and($this->gateway->cancelRequests())->toBe([]);
});

test('resposta inconclusiva não muda nada e avisa', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $pending->forceFill(['provider' => 'fake', 'provider_payment_id' => '7770002'])->save();
    $this->gateway->pretendPayment('7770002', $pending->external_reference, (int) $this->plan->price_cents, 'pending', 'pending_waiting_payment', 'BRL', 'bolbradesco');
    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('cancel_payment'));

    $this->actingAs($this->owner)->post(route('billing.payments.cancel', $pending->ulid))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Não conseguimos confirmar o cancelamento'));

    expect($pending->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('pagamento de outra organização não resolve', function () {
    ['organization' => $other] = createOrganizationWithOwner();
    $foreign = Payment::factory()->create(['organization_id' => $other->id, 'plan_id' => $this->plan->id]);

    $this->actingAs($this->owner)->post(route('billing.payments.cancel', $foreign->ulid))->assertNotFound();

    expect($foreign->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('o painel interno também cancela, com senha confirmada', function () {
    $admin = User::factory()->platformAdmin()->create();
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    $this->actingAs($admin)->post(route('admin.billing.payments.cancel', $pending->ulid))->assertRedirect();
    expect($pending->fresh()->status)->toBe(PaymentStatus::Pending);

    adminBillingPost($admin, 'admin.billing.payments.cancel', ['payment' => $pending->ulid])->assertSessionHas('success');
    expect($pending->fresh()->status)->toBe(PaymentStatus::Cancelled);
});
