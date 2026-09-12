<?php

use App\Integrations\Fiscal\FiscalInvoiceProviderFactory;
use App\Integrations\Fiscal\FiscalProviderUnavailable;
use App\Integrations\Fiscal\SefinNacionalFiscalInvoiceProvider;
use App\Integrations\Payments\Preapproval\MercadoPagoPreapprovalGateway;
use App\Integrations\Payments\Preapproval\PreapprovalGatewayFactory;
use App\Integrations\Payments\Preapproval\PreapprovalRequest;
use App\Integrations\Payments\Preapproval\PreapprovalUnavailable;
use App\Integrations\Payments\Preapproval\SimulatedPreapprovalGateway;
use App\Models\FiscalInvoice;
use App\Services\Billing\PaymentReceipt;
use App\Services\Billing\RecurringBillingStatus;
use App\Services\Fiscal\FiscalInvoiceStatus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Assinaturas recorrentes e NFS-e — classe B: produção desabilitada, com mensagem
|--------------------------------------------------------------------------
*/

function inProduction(Closure $callback): mixed
{
    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        return $callback();
    } finally {
        app()['env'] = $previous;
    }
}

function preapprovalRequest(): PreapprovalRequest
{
    return new PreapprovalRequest('REF-PRE', 'Plano Profissional', 4_900, 'dono@exemplo.com', 'https://app.test/retorno');
}

test('preapproval em produção fica desabilitado, mesmo com o simulador configurado, e diz o que falta', function () {
    config()->set('assinavelox.mercadopago.preapproval.driver', 'simulated');
    Http::fake();

    inProduction(function (): void {
        $gateway = app(PreapprovalGatewayFactory::class)->make();

        expect($gateway)->toBeInstanceOf(MercadoPagoPreapprovalGateway::class)
            ->and($gateway->isEnabled())->toBeFalse()
            ->and(fn () => $gateway->create(preapprovalRequest()))->toThrow(PreapprovalUnavailable::class, 'desabilitadas em produção');

        $summary = app(RecurringBillingStatus::class)->summary();
        expect($summary['enabled'])->toBeFalse()
            ->and($summary['message'])->toBe(PreapprovalUnavailable::PRODUCTION_DISABLED)
            ->and($summary['pending'])->toHaveCount(3)
            ->and(implode(' ', $summary['pending']))->toContain('conta vendedora real')->toContain('Q20');
    });

    Http::assertNothingSent();
});

test('fora de produção o simulador de preapproval se identifica e nunca resolve', function () {
    config()->set('assinavelox.mercadopago.preapproval.driver', 'simulated');

    $gateway = app(PreapprovalGatewayFactory::class)->make();
    $result = $gateway->create(preapprovalRequest());

    expect($gateway)->toBeInstanceOf(SimulatedPreapprovalGateway::class)
        ->and($result->simulated)->toBeTrue()
        ->and($result->status)->toBe('pending')
        ->and($result->initPoint)->toEndWith('/assinatura/'.$result->preapprovalId)
        ->and($result->initPoint)->toStartWith('https://assinatura-falsa.assinavelox.invalid')
        ->and($gateway->cancel($result->preapprovalId)->status)->toBe('cancelled');
});

test('o simulador recusa operar em produção', function () {
    expect(fn () => (new SimulatedPreapprovalGateway(production: true))->create(preapprovalRequest()))
        ->toThrow(PreapprovalUnavailable::class, 'fora de produção');
});

test('o adaptador do Sistema Nacional NFS-e está desabilitado e lista exatamente o que falta', function () {
    config()->set('assinavelox.fiscal.provider', 'sefin_nacional');
    Http::fake();

    $provider = app(FiscalInvoiceProviderFactory::class)->make();

    expect($provider)->toBeInstanceOf(SefinNacionalFiscalInvoiceProvider::class)
        ->and($provider->isConfigured())->toBeFalse()
        ->and(fn () => $provider->issue(['payment_reference' => 'X']))->toThrow(FiscalProviderUnavailable::class, 'CNPJ');

    $missing = implode(' ', SefinNacionalFiscalInvoiceProvider::MISSING);
    expect($missing)->toContain('CNPJ')->toContain('IBGE')->toContain('regime')->toContain('CNC')
        ->toContain('mTLS')->toContain('XMLDSig')->toContain('LC 116')->toContain('NBS')->toContain('IBS/CBS');

    Http::assertNothingSent();
});

test('em produção o simulador fiscal nunca é escolhido', function () {
    config()->set('assinavelox.fiscal.provider', 'simulated');

    inProduction(fn () => expect(app(FiscalInvoiceProviderFactory::class)->make())->toBeNull());
});

test('sem provedor, cada pagamento mostra "não emitida — integração fiscal pendente" e nada é criado', function () {
    $this->withoutVite();
    ['owner' => $owner, 'gateway' => $gateway, 'organization' => $organization, 'plan' => $plan, 'subscription' => $subscription, 'secret' => $secret] = extendedBillingContext();
    enableFiscalInvoices();

    $pending = pendingPaymentFor($organization, $plan, $subscription);
    $gateway->pretendPayment('8100001', $pending->external_reference, 4_900);
    postMercadoPagoWebhook('8100001', $secret)->assertOk();

    expect(FiscalInvoice::withoutOrganizationScope()->count())->toBe(0);

    $this->actingAs($owner)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fiscal_invoices.notice', FiscalInvoiceStatus::NOT_ISSUED)
            ->where('payments.data.0.fiscal.status', 'not_issued')
            ->where('payments.data.0.fiscal.label', 'Nota fiscal: não emitida — integração fiscal pendente'));
});

test('com o simulador, a aprovação cria UMA linha simulated sem número, código, PDF ou XML', function () {
    ['gateway' => $gateway, 'organization' => $organization, 'plan' => $plan, 'subscription' => $subscription, 'secret' => $secret] = extendedBillingContext();
    enableFiscalInvoices();
    config()->set('assinavelox.fiscal.provider', 'simulated');
    config()->set('assinavelox.channels.allow_simulated', true);

    $pending = pendingPaymentFor($organization, $plan, $subscription);
    $gateway->pretendPayment('8100002', $pending->external_reference, 4_900);
    postMercadoPagoWebhook('8100002', $secret, action: 'payment.created')->assertOk();
    postMercadoPagoWebhook('8100002', $secret, action: 'payment.updated')->assertOk();

    $invoice = FiscalInvoice::withoutOrganizationScope()->sole();
    expect($invoice->status)->toBe(FiscalInvoice::STATUS_SIMULATED)
        ->and($invoice->payment_id)->toBe($pending->id)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->verification_code)->toBeNull()
        ->and($invoice->pdf_path)->toBeNull()
        ->and($invoice->xml_path)->toBeNull()
        ->and($invoice->idempotency_key)->toBe('nfse-'.$pending->ulid)
        ->and(FiscalInvoiceStatus::forPayment($pending->fresh(), $invoice)['label'])->toBe(FiscalInvoiceStatus::SIMULATED);
});

test('o recibo continua dizendo que não é documento fiscal com as flags ligadas', function () {
    ['owner' => $owner, 'organization' => $organization, 'payment' => $payment] = extendedBillingContext();
    enableFiscalInvoices();

    expect(app(PaymentReceipt::class)->data($payment, $organization)['disclaimer'])
        ->toBe('Este recibo NÃO é documento fiscal e não substitui a nota fiscal de serviço (NFS-e).');

    $this->actingAs($owner)->get(route('billing.payments.receipt', $payment->ulid))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
