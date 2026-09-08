<?php

use App\Enums\CertificateEnvironment;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
use App\Integrations\Exceptions\SignerNotConfiguredException;
use App\Integrations\IntegrationsServiceProvider;
use App\Integrations\Pdf\NullPdfSigner;
use App\Integrations\Pdf\PyHankoSigner;
use App\Services\Pdf\Dto\SignOptions;
use App\Services\Pdf\Dto\VisibleStamp;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\Exceptions\PdfToolUsageException;
use App\Services\Pdf\PdfToolClient;
use Psr\Log\AbstractLogger;
use Tests\Feature\Pdf\Support\PdfFixtures;

const PDFTEST_PASSPHRASE = 'senha-de-teste-Xk93!';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->app->register(IntegrationsServiceProvider::class);
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    $this->client = app(PdfToolClient::class);

    // A passphrase existe apenas no ambiente do PHP, sob um nome; nunca em config/argv.
    putenv('PDFTEST_CERT_PASS='.PDFTEST_PASSPHRASE);

    $this->pfx = $this->work.'/teste.pfx';
    $this->pem = $this->work.'/teste.pem';
    $this->certificate = $this->client->generateTestCertificate($this->pfx, 'PDFTEST_CERT_PASS', 'CN=AssinaVelox TESTE,O=AssinaVelox,C=BR', 2, $this->pem);

    config()->set('pdftool.company_certificate', [
        'enabled' => true,
        'pfx_path' => $this->pfx,
        'password_env' => 'PDFTEST_CERT_PASS',
        'environment' => 'test',
        'name' => 'Certificado de teste',
        'reason' => 'Teste automatizado',
        'location' => 'Brasil',
        'field_name' => 'AssinaVelox',
    ]);
    config()->set('pdftool.trust_roots', []);

    $this->source = PdfFixtures::twoPagePdf($this->work.'/consolidado.pdf');
});

afterEach(function () {
    putenv('PDFTEST_CERT_PASS');
    putenv('PDFTEST_OTHER_SECRET');
    putenv('PDFTEST_WRONG_PASS');
    PdfFixtures::cleanup($this->work ?? null);
});

it('gera um certificado autoassinado rotulado como TESTE', function () {
    expect($this->certificate['test_only'])->toBeTrue()
        ->and($this->certificate['self_signed'])->toBeTrue()
        ->and($this->certificate['subject'])->toContain('TESTE')
        ->and(is_file($this->pfx))->toBeTrue()
        ->and(is_file($this->pem))->toBeTrue()
        ->and(file_get_contents($this->pem))->toContain('BEGIN CERTIFICATE');
});

it('assina com PyHankoSigner e valida com e sem raiz de confiança', function () {
    $signer = app(PdfSigner::class);
    expect($signer)->toBeInstanceOf(PyHankoSigner::class)
        ->and($signer->isConfigured())->toBeTrue();

    $out = $this->work.'/assinado.pdf';
    $result = $signer->sign(new SignRequest($this->source, $out, reason: 'Assinatura eletrônica de teste'));

    expect($result->profile)->toBe('PAdES-B-B')
        ->and($result->fieldName)->toBe('AssinaVelox')
        ->and($result->mdAlgorithm)->toBe('sha256')
        ->and($result->timestamp)->toBeNull()
        ->and($result->hasTimestamp())->toBeFalse()
        ->and($result->visible)->toBeFalse()
        ->and($result->pageCount)->toBe(2)
        ->and($result->environment)->toBe(CertificateEnvironment::Test)
        ->and($result->isTestCertificate())->toBeTrue()
        ->and($result->signerSubject)->toContain('TESTE')
        ->and($result->certFingerprintSha256)->toBe($this->certificate['cert_fingerprint_sha256'])
        ->and($result->outputPath)->toBe($out)
        ->and(is_file($out))->toBeTrue();

    $inspection = $this->client->inspect($out);
    expect($inspection->hasSignatures)->toBeTrue()
        ->and($inspection->signatureCount)->toBe(1)
        ->and($inspection->signatureFields)->toBe(['AssinaVelox'])
        ->and($inspection->pageCount)->toBe(2)
        ->and($inspection->isBlockedForPreparation())->toBeTrue();

    // Com a raiz (o próprio certificado de teste em PEM): confiável.
    $trusted = $signer->validate($out, [$this->pem]);
    expect($trusted->signatureCount)->toBe(1)
        ->and($trusted->allIntact)->toBeTrue()
        ->and($trusted->allValid)->toBeTrue()
        ->and($trusted->allTrusted())->toBeTrue()
        ->and($trusted->trustRootsConfigured)->toBe(1)
        ->and($trusted->revocation)->toBe('not_checked')
        ->and($trusted->signatures[0]->intact)->toBeTrue()
        ->and($trusted->signatures[0]->valid)->toBeTrue()
        ->and($trusted->signatures[0]->trusted)->toBeTrue()
        ->and($trusted->signatures[0]->trustReason)->toBeNull()
        ->and($trusted->signatures[0]->coverage)->toBe('ENTIRE_FILE')
        ->and($trusted->signatures[0]->subfilter)->toBe('/ETSI.CAdES.detached')
        ->and($trusted->signatures[0]->errors)->toBe([])
        ->and($trusted->summary()['all_trusted'])->toBeTrue();

    // Sem raízes: íntegra e válida, mas NUNCA confiável.
    $untrusted = $signer->validate($out);
    expect($untrusted->allIntact)->toBeTrue()
        ->and($untrusted->allValid)->toBeTrue()
        ->and($untrusted->allTrusted())->toBeFalse()
        ->and($untrusted->trustRootsConfigured)->toBe(0)
        ->and($untrusted->signatures[0]->trusted)->toBeFalse()
        ->and($untrusted->signatures[0]->trustReason)->toBe('no_trust_roots_configured');

    // Raízes vindas da configuração (PDFTOOL_TRUST_ROOTS).
    config()->set('pdftool.trust_roots', [$this->pem]);
    expect($signer->validate($out)->allTrusted())->toBeTrue();
});

