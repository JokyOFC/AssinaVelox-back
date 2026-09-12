<?php

use App\Models\PaymentMethodCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Phase2/Billing/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial D-PAY — meios anunciados ao cliente sem consulta à conta
|--------------------------------------------------------------------------
| PaymentMethodPolicy: "Sem consulta registrada, a disponibilidade é 'não consultada' e vale só a
| configuração — a tela diz isso, em vez de supor." docs/fase-2/pagamentos-e-fiscal.md §2: o
| comportamento do Checkout Pro sem chave Pix é NÃO CONFIRMADO (e a chave Pix é pendência §17.6).
|
| BillingController::index() descarta `available` e manda ao cliente só `offered`, que é `true`
| quando nunca houve consulta. A tela afirma "Meios aceitos no checkout: Pix · Boleto · Cartão"
| como fato, sem nenhum jeito de dizer "não consultada".
*/

test('a tela do cliente distingue meio confirmado na conta de meio só configurado', function () {
    ['owner' => $owner] = extendedBillingContext();
    config()->set('assinavelox.mercadopago.enabled_methods', 'pix,boleto,card');

    expect(PaymentMethodCheck::query()->count())->toBe(0);

    $this->withoutVite();
    $this->actingAs($owner)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page
            // Hoje: {key: pix, label: Pix, offered: true} — sem disponibilidade, anunciado como aceito.
            ->where('extended.methods.0.key', 'pix')
            ->has('extended.methods.0.available')
            ->where('extended.methods.0.available', null));
});
