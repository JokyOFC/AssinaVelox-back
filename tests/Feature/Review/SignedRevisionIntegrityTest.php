<?php

use App\Enums\CertificateEnvironment;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Integrations\Pdf\PyHankoSigner;
use App\Models\CertificateReference;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Envelopes\Finalization\OperatorSignature;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — conteúdo fora da revisão assinada e hash publicado
|--------------------------------------------------------------------------
| Dois defeitos que apareciam juntos no caminho de RETOMADA da finalização (o mesmo
| exercitado por `FinalizationIdempotencyTest::retoma quando o arquivo final já existe mas o
| banco não foi atualizado`), quando os bytes do `final` no disco mudaram entre a queda e a
| retentativa:
|
| 1. `FinalizationArtifacts::existing()` só perguntava se o arquivo EXISTIA no disco
|    (`$this->storage->exists($version)`); nunca reconferia o `sha256` gravado na linha. O
|    `VerificationRecord` então publicava o `final_sha256` da linha antiga, que não é o resumo
|    dos bytes que o download entrega — a plataforma acusando de adulterado o próprio arquivo
|    que está servindo.
|
| 2. `pdftool validate` devolvia `all_intact`/`all_valid` sem olhar para `coverage`. Um PDF
|    assinado com bytes acrescentados DEPOIS da revisão assinada continua
|    `intact = true, valid = true, trusted = true`; a única pista é `coverage = ENTIRE_REVISION`
|    (em vez de `ENTIRE_FILE`). Nem `OperatorSignature::signAndValidate()`, nem
|    `EnvelopeFinalizer::recoverSignatureState()`, nem `SignatureNarrative::validation()` liam
|    esse campo — e a página pública publicava "Íntegro na conclusão: a validação não encontrou
|    alteração no arquivo depois da assinatura".
|
| As duas correções são barreiras independentes, e é por isso que os dois cenários abaixo são
| diferentes:
|
| - com o resumo da linha íntegro, a barreira do sha256 pega o arquivo alterado primeiro:
|   o artefato é descartado e refeito, e o resumo publicado volta a identificar os bytes
|   entregues (primeiro teste);
| - quando a alteração também acerta a coluna `sha256` — bucket adulterado com acesso ao
|   banco, restauração inconsistente —, quem tem de segurar é a leitura de `coverage`
|   (segundo teste).
|
| O acréscimo usado aqui é um comentário PDF no fim do arquivo: bytes reais, fora da revisão
| coberta pela assinatura, que o pyHanko reconhece como tal.
*/

const REVIEW_COVERAGE_CERT_PASS_ENV = 'REVIEW_COVERAGE_CERT_PASS';

/** Bytes acrescentados depois da revisão assinada. */
const REVIEW_TRAILING_BYTES = "\n% conteudo fora da revisao assinada\n";

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    putenv(REVIEW_COVERAGE_CERT_PASS_ENV.'=senha-de-revisao-Vt77!');

    $this->certificate = TestCertificate::generate(
        $this->work.DIRECTORY_SEPARATOR.'certs',
        REVIEW_COVERAGE_CERT_PASS_ENV,
        TestCertificate::SUBJECT,
        2,
    );

    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);
});

