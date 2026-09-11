<?php

use App\Models\Envelope;
use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\TimestampEvidence;
use App\Services\Timestamp\TimestampTokens;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Phase2/Timestamp/Support/TimestampHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C — custódia e criptografia
|--------------------------------------------------------------------------
| O pdftool sabe que o certificado da TSA é de TESTE (`tsa_certificate_test`, CN com "TESTE")
| e `IssuedTimestamp::$testCertificate` carrega isso. Mas `TimestampTokens::recordOperator()`
| não grava o fato, e `TimestampToken::isTest()` decide só por `environment !== 'production'`
| (linha 110). Com `ASSINAVELOX_TSA_ENVIRONMENT=production` e a TSA de teste (a do seeder, a do
| `tsa:generate-test`), a página de evidências e a verificação pública mostram o carimbo SEM o
| aviso "TSA de TESTE". O próprio dossiê usa a regra certa (`testCertificate || environment !==
| 'production'`), então as duas telas e o ZIP se contradizem. Nada recusa a TSA de teste em
| produção (`OperatorTsaConfig::missing()` e `tsa:status` só avisam do OID de exemplo).
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

it('carimbo de TSA com certificado de TESTE é sempre exibido como teste, mesmo com environment=production', function () {
    ktsaConfigureTsa($this->work);
    config()->set('assinavelox.tsa.environment', 'production');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create();

    $issued = app(OperatorTsa::class)->stampDigest(hash('sha256', 'resumo'), 'sha256', 'provider', $organization->id);

    // O pdftool reconheceu o certificado de TESTE.
    expect($issued->testCertificate)->toBeTrue();

    app(TimestampTokens::class)->recordOperator($organization->id, $issued, TimestampToken::PURPOSE_SIGNATURE, $envelope);

    $evidence = TimestampEvidence::forEnvelope($envelope)['items'][0];
    $public = TimestampEvidence::publicProps($envelope)['timestamps'][0];

    expect($evidence['is_test'])->toBeTrue()
        ->and($evidence['test_notice'])->toBe(TimestampEvidence::TEST_NOTICE)
        ->and($public['is_test'])->toBeTrue();
});
