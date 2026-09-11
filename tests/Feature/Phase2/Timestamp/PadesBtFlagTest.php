<?php

use App\Enums\EnvelopeStatus;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\PadesBtSigner;
use App\Services\Timestamp\PadesProfilePolicy;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/Support/TimestampHelpers.php';

/*
|--------------------------------------------------------------------------
| PAdES com carimbo da operadora (flag `pades_bt`) — o perfil ANUNCIADO continua B-B
|--------------------------------------------------------------------------
| Roadmap T2: B-T só é anunciado depois de validação externa independente
| (PadesProfilePolicy::checklist()). Até lá o carimbo existe tecnicamente, é rotulado como
| da operadora, e nem a interface nem `signature_profile` mudam.
*/

const KTSA_CERT_PASS_ENV = 'KTSA_TEST_COMPANY_CERT_PASS';

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    putenv(KTSA_CERT_PASS_ENV.'=senha-cert-teste-K7!');
    $this->certificate = TestCertificate::generate($this->work.DIRECTORY_SEPARATOR.'certs', KTSA_CERT_PASS_ENV, TestCertificate::SUBJECT, 2);
});

afterEach(function () {
    putenv(KTSA_CERT_PASS_ENV);
    ktsaCleanup($this->work ?? null);
});

it('com pades_bt desligada o assinador com carimbo recusa e não gasta serial', function () {
    ktsaConfigureTsa($this->work);
    config()->set('assinavelox.features.pades_bt', false);

    $source = PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'in.pdf');

    expect(fn () => app(PadesBtSigner::class)->sign($source, $this->work.DIRECTORY_SEPARATOR.'out.pdf', $this->certificate['pfx'], KTSA_CERT_PASS_ENV))
        ->toThrow(TsaUnavailableException::class);

    expect(OperatorTsaIssuance::query()->count())->toBe(0);
});

it('pades_bt não liga sozinha: exige operator_tsa', function () {
    ktsaConfigureTsa($this->work, enableFlag: false);
    config()->set('assinavelox.features.pades_bt', true);

    expect(app(PadesBtSigner::class)->isAvailable())->toBeFalse();
});

it('com pades_bt ligada embute o carimbo da operadora e o perfil anunciado continua PAdES-B-B', function () {
    ktsaConfigureTsa($this->work);
    config()->set('assinavelox.features.pades_bt', true);

    $source = PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'in.pdf');
    $out = $this->work.DIRECTORY_SEPARATOR.'out.pdf';

    $signed = app(PadesBtSigner::class)->sign($source, $out, $this->certificate['pfx'], KTSA_CERT_PASS_ENV);

    expect($signed['declared_profile'])->toBe('PAdES-B-B')
        ->and($signed['result']['profile'])->toBe('PAdES-B-B')
        ->and($signed['signature_timestamp']['tsa_kind'])->toBe('operator')
        ->and($signed['signature_timestamp']['announced'])->toBeFalse()
        ->and($signed['signature_timestamp']['label'])->toContain('não é carimbo ICP-Brasil');

    $validation = app(PdfToolClient::class)->validate($out, [$this->certificate['pem']]);

    expect($validation->allValid)->toBeTrue()
        ->and($validation->allCovering)->toBeTrue();

    expect(OperatorTsaIssuance::query()->where('purpose', 'signature')->value('serial'))->toBe($signed['signature_timestamp']['serial']);
    expect(PadesProfilePolicy::declaredProfile())->toBe('PAdES-B-B');
    expect(collect(PadesProfilePolicy::checklist())->pluck('status'))->toContain('pendente');
});

it('finalizar com pades_bt ligada não muda o signature_profile exibido', function () {
    Notification::fake();
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    ktsaConfigureTsa($this->work);
    config()->set('assinavelox.features.pades_bt', true);
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);

    $scenario = finalizationEnvelope($this->work);
    finalizationRun($scenario['envelope']);
    $envelope = $scenario['envelope']->refresh();
    $record = $envelope->verificationRecord;

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($record->signature_profile)->toBe('PAdES-B-B')
        ->and(SignatureNarrative::for($envelope, $record)['profile'])->toBe('PAdES-B-B');
});