it('assinar novamente preserva a primeira assinatura', function () {
    $signer = app(PyHankoSigner::class);
    $first = $this->work.'/assinado-1.pdf';
    $second = $this->work.'/assinado-2.pdf';

    $signer->sign(new SignRequest($this->source, $first));
    $result = $signer->sign(new SignRequest($first, $second));

    expect($result->fieldName)->toBe('AssinaVelox_2');

    $inspection = $this->client->inspect($second);
    expect($inspection->signatureCount)->toBe(2)
        ->and($inspection->signatureFields)->toBe(['AssinaVelox', 'AssinaVelox_2']);

    $validation = $signer->validate($second, [$this->pem]);
    expect($validation->signatureCount)->toBe(2)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($validation->allTrusted())->toBeTrue()
        ->and($validation->signatures[0]->fieldName)->toBe('AssinaVelox')
        ->and($validation->signatures[0]->intact)->toBeTrue()
        ->and($validation->signatures[1]->fieldName)->toBe('AssinaVelox_2')
        ->and($validation->signatures[1]->intact)->toBeTrue();
});

it('aplica carimbo visível quando solicitado', function () {
    $out = $this->work.'/assinado-visivel.pdf';

    $result = $this->client->sign(
        $this->source,
        $out,
        $this->pfx,
        'PDFTEST_CERT_PASS',
        new SignOptions(fieldName: 'Operadora', reason: 'Teste', location: 'Brasil', visible: new VisibleStamp(2, 0.55, 0.85, 0.40, 0.08)),
    );

    expect($result->visible)->toBeTrue()
        ->and($result->fieldName)->toBe('Operadora')
        ->and($this->client->validate($out, [$this->pem])->allTrusted())->toBeTrue();
});

it('não vaza a passphrase quando a variável está ausente', function () {
    putenv('PDFTEST_OTHER_SECRET=valor-super-secreto-xyz');
    $out = $this->work.'/nao-assinado.pdf';

    try {
        $this->client->sign($this->source, $out, $this->pfx, 'PDFTEST_VARIAVEL_INEXISTENTE');
        $this->fail('Esperava PdfToolUsageException.');
    } catch (PdfToolUsageException $exception) {
        $argv = implode(' ', $exception->command);

        expect($exception->errorCode)->toBe('missing_passphrase')
            ->and($exception->getMessage())->toContain('PDFTEST_VARIAVEL_INEXISTENTE')
            ->and($exception->getMessage())->not->toContain('valor-super-secreto-xyz')
            ->and($exception->getMessage())->not->toContain(PDFTEST_PASSPHRASE)
            ->and($argv)->toContain('--pass-env PDFTEST_VARIAVEL_INEXISTENTE')
            ->and($argv)->not->toContain('valor-super-secreto-xyz')
            ->and($argv)->not->toContain(PDFTEST_PASSPHRASE)
            ->and(is_file($out))->toBeFalse()
            ->and(glob($this->work.'/pdftool-tmp/*') ?: [])->toBe([]);
    }
});

it('não vaza a passphrase quando a senha está errada', function () {
    putenv('PDFTEST_WRONG_PASS=senha-errada-abc');
    $out = $this->work.'/nao-assinado.pdf';

    try {
        $this->client->sign($this->source, $out, $this->pfx, 'PDFTEST_WRONG_PASS');
        $this->fail('Esperava PdfToolInputRejectedException.');
    } catch (PdfToolInputRejectedException $exception) {
        $argv = implode(' ', $exception->command);

        expect($exception->errorCode)->toBe('pfx_load_failed')
            ->and($exception->exitCode)->toBe(PdfToolClient::EXIT_INPUT_REJECTED)
            ->and($exception->getMessage())->not->toContain('senha-errada-abc')
            ->and($exception->getMessage())->not->toContain(PDFTEST_PASSPHRASE)
            ->and($argv)->toContain('--pass-env PDFTEST_WRONG_PASS')
            ->and($argv)->not->toContain('senha-errada-abc')
            ->and((string) $exception->stderrExcerpt)->not->toContain('senha-errada-abc')
            ->and(is_file($out))->toBeFalse()
            ->and(glob($this->work.'/pdftool-tmp/*') ?: [])->toBe([]);
    }
});