afterEach(function () {
    putenv(REVIEW_COVERAGE_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

/**
 * Conclui um envelope, volta-o para `finalizing` (queda antes de gravar o registro) e
 * acrescenta bytes ao arquivo final já gravado no disco.
 *
 * `$fixDigest` decide se a alteração também acerta a coluna `sha256` da versão — isto é, se
 * a barreira do resumo consegue ou não perceber a troca.
 *
 * @return array{envelope: Envelope, sha_before: string, sha_after: string, final_id: int}
 */
function reviewTamperedResume(string $work, bool $fixDigest): array
{
    $scenario = finalizationEnvelope($work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);
    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $finalVersion = $envelope->finalVersion;
    $shaBefore = (string) VerificationRecord::query()
        ->where('envelope_id', $envelope->getKey())
        ->value('final_sha256');

    // Queda depois de gravar o arquivo, antes de publicar o registro.
    VerificationRecord::query()->where('envelope_id', $envelope->getKey())->delete();
    $envelope->forceFill([
        'status' => EnvelopeStatus::Finalizing,
        'completed_at' => null,
        'final_document_version_id' => null,
        'settings' => [],
    ])->save();

    // Bytes acrescentados ao arquivo final já persistido, fora da revisão assinada.
    $disk = Storage::disk('documents');
    $disk->put($finalVersion->storage_path, (string) $disk->get($finalVersion->storage_path).REVIEW_TRAILING_BYTES);

    $shaAfter = hash('sha256', (string) $disk->get($finalVersion->storage_path));

    expect($shaAfter)->not->toBe($shaBefore);

    if ($fixDigest) {
        $finalVersion->forceFill(['sha256' => $shaAfter, 'size_bytes' => strlen((string) $disk->get($finalVersion->storage_path))])->save();
    }

    return [
        'envelope' => $envelope->refresh(),
        'sha_before' => $shaBefore,
        'sha_after' => $shaAfter,
        'final_id' => (int) $finalVersion->getKey(),
    ];
}

it('publica um final_sha256 que é o resumo dos bytes que o download entrega', function () {
    ['envelope' => $envelope, 'sha_before' => $shaBefore, 'sha_after' => $shaAfter] =
        reviewTamperedResume($this->work, fixDigest: false);

    finalizationRun($envelope);
    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'entregue.pdf');
    $served = (string) hash_file('sha256', $path);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole();

    // A invariante: o resumo publicado identifica exatamente os bytes que a plataforma
    // entrega. É esta comparação que a conferência "Conferir meu arquivo" faz.
    expect($record->final_sha256)->toBe(
        $served,
        'verification_records.final_sha256 não é o resumo do arquivo final servido.',
    );

    // E o arquivo entregue não é nem o resumo antigo republicado às cegas, nem os bytes
    // alterados: o artefato incoerente foi descartado e refeito.
    expect($served)->not->toBe($shaAfter)
        ->and($record->final_sha256)->not->toBe($shaBefore);
});

it('não afirma integridade quando a assinatura não cobre o arquivo inteiro', function () {
    ['envelope' => $envelope, 'final_id' => $finalId] = reviewTamperedResume($this->work, fixDigest: true);

    // Controle: o pyHanko continua dizendo íntegra e válida — e é justamente esse o ponto.
    // O que denuncia o acréscimo é a cobertura.
    $tampered = finalizationDownload(
        DocumentVersion::withoutOrganizationScope()->findOrFail($finalId),
        $this->work.DIRECTORY_SEPARATOR.'adulterado.pdf',
    );

    $validation = app(PdfToolClient::class)->validate($tampered, [$this->certificate['pem']]);

    expect($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_REVISION')
        ->and($validation->allCovering)->toBeFalse();

    // A retomada não pode publicar isso como assinado: a exceção sobe, o envelope fica em
    // `finalizing` para inspeção e nada é publicado.
    expect(fn () => finalizationRun($envelope))->toThrow(FinalizationException::class);

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeFalse();
});

it('a página pública não chama de íntegro um resultado com conteúdo fora da revisão assinada', function () {
    ['envelope' => $envelope, 'final_id' => $finalId, 'sha_after' => $shaAfter] =
        reviewTamperedResume($this->work, fixDigest: true);

    $tampered = finalizationDownload(
        DocumentVersion::withoutOrganizationScope()->findOrFail($finalId),
        $this->work.DIRECTORY_SEPARATOR.'adulterado.pdf',
    );

    // Resultado técnico REAL do arquivo com o acréscimo, gravado na forma que a finalização
    // grava. É o que uma linha antiga — de antes da barreira de cobertura — carregaria.
    $payload = app(OperatorSignature::class)->validationPayload(
        SignatureStatus::CompanyA1,
        app(PdfToolClient::class)->validate($tampered, [$this->certificate['pem']]),
        PyHankoSigner::PROFILE,
        CertificateEnvironment::Test,
    );

    VerificationRecord::query()->create([
        'code' => (string) $envelope->verification_code,
        'envelope_id' => $envelope->getKey(),
        'organization_id' => $envelope->organization_id,
        'final_document_version_id' => $finalId,
        'original_sha256' => str_repeat('a', 64),
        'sent_sha256' => str_repeat('b', 64),
        'consolidated_sha256' => str_repeat('c', 64),
        'final_sha256' => $shaAfter,
        'signature_status' => SignatureStatus::CompanyA1,
        'signature_profile' => PyHankoSigner::PROFILE,
        'certificate_reference_id' => CertificateReference::query()->value('id'),
        'validation_result' => $payload,
        'validated_at' => now(),
    ]);

    $envelope->forceFill([
        'status' => EnvelopeStatus::Completed,
        'completed_at' => now(),
        'final_document_version_id' => $finalId,
    ])->save();

    $public = $this->get(route('verify.show', ['code' => $envelope->verification_code]));
    $public->assertOk();

    /** @var array<string, mixed> $result */
    $result = $public->viewData('page')['props']['result'];

    expect($result['validation']['integrity'])->not->toBe(
        'intact',
        'A página pública afirma integridade com conteúdo fora da revisão assinada.',
    );

    expect(mb_strtolower((string) $result['validation']['integrity_label']))
        ->not->toContain('não encontrou alteração no arquivo depois da assinatura');

    // E o marco da linha do tempo também não pode dizer "validada".
    $labels = implode(' | ', array_column($result['events_summary'], 'label'));

    expect($labels)->not->toContain('validada');
});
