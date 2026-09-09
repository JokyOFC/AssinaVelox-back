<?php

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use App\Enums\EnvelopeStatus;
use App\Models\CertificateReference;
use App\Models\VerificationRecord;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — o certificado impresso na página de evidências é um palpite
|--------------------------------------------------------------------------
| `EnvelopeFinalizer::configuredCertificateHint()` escolhe o certificado que a página de
| evidências vai IMPRIMIR com
|
|     CertificateReference::whereNull('organization_id')->where('is_active', true)->latest('id')->first()
|
| — a linha mais recente da tabela, sem nenhuma relação com o PKCS#12 apontado por
| `pdftool.company_certificate.pfx_path`, que é o que vai de fato assinar. As linhas de
| `certificate_references` só nascem DEPOIS de uma assinatura bem-sucedida
| (`OperatorSignature::certificateReference()`), então na primeira finalização com um
| certificado novo (rotação, troca de teste para produção e vice-versa) a página de evidências
| imprime o certificado ANTERIOR.
|
| Consequência exercitada aqui: o arquivo é assinado com um certificado de TESTE, mas a página
| de evidências embutida nele exibe o titular, o emissor e a validade de um certificado de
| PRODUÇÃO e, por isso, omite o aviso obrigatório "Certificado de ambiente de teste"
| (docs/juridico/declaracao-de-aceite.md §5.2 e arquitetura §2).
*/

const REVIEW_HINT_CERT_PASS_ENV = 'REVIEW_HINT_CERT_PASS';

if (! function_exists('reviewEvidencePdfText')) {
    function reviewEvidencePdfText(string $path): string
    {
        $text = '';

        foreach (finalizationPdfPages($path) as $page) {
            foreach ($page['runs'] as $run) {
                $text .= ' '.(string) $run['text'];
            }
        }

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    putenv(REVIEW_HINT_CERT_PASS_ENV.'=senha-de-revisao-Br19!');

    $this->certificate = TestCertificate::generate(
        $this->work.DIRECTORY_SEPARATOR.'certs',
        REVIEW_HINT_CERT_PASS_ENV,
        TestCertificate::SUBJECT,
        2,
    );
});

afterEach(function () {
    putenv(REVIEW_HINT_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

it('imprime na página de evidências o certificado que realmente assinou, com o aviso de teste', function () {
    // O certificado que vai assinar: o de TESTE gerado pelo pdftool.
    TestCertificate::configure($this->certificate);

    // Uma linha ANTERIOR na tabela — o certificado de produção que a operadora usava antes
    // da troca. Continua ativo e é a linha mais recente no momento da finalização.
    CertificateReference::query()->create([
        'organization_id' => null,
        'name' => 'A1 da operadora (produção)',
        'kind' => CertificateKind::CompanyA1->value,
        'environment' => CertificateEnvironment::Production->value,
        'secret_ref' => 'COMPANY_CERT_PASSWORD',
        'subject' => 'CN=ASSINAVELOX TECNOLOGIA LTDA:11222333000181,O=ICP-Brasil,C=BR',
        'issuer' => 'CN=AC Exemplo RFB v5,O=ICP-Brasil,C=BR',
        'serial_number' => '00aabbccddeeff0011',
        'fingerprint_sha256' => str_repeat('ab', 32),
        'not_before' => Carbon::now()->subYear(),
        'not_after' => Carbon::now()->addYear(),
        'is_active' => true,
    ]);

    $scenario = finalizationEnvelope($this->work);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole();
    $signing = CertificateReference::query()->findOrFail($record->certificate_reference_id);

    // Quem assinou foi o certificado de TESTE.
    expect($signing->environment)->toBe(CertificateEnvironment::Test)
        ->and($signing->subject)->toContain('TESTE');

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $text = reviewEvidencePdfText($path);

    // A página de evidências embutida não pode identificar outro certificado…
    expect($text)->not->toContain('ASSINAVELOX TECNOLOGIA LTDA:11222333000181');
    expect($text)->not->toContain('AC Exemplo RFB v5');

    // …e, sendo o certificado de teste, o aviso é obrigatório (declaração de aceite §5.2).
    expect(mb_strtolower($text))->toContain('certificado de ambiente de teste');
});
