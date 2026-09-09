<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — finalização: registro de verificação obsoleto
|--------------------------------------------------------------------------
|
| DEFEITO (crítico). `EnvelopeFinalizer::verificationRecord()` (linha 494) devolve o
| `VerificationRecord` existente SEM conferir se ele descreve o arquivo final desta
| execução:
|
|     $existing = VerificationRecord::query()->where('envelope_id', ...)->first();
|     if ($existing !== null) { $steps['verification_record'] = 'reused'; return $existing; }
|
| Mas a etapa imediatamente anterior, `reusableFinal()` (linha 403), DESCARTA o `final`
| já gravado quando ele não é coerente com a configuração de assinatura vigente
| (`$inspection->hasSignatures !== $this->signature->isConfigured()`) e o reconstrói do
| zero. Quando a finalização é retomada depois de uma queda ENTRE gravar o registro
| (transação da etapa f) e concluir o envelope (transação da etapa g), e nesse intervalo
| o certificado da operadora deixou de estar configurado, a segunda execução:
|
|   - reconstrói o `final` — desta vez SEM assinatura nenhuma;
|   - reaproveita o registro antigo, que diz `signature_status = company_a1`,
|     `signature_profile = PAdES-B-B` e carrega o `final_sha256` do arquivo descartado;
|   - conclui o envelope apontando para o arquivo NOVO.
|
| Consequências, todas publicadas por `PublicVerification::result()`
| (app/Services/Verification/PublicVerification.php, chaves `final_sha256` e
| `signature_status`):
|
|   1. A página pública afirma "assinado digitalmente pela operadora" sobre um PDF que
|      não tem nenhuma assinatura criptográfica — exatamente o que a arquitetura §2
|      proíbe ("Nunca simule assinatura criptográfica").
|   2. O `final_sha256` publicado é o de um arquivo que não existe mais: a conferência
|      por resumo do arquivo verdadeiro responde "Não confere".
|   3. `verification_records.final_document_version_id` fica órfão (a versão descartada
|      sai da tabela), enquanto `envelopes.final_document_version_id` aponta para outra.
|
| A queda é simulada de forma mecânica: um listener de `updating` do Envelope derruba a
| transação da etapa g na primeira vez que o status vira `completed` — precisamente o
| ponto em que um worker morto (timeout de 600 s, OOM, deploy) deixaria o envelope em
| `finalizing` com o registro já commitado.
*/

use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Integrations\Contracts\PdfSigner;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';

const REVIEW_STALE_CERT_PASS_ENV = 'REVIEW_STALE_TEST_CERT_PASS';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    putenv(REVIEW_STALE_CERT_PASS_ENV.'=senha-de-teste-Xk93!');

    $this->certificate = TestCertificate::generate(
        $this->work.DIRECTORY_SEPARATOR.'certs',
        REVIEW_STALE_CERT_PASS_ENV,
        TestCertificate::SUBJECT,
        2,
    );
});

afterEach(function () {
    putenv(REVIEW_STALE_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

/**
 * Deixa o envelope em `finalizing` com o VerificationRecord já gravado (queda entre a
 * etapa f e a etapa g), depois tira o certificado da operadora do ar e retoma.
 */
function reviewStaleFinalizeTwice(Envelope $envelope): void
{
    $crashOnce = true;

    Envelope::updating(function (Envelope $model) use (&$crashOnce): void {
        if ($crashOnce && $model->status === EnvelopeStatus::Completed) {
            $crashOnce = false;

            throw new RuntimeException('worker morto ao concluir');
        }
    });

    try {
        finalizationRun($envelope);
    } catch (Throwable) {
        // É a queda simulada; o job real deixaria isto para a retentativa.
    }

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeTrue();

    // Entre a queda e a retentativa o certificado da operadora sai do ar (venceu, foi
    // revogado, a variável de ambiente sumiu no deploy).
    config()->set('pdftool.company_certificate.enabled', false);
    app()->forgetInstance(PdfSigner::class);

    finalizationRun($envelope);

    $envelope->refresh();
}

it('não conclui afirmando assinatura da operadora sobre um arquivo sem assinatura nenhuma', function () {
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);

    $envelope = finalizationEnvelope($this->work)['envelope'];

    reviewStaleFinalizeTwice($envelope);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $inspection = app(PdfToolClient::class)->inspect($path);

    // O arquivo entregue não tem assinatura criptográfica.
    expect($inspection->hasSignatures)->toBeFalse()
        ->and($inspection->signatureCount)->toBe(0);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($record->signature_status)->toBe(
        SignatureStatus::None,
        'o registro de verificação afirma assinatura da operadora sobre um PDF sem assinatura',
    );

    expect($record->signature_profile)->toBeNull(
        'o registro publica um perfil PAdES para um arquivo que não foi assinado',
    );
});

it('publica o resumo e a versão do arquivo final que realmente foi entregue', function () {
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);

    $envelope = finalizationEnvelope($this->work)['envelope'];

    reviewStaleFinalizeTwice($envelope);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');

    // A conferência por resumo da página pública compara exatamente estes dois valores.
    expect($record->final_sha256)->toBe(
        hash_file('sha256', $path),
        'o resumo publicado não é o do arquivo final entregue: a conferência do arquivo verdadeiro diz "Não confere"',
    );

    expect((int) $record->final_document_version_id)->toBe(
        (int) $envelope->final_document_version_id,
        'o registro de verificação aponta para uma versão que não é a final do envelope',
    );
});
