<?php

use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\TimestampVerifier;
use App\Services\Timestamp\TsaKind;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require_once __DIR__.'/Support/DossierHelpers.php';

/*
|--------------------------------------------------------------------------
| Carimbo da TSA da operadora sobre o manifesto do dossiê (flag `operator_tsa`)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    ['organization' => $this->organization, 'owner' => $this->owner] = ktsaDossierSetup($this->work);
    $this->envelope = ktsaCompletedEnvelope($this->organization, $this->owner, $this->work)['envelope'];
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

function ktsaBuildViaHttp(object $test): array
{
    actingAsMember($test->owner, $test->organization);
    $id = $test->postJson(route('envelopes.dossier.store', ['envelope' => $test->envelope->ulid]))->assertSuccessful()->json('export.id');
    $export = DossierExport::withoutOrganizationScope()->where('ulid', $id)->firstOrFail();
    $url = app(DossierExports::class)->statusProps($export)['download_url'];

    return [$export, ktsaZipEntries($test->get($url)->assertOk()->streamedContent(), $test->work)];
}

it('carimba o manifesto com a TSA da operadora e o token confere com os bytes do manifesto', function () {
    $tsa = ktsaConfigureTsa($this->work);

    [$export, $entries] = ktsaBuildViaHttp($this);
    $manifestSha = hash('sha256', $entries['manifest.json']);

    expect($entries)->toHaveKeys(['carimbo/manifesto.tsr', 'carimbo/carimbo.json', 'carimbo/cadeia-tsa.pem'])
        ->and($export->timestamp_status)->toBe('granted');

    $verified = app(TimestampVerifier::class)->verify($entries['carimbo/manifesto.tsr'], $manifestSha);
    expect($verified['valid'])->toBeTrue()->and($verified['trusted'])->toBeTrue();

    $tampered = app(TimestampVerifier::class)->verify($entries['carimbo/manifesto.tsr'], hash('sha256', $entries['manifest.json'].' '));
    expect($tampered['valid'])->toBeFalse();

    $meta = json_decode($entries['carimbo/carimbo.json'], true);
    expect($meta['tsa_kind'])->toBe('operator')
        ->and($meta['label'])->toBe('Carimbo do tempo da operadora — não é carimbo ICP-Brasil')
        ->and($meta['test_tsa'])->toBeTrue()
        ->and($meta['stamped_sha256'])->toBe($manifestSha);

    // O carimbo fica fora do manifesto por construção.
    $manifest = json_decode($entries['manifest.json'], true);
    expect(collect($manifest['files'])->pluck('path')->filter(fn ($p) => str_starts_with($p, 'carimbo/'))->all())->toBe([]);

    $token = TimestampToken::withoutOrganizationScope()->findOrFail($export->timestamp_token_id);
    expect($token->tsa_kind)->toBe(TsaKind::Operator)
        ->and($token->purpose)->toBe('dossier_manifest')
        ->and($token->imprint)->toBe($manifestSha)
        ->and($token->verification['valid'])->toBeTrue();

    expect($entries['LEIA-ME.txt'])->toContain('não é carimbo ICP-Brasil')
        ->and(implode("\n", $entries))->not->toContain(KTSA_TSA_PASSWORD)
        ->and(implode("\n", $entries))->not->toContain('PRIVATE KEY');

    // Conferência independente, quando o OpenSSL está disponível na máquina.
    $openssl = (new ExecutableFinder)->find('openssl');
    if ($openssl !== null) {
        file_put_contents($this->work.'/manifest.json', $entries['manifest.json']);
        file_put_contents($this->work.'/manifesto.tsr', $entries['carimbo/manifesto.tsr']);
        $process = new Process([$openssl, 'ts', '-verify', '-data', $this->work.'/manifest.json', '-in', $this->work.'/manifesto.tsr', '-CAfile', $tsa['root']]);
        $process->run();
        expect($process->getOutput())->toContain('Verification: OK');
    }
});

it('com a TSA indisponível o dossiê sai sem carimbo e diz isso', function () {
    config()->set('assinavelox.features.operator_tsa', true);
    config()->set('assinavelox.tsa.pfx_path', $this->work.'/nao-existe.pfx');

    [$export, $entries] = ktsaBuildViaHttp($this);

    expect($export->timestamp_status)->toBe('unavailable')
        ->and($entries)->toHaveKey('carimbo/INDISPONIVEL.txt')
        ->and($entries)->not->toHaveKey('carimbo/manifesto.tsr')
        ->and(TimestampToken::withoutOrganizationScope()->count())->toBe(0);
});
