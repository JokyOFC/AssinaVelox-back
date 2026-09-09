<?php

use App\Enums\PaymentStatus;
use App\Models\Plan;
use App\Services\Billing\PaymentReceipt;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->plan = paidPlan();
    $this->subscription = subscribeOrganization($organization, $this->plan);

    $this->payment = pendingPaymentFor($organization, $this->plan, $this->subscription);
    $this->payment->forceFill([
        'status' => PaymentStatus::Approved,
        'status_detail' => 'accredited',
        'provider_payment_id' => '1234567890',
        'payment_method_id' => 'pix',
        'paid_at' => now()->subHour(),
        'activated_at' => now()->subHour(),
    ])->save();
});

/**
 * Texto do PDF como o leitor humano o vê.
 *
 * O DOMPDF embute a DejaVu Sans com `Identity-H`: os operadores de texto carregam
 * identificadores de glifo, não ASCII, então ler o arquivo cru nunca encontraria a
 * frase impressa. O `receipt_text.py` (venv do pdftool, só pypdf) resolve isso usando o
 * mapa ToUnicode do próprio arquivo.
 */
function receiptText(string $pdf): string
{
    $python = base_path('tools/pdftool/.venv/Scripts/python.exe');

    if (! file_exists($python)) {
        $python = base_path('tools/pdftool/.venv/bin/python');
    }

    if (! file_exists($python)) {
        test()->markTestSkipped('venv do pdftool ausente: sem pypdf para ler o texto do PDF.');
    }

    $file = tempnam(sys_get_temp_dir(), 'recibo').'.pdf';
    file_put_contents($file, $pdf);

    $process = Process::fromShellCommandline(
        '"'.$python.'" "'.__DIR__.'/Support/receipt_text.py" "'.$file.'"',
    );
    $process->run();

    @unlink($file);

    if (! $process->isSuccessful()) {
        test()->fail('não foi possível extrair o texto do recibo: '.$process->getErrorOutput());
    }

    return $process->getOutput();
}

test('o recibo em PDF é gerado e avisa, com todas as letras, que não é documento fiscal', function () {
    actingAsMember($this->owner, $this->organization);

    $response = $this->get(route('billing.payments.receipt', ['payment' => $this->payment->ulid]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    $pdf = $response->getContent();

    expect(substr($pdf, 0, 5))->toBe('%PDF-');

    $text = receiptText($pdf);

    expect($text)->toContain('NÃO é documento fiscal')
        ->and($text)->toContain('nota fiscal de serviço')
        ->and($text)->toContain('Recibo de pagamento');
});

test('o aviso do recibo é o mesmo texto exposto pelo serviço', function () {
    expect(PaymentReceipt::NOT_A_FISCAL_DOCUMENT)
        ->toContain('NÃO é documento fiscal')
        ->toContain('NFS-e');

    $data = app(PaymentReceipt::class)->data($this->payment, $this->organization);

    expect($data['disclaimer'])->toBe(PaymentReceipt::NOT_A_FISCAL_DOCUMENT)
        ->and($data['amount']['cents'])->toBe((int) $this->plan->price_cents)
        ->and($data['amount']['currency'])->toBe('BRL')
        ->and($data['method'])->toBe('Pix');
});

test('pagamento não aprovado não tem recibo', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    actingAsMember($this->owner, $this->organization);

    $this->get(route('billing.payments.receipt', ['payment' => $pending->ulid]))->assertNotFound();
});

test('o recibo de outra organização não é acessível', function () {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();

    actingAsMember($otherOwner, $other);

    $this->get(route('billing.payments.receipt', ['payment' => $this->payment->ulid]))->assertNotFound();
});

test('a lista de pagamentos só oferece recibo para pagamento pago', function () {
    pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    actingAsMember($this->owner, $this->organization);

    $rows = $this->get(route('billing.index'))->viewData('page')['props']['payments']['data'];

    $paid = collect($rows)->firstWhere('id', $this->payment->ulid);
    $pending = collect($rows)->firstWhere('receipt_url', null);

    expect($paid['receipt_url'])->toBe(route('billing.payments.receipt', ['payment' => $this->payment->ulid]))
        ->and($pending)->not->toBeNull();
});

test('os dados de faturamento salvos aparecem no recibo', function () {
    actingAsMember($this->owner, $this->organization);

    $this->patch(route('billing.profile.update'), [
        'legal_name' => 'Horizonte Negócios Ltda.',
        'document_number' => '11.222.333/0001-81',
        'address_line' => 'Av. Paulista, 1000 · cj. 1201',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postal_code' => '01310-100',
        'email' => 'financeiro@horizonte.test',
    ])->assertSessionHasNoErrors();

    $data = app(PaymentReceipt::class)->data($this->payment, $this->organization->refresh());

    expect($data['customer']['name'])->toBe('Horizonte Negócios Ltda.')
        ->and($data['customer']['document'])->toBe('11.222.333/0001-81')
        ->and($data['customer']['address'])->toContain('São Paulo/SP')
        ->and($data['customer']['address'])->toContain('01310-100')
        ->and($data['customer']['email'])->toBe('financeiro@horizonte.test');

    // O documento fica criptografado na coluna, nunca em texto no JSON de settings.
    expect($this->organization->refresh()->getRawOriginal('tax_id'))->not->toContain('11222333');
});

test('CPF/CNPJ inválido e CEP curto são recusados nos dados de faturamento', function () {
    actingAsMember($this->owner, $this->organization);

    $this->patch(route('billing.profile.update'), [
        'legal_name' => 'Empresa X',
        'document_number' => '11.111.111/1111-11',
        'address_line' => 'Rua A, 1',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postal_code' => '01310-100',
        'email' => 'a@b.test',
    ])->assertSessionHasErrors('document_number');

    $this->patch(route('billing.profile.update'), [
        'legal_name' => 'Empresa X',
        'document_number' => '11.222.333/0001-81',
        'address_line' => 'Rua A, 1',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postal_code' => '013',
        'email' => 'a@b.test',
    ])->assertSessionHasErrors('postal_code');
});

test('o plano sandbox aparece rotulado como preço fictício na escolha de planos', function () {
    actingAsMember($this->owner, $this->organization);

    $plans = collect($this->get(route('plans.index'))->viewData('page')['props']['plans']);

    $sandbox = $plans->firstWhere('code', $this->plan->code);
    $free = $plans->firstWhere('code', Plan::CODE_FREE);

    expect($sandbox)->not->toBeNull()
        ->and($sandbox['is_sandbox'])->toBeTrue()
        ->and($sandbox['price_is_placeholder'])->toBeTrue()
        ->and($free['is_sandbox'])->toBeFalse()
        ->and($free['price_is_placeholder'])->toBeFalse();
});
