<?php

use App\Integrations\Fiscal\FakeFiscalInvoiceProvider;
use App\Models\FiscalInvoice;
use App\Services\Fiscal\FiscalInvoiceStatus;
use App\Services\Fiscal\IssueFiscalInvoice;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Phase2/Billing/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial D-PAY — rótulo fiscal promete uma consulta que não existe
|--------------------------------------------------------------------------
| IssueFiscalInvoice (docblock e docs §11): uma emissão inconclusiva fica `pending` com
| `error = inconclusive` e **não** é reemitida nem consultada — "o contrato atual não tem consulta
| pelo identificador da DPS". Nenhum job, agendamento ou tela volta a olhar essa linha.
| Mesmo assim, a tela de cobrança do CLIENTE mostra "emissão sem confirmação — consultando antes
| de reemitir": uma afirmação de atividade que o sistema não faz (T4/T5, rótulos honestos).
*/

test('nota inconclusiva não diz ao cliente que está sendo consultada', function () {
    ['organization' => $organization, 'owner' => $owner, 'payment' => $payment] = extendedBillingContext();
    enableFiscalInvoices();
    config()->set('assinavelox.fiscal.provider', 'simulated');

    $invoice = new FiscalInvoice;
    $invoice->forceFill([
        'organization_id' => $organization->id,
        'payment_id' => $payment->id,
        'provider' => app(FakeFiscalInvoiceProvider::class)->name(),
        'status' => FiscalInvoice::STATUS_PENDING,
        'idempotency_key' => 'nfse-'.$payment->ulid,
        'correlation_id' => (string) Str::ulid(),
        'error' => 'inconclusive',
    ])->save();

    // Rodar a emissão de novo não consulta nem reemite: a linha fica exatamente como estava.
    app(IssueFiscalInvoice::class)->handle($payment->fresh());
    expect($invoice->fresh()->status)->toBe(FiscalInvoice::STATUS_PENDING)
        ->and($invoice->fresh()->error)->toBe('inconclusive');

    $label = FiscalInvoiceStatus::forPayment($payment->fresh(), $invoice->fresh())['label'];

    $this->withoutVite();
    $this->actingAs($owner)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('payments.data.0.fiscal.label', $label));

    // Defeito: "consultando antes de reemitir" — ninguém consulta.
    expect(mb_strtolower($label))->not->toContain('consultando');
});
