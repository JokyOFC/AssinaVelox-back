<?php

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
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
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — a página de evidências promete a assinatura antes de ela existir
|--------------------------------------------------------------------------
| `EnvelopeFinalizer::generateEvidence()` monta o bloco "5. Sobre a assinatura criptográfica
| deste arquivo" a partir de `$willSign = $this->signature->isConfigured()` — a INTENÇÃO da
| execução, não o resultado. A página é gravada como `DocumentVersion(kind=evidence)` e, numa
| segunda execução do job, `FinalizationArtifacts::existing()` a REAPROVEITA sem conferir se
| a configuração de assinatura continua a mesma.
|
| `reusableFinal()` faz exatamente essa conferência para o arquivo `final` (e descarta o
| artefato quando `hasSignatures !== isConfigured()`). A etapa (b) não tem equivalente.
|
| Consequência: assinatura falha com o certificado ligado → o operador desliga o certificado
| para destravar → o envelope conclui com `signature_status = none` e ZERO assinaturas no
| arquivo, carregando dentro dele uma página que afirma "Este arquivo recebeu uma assinatura
| digital no perfil PAdES-B-B, aplicada pela operadora com certificado digital de sua própria
| titularidade".
*/

const REVIEW_EVIDENCE_CERT_PASS_ENV = 'REVIEW_EVIDENCE_CERT_PASS';

if (! function_exists('reviewPdfText')) {
    /** Todo o texto do PDF, em uma linha (mesma extração dos testes de ponta a ponta). */
    function reviewPdfText(string $path): string
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

    putenv(REVIEW_EVIDENCE_CERT_PASS_ENV.'=senha-de-revisao-Zq41!');

    $this->certificate = TestCertificate::generate(
        $this->work.DIRECTORY_SEPARATOR.'certs',
        REVIEW_EVIDENCE_CERT_PASS_ENV,
        TestCertificate::SUBJECT,
        2,
    );
});

afterEach(function () {
    putenv(REVIEW_EVIDENCE_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

it('não deixa o PDF final sem assinatura afirmar que recebeu assinatura digital', function () {
    // -- 1ª execução: certificado ligado, assinatura falha ---------------------------
    TestCertificate::configure($this->certificate);

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

    expect(fn () => finalizationRun($envelope))->toThrow(FinalizationException::class);

    // A página de evidências ficou pronta e persistida, prometendo a assinatura.
    $evidenceVersion = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Evidence->value)
        ->sole();

    // -- 2ª execução: o operador desliga o certificado para destravar o envelope ------
    config()->set('pdftool.company_certificate.enabled', false);
    app()->forgetInstance(PdfSigner::class);

    expect(app(PdfSigner::class)->isConfigured())->toBeFalse();

    finalizationRun($envelope);

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole();

    // O registro é honesto: nenhuma assinatura criptográfica.
    expect($record->signature_status)->toBe(SignatureStatus::None)
        ->and($record->signature_profile)->toBeNull();

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-reaproveitado.pdf');

    $inspection = app(PdfToolClient::class)->inspect($path);

    expect($inspection->hasSignatures)->toBeFalse()
        ->and($inspection->signatureCount)->toBe(0);

    // A página de evidências foi reaproveitada tal e qual (mesmos bytes da 1ª execução).
    expect($envelope->finalVersion->getKey())->not->toBe($evidenceVersion->getKey());

    $text = mb_strtolower(reviewPdfText($path));

    // Controle positivo: a página de evidências está mesmo dentro do arquivo lido.
    expect($text)->toContain('página de evidências do aceite eletrônico');

    // O arquivo não tem assinatura nenhuma: nada dentro dele pode afirmar que tem.
    // (Uma asserção por agulha: `not->toContain($a, $b)` passa se QUALQUER uma faltar.)
    expect($text)->not->toContain('recebeu uma assinatura digital');
    expect($text)->not->toContain('pades');
    expect($text)->not->toContain('certificado digital de sua própria titularidade');
});

it('imprime no relatório de evidências o resultado técnico da validação exigido pela declaração', function () {
    // Caminho feliz: certificado de teste real, assinatura aplicada e validada.
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);

    $scenario = finalizationEnvelope($this->work);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole();

    expect($record->signature_status)->toBe(SignatureStatus::CompanyA1)
        ->and($record->validation_result['result']['all_intact'])->toBeTrue();

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-assinado.pdf');
    $text = mb_strtolower(reviewPdfText($path));

    // Controle: é a variante `company_a1` do bloco 5.
    expect($text)->toContain('recebeu uma assinatura digital');

    // docs/juridico/declaracao-de-aceite.md §5.2 exige esta frase no relatório.
    expect($text)->toContain('resultado técnico da validação');
});
