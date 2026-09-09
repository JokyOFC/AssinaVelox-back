<?php

use App\Enums\CertificateEnvironment;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
use App\Integrations\Pdf\PyHankoSigner;
use App\Models\CertificateReference;
use App\Models\DocumentVersion;
use App\Models\VerificationRecord;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Assinatura criptográfica da operadora na finalização
|--------------------------------------------------------------------------
| Roda contra o pyHanko real, com um certificado de TESTE gerado por `gen-test-cert`
| (autoassinado, CN com "TESTE", rotulado environment=test). A senha existe apenas em uma
| variável de ambiente do processo de teste, sob um nome — nunca em config, argv ou log.
|
| O marco "A1 com credencial de PRODUÇÃO" permanece PENDENTE: nada aqui prova que um
| certificado ICP-Brasil real funciona (ver docs/finalizacao-e-evidencias.md).
*/

const FINALIZATION_CERT_PASS_ENV = 'FINALIZATION_TEST_CERT_PASS';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    putenv(FINALIZATION_CERT_PASS_ENV.'=senha-de-teste-Xk93!');

    $this->certificate = TestCertificate::generate(
        $this->work.DIRECTORY_SEPARATOR.'certs',
        FINALIZATION_CERT_PASS_ENV,
        TestCertificate::SUBJECT,
        2,
    );
});

afterEach(function () {
    putenv(FINALIZATION_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

it('aplica UMA assinatura íntegra e válida no perfil declarado quando há certificado', function () {
    $signer = TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);

    expect($signer)->toBeInstanceOf(PyHankoSigner::class)
        ->and($signer->isConfigured())->toBeTrue();

    $scenario = finalizationEnvelope($this->work);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $path = finalizationDownload($envelope->finalVersion, $this->work.'/assinado.pdf');
    $client = app(PdfToolClient::class);

    $inspection = $client->inspect($path);

    expect($inspection->hasSignatures)->toBeTrue()
        ->and($inspection->signatureCount)->toBe(1)
        ->and($inspection->signatureFields)->toBe(['AssinaVelox']);

    // Com o certificado de teste como raiz confiável: íntegra, válida e confiável.
    $validation = $client->validate($path, [$this->certificate['pem']]);

    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($validation->allTrusted())->toBeTrue()
        ->and($validation->revocation)->toBe('not_checked')
        ->and($validation->signatures[0]->subfilter)->toBe('/ETSI.CAdES.detached')
        ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_FILE')
        ->and($validation->signatures[0]->signerSubject)->toContain('TESTE');

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($record->signature_status)->toBe(SignatureStatus::CompanyA1)
        ->and($record->signature_profile)->toBe('PAdES-B-B')
        ->and($record->certificate_reference_id)->not->toBeNull()
        ->and($record->final_sha256)->toBe(hash_file('sha256', $path))
        ->and($record->validation_result['signed'])->toBeTrue()
        ->and($record->validation_result['profile'])->toBe('PAdES-B-B')
        ->and($record->validation_result['environment'])->toBe('test')
        // O que NÃO é afirmado, dito explicitamente.
        ->and($record->validation_result['timestamp'])->toBeNull()
        ->and($record->validation_result['long_term_validation'])->toBeFalse()
        ->and($record->validation_result['revocation'])->toBe('not_checked')
        ->and($record->validation_result['result']['all_intact'])->toBeTrue()
        ->and($record->validation_result['result']['all_valid'])->toBeTrue();

    $certificate = CertificateReference::query()->findOrFail($record->certificate_reference_id);

    expect($certificate->environment)->toBe(CertificateEnvironment::Test)
        ->and($certificate->organization_id)->toBeNull()
        ->and($certificate->secret_ref)->toBe(FINALIZATION_CERT_PASS_ENV)
        ->and($certificate->fingerprint_sha256)->toBe($this->certificate['raw']['cert_fingerprint_sha256'])
        // O registro guarda o NOME da variável, jamais o valor da senha.
        ->and($certificate->getAttributes())->not->toContain('senha-de-teste-Xk93!');
});

it('rotula o certificado de teste como teste na página de evidências, nunca como ICP-Brasil', function () {
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);

    $scenario = finalizationEnvelope($this->work);
    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $evidence = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Evidence->value)
        ->firstOrFail();

    // O DOMPDF quebra linhas onde couber; para asserções sobre frases, o espaço em branco
    // é normalizado (o que se verifica é o texto, não a paginação).
    $text = (string) preg_replace('/\s+/u', ' ', implode(' ', array_column(
        finalizationPdfPages(finalizationDownload($evidence, $this->work.'/evidencias.pdf')),
        'text',
    )));

    expect($text)->toContain('PAdES-B-B')
        ->and($text)->toContain('Certificado de ambiente de teste')
        ->and($text)->toContain('titularidade')
        // "ICP-Brasil" aparece uma única vez, e é para NEGAR.
        ->and(substr_count($text, 'ICP-Brasil'))->toBe(1)
        ->and($text)->toContain('não é ICP-Brasil')
        ->and($text)->toContain('não é a assinatura pessoal')
        ->and($text)->not->toContain('assinado digitalmente por');
});

