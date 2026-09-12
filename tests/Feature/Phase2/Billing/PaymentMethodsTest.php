<?php

use App\Enums\PaymentEnvironment;
use App\Integrations\Payments\Dto\GatewayPaymentMethod;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\PaymentMethodCheck;
use App\Models\User;
use App\Services\Billing\PaymentMethodPolicy;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Meios de pagamento seguem a configuração E a conta (GET /v1/payment_methods)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner] = extendedBillingContext();
    $this->admin = User::factory()->platformAdmin()->create();
    $this->policy = app(PaymentMethodPolicy::class);
});

test('sem consulta registrada vale só a configuração, e a disponibilidade fica "não consultada"', function () {
    config()->set('assinavelox.mercadopago.enabled_methods', 'pix,card');

    $families = collect($this->policy->families('fake', PaymentEnvironment::Sandbox))->keyBy('key');

    expect($families['pix'])->toMatchArray(['configured' => true, 'available' => null, 'offered' => true])
        ->and($families['boleto'])->toMatchArray(['configured' => false, 'available' => null, 'offered' => false])
        ->and($this->policy->excludedPaymentTypes('fake', PaymentEnvironment::Sandbox))->toBe(['ticket']);
});

test('a consulta registra os meios ativos e uma família inativa na conta sai do checkout', function () {
    $this->gateway->pretendPaymentMethods([
        new GatewayPaymentMethod('pix', 'Pix', 'bank_transfer', 'deactive'),
        new GatewayPaymentMethod('bolbradesco', 'Boleto', 'ticket', 'active'),
        new GatewayPaymentMethod('visa', 'Visa', 'credit_card', 'active'),
    ]);

    adminBillingPost($this->admin, 'admin.billing.methods.refresh')->assertSessionHas('success');

    $check = PaymentMethodCheck::query()->sole();
    expect($check->status)->toBe('ok')
        ->and($check->checked_by_user_id)->toBe($this->admin->id)
        // Só os quatro campos documentados de cada meio.
        ->and(array_keys($check->methods[0]))->toBe(['id', 'name', 'payment_type_id', 'status']);

    $families = collect($this->policy->families('fake', PaymentEnvironment::Sandbox))->keyBy('key');
    expect($families['pix'])->toMatchArray(['configured' => true, 'available' => false, 'offered' => false])
        ->and($families['card']['offered'])->toBeTrue()
        ->and($this->policy->excludedPaymentTypes('fake', PaymentEnvironment::Sandbox))->toBe(['bank_transfer']);
});

test('consulta que falha fica registrada como falha e a anterior continua valendo', function () {
    adminBillingPost($this->admin, 'admin.billing.methods.refresh')->assertSessionHas('success');

    $this->gateway->failNextWith(PaymentGatewayException::rejected('list_payment_methods', 401));
    adminBillingPost($this->admin, 'admin.billing.methods.refresh')->assertSessionHas('error');

    expect(PaymentMethodCheck::query()->where('status', 'failed')->sole()->error)->toBe('rejected:401')
        ->and($this->policy->latestCheck('fake', PaymentEnvironment::Sandbox)?->status)->toBe('ok');
});

test('account_money nunca é excluído, nem se configurado', function () {
    config()->set('assinavelox.mercadopago.excluded_payment_types', 'account_money,ticket');
    config()->set('assinavelox.mercadopago.enabled_methods', 'pix,boleto,card');

    expect($this->policy->excludedPaymentTypes('fake', PaymentEnvironment::Sandbox))->toBe(['ticket']);
});

test('a tela de cobrança mostra os meios aceitos no checkout', function () {
    $this->withoutVite();
    config()->set('assinavelox.mercadopago.enabled_methods', 'pix,boleto');

    $this->actingAs($this->owner)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page
            // `available` null = conta vendedora ainda não consultada (docs/fase-2/pagamentos-e-fiscal.md §2).
            ->where('extended.methods.0', ['key' => 'pix', 'label' => 'Pix', 'offered' => true, 'available' => null])
            ->where('extended.methods.2', ['key' => 'card', 'label' => 'Cartão de crédito ou débito', 'offered' => false, 'available' => null])
            ->where('extended.offline_expiration_hours', 72));
});
