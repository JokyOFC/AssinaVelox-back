<?php

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Timestamp\FakeIcpBrasilTimestampProvider;
use App\Integrations\Timestamp\IcpBrasilTimestampFactory;
use App\Integrations\Timestamp\IcpBrasilTimestampProvider;
use App\Integrations\Timestamp\OperatorTimestampProvider;
use App\Models\Envelope;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\TimestampEvidence;
use App\Services\Timestamp\TimestampTokens;
use App\Services\Timestamp\TimestampVerifier;
use App\Services\Timestamp\TsaKind;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/TimestampHelpers.php';

/*
|--------------------------------------------------------------------------
| TSA da operadora (RFC 3161) — provedor `operator` e contrato ICP-Brasil
|--------------------------------------------------------------------------
| Roda contra o pdftool real com uma TSA de TESTE. Regra T3 conferida em cada caminho:
| o carimbo da operadora é `operator` e o rótulo diz que não é ICP-Brasil; nada grava
| `icp_brasil` enquanto não houver ACT credenciada configurada.
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

it('emite pelo provedor operator e o token confere com o resumo e com a raiz da TSA', function () {
    ktsaConfigureTsa($this->work);
    $provider = app(OperatorTimestampProvider::class);
    $digest = hash('sha256', 'manifesto de teste');

    expect($provider->isConfigured())->toBeTrue()
        ->and($provider->isSimulated())->toBeFalse();

    $result = $provider->timestamp($digest);

    expect($result['tsa_kind'])->toBe('operator')
        ->and($result['simulated'])->toBeFalse()
        ->and($result['serial'])->toMatch('/^\d+$/');

    $verified = app(TimestampVerifier::class)->verify(base64_decode($result['token_der_base64']), $digest);

    expect($verified['valid'])->toBeTrue()
        ->and($verified['trusted'])->toBeTrue()
        ->and($verified['imprint_matches'])->toBeTrue();

    $other = app(TimestampVerifier::class)->verify(base64_decode($result['token_der_base64']), hash('sha256', 'outro conteúdo'));

    expect($other['valid'])->toBeFalse()
        ->and($other['trusted'])->toBeFalse()
        ->and($other['imprint_matches'])->toBeFalse();

    expect(OperatorTsaIssuance::query()->where('serial', $result['serial'])->value('status'))->toBe('granted');
});

it('rotula o carimbo como da operadora e nunca como ICP-Brasil, em banco e nas props', function () {
    ktsaConfigureTsa($this->work);
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create();

    $issued = app(OperatorTsa::class)->stampDigest(hash('sha256', 'x'), 'sha256', 'provider', $organization->id);
    $token = app(TimestampTokens::class)->recordOperator($organization->id, $issued, TimestampToken::PURPOSE_OTHER, $envelope);

    expect($token->tsa_kind)->toBe(TsaKind::Operator)
        ->and($token->environment)->toBe('test')
        ->and(TsaKind::Operator->label())->toBe('Carimbo do tempo da operadora — não é carimbo ICP-Brasil');

    $props = TimestampEvidence::forEnvelope($envelope);

    expect($props['items'])->toHaveCount(1)
        ->and($props['items'][0]['tsa_kind'])->toBe('operator')
        ->and($props['items'][0]['label'])->toContain('não é carimbo ICP-Brasil')
        ->and($props['items'][0]['is_test'])->toBeTrue()
        ->and($props['items'][0]['test_notice'])->not->toBeNull()
        ->and(json_encode($props))->not->toContain('"icp_brasil"');

    $public = TimestampEvidence::forPublic($envelope);

    expect($public[0])->toHaveKeys(['tsa_kind', 'label', 'purpose_label', 'gen_time', 'is_test'])
        ->and($public[0])->not->toHaveKey('tsa_cert_fingerprint');
});

it('com a flag desligada o provedor fica indisponível e nenhum serial é gasto', function () {
    ktsaConfigureTsa($this->work, enableFlag: false);
    $provider = app(OperatorTimestampProvider::class);

    expect($provider->isConfigured())->toBeFalse();

    expect(fn () => $provider->timestamp(hash('sha256', 'x')))->toThrow(TsaUnavailableException::class);
    expect(OperatorTsaIssuance::query()->count())->toBe(0);
});

it('sem configuração diz exatamente o que falta, sem segredo', function () {
    config()->set('assinavelox.features.operator_tsa', true);
    config()->set('assinavelox.tsa.pfx_path', null);

    try {
        app(OperatorTsa::class)->stampDigest(hash('sha256', 'x'));
        $this->fail('Deveria recusar.');
    } catch (TsaUnavailableException $exception) {
        expect($exception->reasons)->toContain('ASSINAVELOX_TSA_PFX_PATH não definido.');
    }
});

it('ICP-Brasil: produção desabilitada até o contrato com ACT', function () {
    $provider = app(IcpBrasilTimestampFactory::class)->make();

    expect($provider)->toBeInstanceOf(IcpBrasilTimestampProvider::class)
        ->and($provider->isConfigured())->toBeFalse();

    expect(fn () => $provider->timestamp(hash('sha256', 'x')))->toThrow(ProviderDisabledException::class);
});

it('o simulador ICP-Brasil nunca grava icp_brasil e ninguém mais consegue gravar', function () {
    config()->set('assinavelox.channels.allow_simulated', true);
    config()->set('assinavelox.tsa.icp_brasil.driver', 'fake');
    ['organization' => $organization] = createOrganizationWithOwner();
    $tokens = app(TimestampTokens::class);
    $digest = hash('sha256', 'y');

    $fake = app(IcpBrasilTimestampFactory::class)->make();
    expect($fake)->toBeInstanceOf(FakeIcpBrasilTimestampProvider::class);

    $result = $fake->timestamp($digest);
    expect($result['tsa_kind'])->toBe('simulated')
        ->and($result['simulated'])->toBeTrue()
        ->and($result['tsa'])->toContain('não é carimbo ICP-Brasil');

    $row = $tokens->recordFromProvider($organization->id, $fake, $result, $digest, TimestampToken::PURPOSE_OTHER);
    expect($row->tsa_kind)->toBe(TsaKind::Simulated);

    // Um resultado forjado como icp_brasil é recusado, venha do simulador ou de qualquer outro.
    $forged = [...$result, 'tsa_kind' => 'icp_brasil', 'simulated' => false];
    expect(fn () => $tokens->recordFromProvider($organization->id, $fake, $forged, $digest, 'other'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tokens->recordFromProvider($organization->id, new IcpBrasilTimestampProvider, $forged, $digest, 'other'))->toThrow(InvalidArgumentException::class);

    expect(TimestampToken::withoutOrganizationScope()->where('tsa_kind', 'icp_brasil')->count())->toBe(0);
});

it('nenhuma senha da TSA aparece no log nem na exceção', function () {
    $log = $this->work.DIRECTORY_SEPARATOR.'ktsa.log';
    config()->set('logging.channels.ktsa', ['driver' => 'single', 'path' => $log, 'level' => 'debug']);
    config()->set('logging.default', 'ktsa');

    ktsaConfigureTsa($this->work);
    app(OperatorTsa::class)->stampDigest(hash('sha256', 'z'));

    // Força um erro do pdftool com a mesma senha no ambiente (política recusada pela ferramenta).
    config()->set('assinavelox.tsa.policy_oid', '9.9');

    try {
        app(OperatorTsa::class)->stampDigest(hash('sha256', 'z'));
    } catch (Throwable $exception) {
        expect($exception->getMessage())->not->toContain(KTSA_TSA_PASSWORD);
    }

    $contents = is_file($log) ? (string) file_get_contents($log) : '';

    expect($contents)->toContain('tsa-issue')
        ->and($contents)->not->toContain(KTSA_TSA_PASSWORD);
});
