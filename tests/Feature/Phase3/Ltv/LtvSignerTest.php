<?php

use App\Services\Ltv\LtvSigner;
use App\Services\Ltv\LtvStatus;
use App\Services\Ltv\Models\LtvOperation;
use App\Services\Pdf\PdfToolClient;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\Models\TimestampToken;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/LtvHelpers.php';

/*
|--------------------------------------------------------------------------
| P3-LTV — assinatura B-T / B-LT / B-LTA com a TSA da operadora (pdftool real)
|--------------------------------------------------------------------------
| O perfil DECLARADO continua PAdES-B-B (T2); o nível técnico sai em `ltv_status`. A
| degradação é explícita e registrada (R5). Nenhum carimbo é ICP-Brasil (T3).
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    $this->pki = ltvSetup($this->work);
    $this->source = PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'in.pdf');
});

afterEach(function () {
    putenv('P3LTV_WRONG_PASSWORD');
    ltvCleanup($this->work ?? null);
});

it('assina B-LTA com a TSA da operadora e o perfil declarado continua PAdES-B-B', function () {
    $out = $this->work.DIRECTORY_SEPARATOR.'lta.pdf';

    $signed = app(LtvSigner::class)->sign($this->source, $out, $this->pki['signer_pfx'], LTV_PASS_ENV);

    expect($signed['declared_profile'])->toBe('PAdES-B-B')
        ->and($signed['ltv_status'])->toBe(LtvStatus::BLta)
        ->and($signed['effective_level'])->toBe('B-LTA')
        ->and($signed['degraded'])->toBeFalse()
        ->and($signed['report']['announced_profile'])->toBe('PAdES-B-B')
        ->and($signed['report']['announced'])->toBeFalse()
        ->and($signed['report']['icp_brasil'])->toBeFalse()
        ->and($signed['report']['tsa_kind'])->toBe('operator');

    foreach ($signed['report']['timestamps'] as $stamp) {
        expect($stamp['label'])->toContain('não é carimbo ICP-Brasil')
            ->and($stamp['announced'])->toBeFalse();
    }

    // Um serial por carimbo, na ordem: assinatura e documento; ambos concedidos.
    $issuances = OperatorTsaIssuance::query()->orderBy('id')->get();
    expect($issuances->pluck('purpose')->all())->toBe(['ltv_signature', 'ltv_document'])
        ->and($issuances->pluck('status')->unique()->all())->toBe([OperatorTsaIssuance::STATUS_GRANTED])
        ->and($issuances->pluck('serial')->all())->toBe($signed['report']['serials_used']);

    $operation = $signed['operation'];
    expect($operation->kind)->toBe(LtvOperation::KIND_SIGN)
        ->and($operation->status)->toBe(LtvOperation::STATUS_COMPLETED)
        ->and($operation->effective_level)->toBe('B-LTA')
        ->and($operation->degradations)->toBe([]);

    // Conferência independente do caminho de assinatura: `validate` (B-B) e `ltv-validate`.
    $validation = app(PdfToolClient::class)->validate($out, [$this->pki['root_pem']]);
    expect($validation->allValid)->toBeTrue();

    $report = ltvValidate($out, $this->pki);
    expect($report['effective_level'])->toBe('B-LTA')
        ->and($report['dss']['present'])->toBeTrue()
        ->and($report['signatures'][0]['vri_present'])->toBeTrue()
        ->and($report['document_timestamp_count'])->toBe(1)
        ->and($report['signatures'][0]['policy_conformance'])->toBe('not_checked');

    // Nada vira ICP-Brasil.
    expect(TimestampToken::query()->where('tsa_kind', 'icp_brasil')->count())->toBe(0);
});

it('sem CRL a degradação é explícita: fica B-T, registra e marca o serial que sobrou', function () {
    config()->set('assinavelox.ltv.crl_paths', []);
    $out = $this->work.DIRECTORY_SEPARATOR.'bt.pdf';

    $signed = app(LtvSigner::class)->sign($this->source, $out, $this->pki['signer_pfx'], LTV_PASS_ENV);

    expect($signed['effective_level'])->toBe('B-T')
        ->and($signed['ltv_status'])->toBe(LtvStatus::BT)
        ->and($signed['declared_profile'])->toBe('PAdES-B-B')
        ->and($signed['degraded'])->toBeTrue()
        ->and($signed['degradations'][0]['step'])->toBe('validation_info');

    $operation = LtvOperation::query()->sole();
    expect($operation->status)->toBe(LtvOperation::STATUS_DEGRADED)
        ->and($operation->requested_level)->toBe('B-LTA')
        ->and($operation->effective_level)->toBe('B-T')
        ->and($operation->serials['unused'])->toHaveCount(1);

    $issuances = OperatorTsaIssuance::query()->orderBy('id')->get();
    expect($issuances[0]->status)->toBe(OperatorTsaIssuance::STATUS_GRANTED)
        ->and($issuances[1]->status)->toBe(OperatorTsaIssuance::STATUS_FAILED)
        ->and($issuances[1]->fail_info)->toBe('not_used');

    expect(ltvValidate($out, $this->pki)['effective_level'])->toBe('B-T');
});

it('sem raízes de confiança pede só B-T e registra o motivo', function () {
    config()->set('assinavelox.ltv.trust_roots', []);
    config()->set('assinavelox.tsa.trust_roots', []);
    config()->set('pdftool.trust_roots', []);

    $signed = app(LtvSigner::class)->sign($this->source, $this->work.DIRECTORY_SEPARATOR.'o.pdf', $this->pki['signer_pfx'], LTV_PASS_ENV);

    expect($signed['effective_level'])->toBe('B-T')
        ->and($signed['degradations'][0]['code'])->toBe('trust_roots_not_configured')
        ->and(OperatorTsaIssuance::query()->count())->toBe(1);
});

it('senha errada falha sem vazar a senha e sem deixar serial reservado', function () {
    putenv('P3LTV_WRONG_PASSWORD=senha-errada-nao-vaza-91');

    try {
        app(LtvSigner::class)->sign($this->source, $this->work.DIRECTORY_SEPARATOR.'x.pdf', $this->pki['signer_pfx'], 'P3LTV_WRONG_PASSWORD');
        $this->fail('Deveria ter falhado.');
    } catch (TsaException $exception) {
        expect($exception->getMessage())->not->toContain('senha-errada-nao-vaza-91')
            ->and($exception->getMessage())->not->toContain(LTV_PASSWORD);
    }

    expect(OperatorTsaIssuance::query()->where('status', OperatorTsaIssuance::STATUS_RESERVED)->count())->toBe(0)
        ->and(LtvOperation::query()->count())->toBe(0);
});