it('nunca registra a senha em log — nem em sucesso, nem em senha errada', function () {
    putenv('PDFTEST_WRONG_PASS=senha-errada-abc');

    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };
    $client = new PdfToolClient(app('config'), $logger);

    $client->sign($this->source, $this->work.'/assinado-log.pdf', $this->pfx, 'PDFTEST_CERT_PASS');

    try {
        $client->sign($this->source, $this->work.'/nao-assinado-log.pdf', $this->pfx, 'PDFTEST_WRONG_PASS');
        $this->fail('Esperava PdfToolInputRejectedException.');
    } catch (PdfToolInputRejectedException) {
        // esperado
    }

    $serialized = json_encode($logger->records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect(count($logger->records))->toBeGreaterThanOrEqual(2)
        ->and($serialized)->toContain('--pass-env')
        ->and($serialized)->toContain('PDFTEST_CERT_PASS')
        ->and($serialized)->not->toContain(PDFTEST_PASSPHRASE)
        ->and($serialized)->not->toContain('senha-errada-abc');

    // O ambiente do processo filho não é registrado: nenhum registro traz chave "env".
    foreach ($logger->records as $record) {
        expect($record['context'])->not->toHaveKey('env');
    }
});

it('sem certificado o container resolve NullPdfSigner, que nunca assina', function () {
    config()->set('pdftool.company_certificate.pfx_path', null);

    $signer = app(PdfSigner::class);
    $out = $this->work.'/jamais-assinado.pdf';

    expect($signer)->toBeInstanceOf(NullPdfSigner::class)
        ->and($signer->isConfigured())->toBeFalse()
        ->and(fn () => $signer->sign(new SignRequest($this->source, $out)))->toThrow(SignerNotConfiguredException::class)
        ->and(is_file($out))->toBeFalse();

    // NullPdfSigner ainda valida (não exige certificado).
    expect($signer->validate($this->source)->signatureCount)->toBe(0);
});

it('com COMPANY_CERT_ENABLED=false o container resolve NullPdfSigner mesmo com PFX e senha válidos', function () {
    config()->set('pdftool.company_certificate.enabled', false);

    $pyhanko = app(PyHankoSigner::class);
    expect($pyhanko->isEnabled())->toBeFalse()
        ->and($pyhanko->isConfigured())->toBeFalse()
        ->and(implode(' ', $pyhanko->configurationProblems()))->toContain('COMPANY_CERT_ENABLED')
        ->and(app(PdfSigner::class))->toBeInstanceOf(NullPdfSigner::class);

    config()->set('pdftool.company_certificate.enabled', 'true');
    expect($pyhanko->isEnabled())->toBeTrue()
        ->and($pyhanko->isConfigured())->toBeTrue()
        ->and(app(PdfSigner::class))->toBeInstanceOf(PyHankoSigner::class);
});

it('PyHankoSigner sem PFX ou sem variável de senha não está configurado e lança', function () {
    $signer = app(PyHankoSigner::class);
    $out = $this->work.'/jamais-assinado.pdf';

    config()->set('pdftool.company_certificate.pfx_path', $this->work.'/inexistente.pfx');
    expect($signer->isConfigured())->toBeFalse()
        ->and($signer->configurationProblems())->toContain('Arquivo PKCS#12 não encontrado em COMPANY_CERT_PFX_PATH.')
        ->and(fn () => $signer->sign(new SignRequest($this->source, $out)))->toThrow(SignerNotConfiguredException::class);

    config()->set('pdftool.company_certificate.pfx_path', $this->pfx);
    config()->set('pdftool.company_certificate.password_env', 'PDFTEST_VARIAVEL_INEXISTENTE');
    expect($signer->isConfigured())->toBeFalse()
        ->and(implode(' ', $signer->configurationProblems()))->toContain('PDFTEST_VARIAVEL_INEXISTENTE')
        ->and(fn () => $signer->sign(new SignRequest($this->source, $out)))->toThrow(SignerNotConfiguredException::class)
        ->and(is_file($out))->toBeFalse();

    config()->set('pdftool.company_certificate.environment', 'staging');
    expect($signer->environment())->toBe(CertificateEnvironment::Test);
});

it('validar um PDF sem assinaturas não afirma integridade', function () {
    $validation = $this->client->validate($this->source);

    expect($validation->signatureCount)->toBe(0)
        ->and($validation->allIntact)->toBeFalse()
        ->and($validation->allValid)->toBeFalse()
        ->and($validation->allTrusted())->toBeFalse();
});