it('não conclui o envelope quando a assinatura falha', function () {
    TestCertificate::configure($this->certificate);

    // Signer configurado que falha ao assinar: o pipeline não pode concluir nada.
    app()->instance(PdfSigner::class, new class implements PdfSigner
    {
        public function isConfigured(): bool
        {
            return true;
        }

        public function sign(SignRequest $request): SignResult
        {
            throw new RuntimeException('pdftool sign falhou [signing_failed]: recusado pelo pyHanko.');
        }

        public function validate(string $pdfPath, array $trustRoots = []): ValidationResult
        {
            return ValidationResult::fromArray(['signature_count' => 0, 'all_intact' => false, 'all_valid' => false]);
        }
    });

    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    expect(fn () => finalizationRun($envelope))
        ->toThrow(FinalizationException::class);

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Finalizing)
        ->and($envelope->final_document_version_id)->toBeNull()
        ->and($envelope->completed_at)->toBeNull()
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeFalse()
        ->and(DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $scenario['document']->getKey())
            ->where('kind', DocumentVersionKind::Final->value)
            ->exists())->toBeFalse();

    // As etapas anteriores ficaram prontas — a retomada não precisa refazê-las.
    expect(DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->whereIn('kind', ['consolidated', 'evidence'])
        ->count())->toBe(2);
});

it('não conclui quando o arquivo assinado não pode ser validado', function () {
    TestCertificate::configure($this->certificate);

    $real = app(PyHankoSigner::class);

    app()->instance(PdfSigner::class, new class($real) implements PdfSigner
    {
        public function __construct(private readonly PyHankoSigner $inner) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function sign(SignRequest $request): SignResult
        {
            return $this->inner->sign($request);
        }

        /** Validador que não confirma nada: um "assinado" não verificável não é publicável. */
        public function validate(string $pdfPath, array $trustRoots = []): ValidationResult
        {
            return ValidationResult::fromArray(['signature_count' => 0, 'all_intact' => false, 'all_valid' => false]);
        }
    });

    $scenario = finalizationEnvelope($this->work);

    expect(fn () => finalizationRun($scenario['envelope']))
        ->toThrow(FinalizationException::class);

    expect($scenario['envelope']->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(VerificationRecord::query()->where('envelope_id', $scenario['envelope']->getKey())->exists())->toBeFalse();
});

it('conclui sem nenhuma assinatura no PDF quando o certificado é desligado', function () {
    config()->set('pdftool.company_certificate.enabled', false);

    expect(app(PdfSigner::class)->isConfigured())->toBeFalse();

    $scenario = finalizationEnvelope($this->work);
    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $path = finalizationDownload($envelope->finalVersion, $this->work.'/sem-assinatura.pdf');

    $inspection = app(PdfToolClient::class)->inspect($path);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($inspection->hasSignatures)->toBeFalse()
        ->and($inspection->signatureCount)->toBe(0)
        ->and($inspection->signatureFields)->toBe([]);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($record->signature_status)->toBe(SignatureStatus::None)
        ->and($record->signature_profile)->toBeNull()
        ->and($record->validation_result['result'])->toBeNull();
});
