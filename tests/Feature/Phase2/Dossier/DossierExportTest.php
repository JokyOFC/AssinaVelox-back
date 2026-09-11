<?php

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Services\Dossier\DossierBuilder;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Pdf\PdfToolClient;

require_once __DIR__.'/Support/DossierHelpers.php';

/*
|--------------------------------------------------------------------------
| Dossiê ZIP (roadmap §2.13): conteúdo, manifesto, trilha, segredos, idempotência e lote
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    ['organization' => $this->organization, 'owner' => $this->owner] = ktsaDossierSetup($this->work);
    $this->scenario = ktsaCompletedEnvelope($this->organization, $this->owner, $this->work);
    $this->envelope = $this->scenario['envelope'];
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

function ktsaRequestAndDownload(object $test, string $envelopeUlid): array
{
    actingAsMember($test->owner, $test->organization);
    $response = $test->postJson(route('envelopes.dossier.store', ['envelope' => $envelopeUlid]))->assertSuccessful();
    $export = DossierExport::withoutOrganizationScope()->where('ulid', $response->json('export.id'))->firstOrFail();
    $props = app(DossierExports::class)->statusProps($export);

    expect($props['status'])->toBe('ready')
        ->and($props['download_url'])->toBeString();

    $download = $test->get($props['download_url']);
    $download->assertOk()->assertHeader('Content-Type', 'application/zip');

    return [$export, ktsaZipEntries($download->streamedContent(), $test->work)];
}

it('com a flag desligada as rotas do dossiê respondem 404', function () {
    config()->set('assinavelox.features.dossier_export', false);
    actingAsMember($this->owner, $this->organization);

    $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertNotFound();
    $this->postJson(route('dossiers.bulk'), ['ids' => [$this->envelope->ulid]])->assertNotFound();

    expect(DossierExport::withoutOrganizationScope()->count())->toBe(0);
});

it('contém exatamente os arquivos esperados e o manifesto confere com os bytes', function () {
    [$export, $entries] = ktsaRequestAndDownload($this, $this->envelope->ulid);
    $versions = $this->scenario['versions'];

    $expected = [
        'manifest.json',
        'LEIA-ME.txt',
        'trilha/auditoria.json',
        'trilha/auditoria.csv',
        'validacao.json',
        'documentos/01-contrato/v1-original.pdf',
        'documentos/01-contrato/v2-consolidado.pdf',
        'documentos/01-contrato/v3-evidencias.pdf',
        'documentos/01-contrato/v4-final.pdf',
    ];

    expect(array_keys($entries))->toEqualCanonicalizing($expected);

    $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['format'])->toBe(DossierBuilder::FORMAT)
        ->and(collect($manifest['files'])->pluck('path')->all())->toEqualCanonicalizing(array_values(array_diff($expected, ['manifest.json'])));

    foreach ($manifest['files'] as $file) {
        expect(hash('sha256', $entries[$file['path']]))->toBe($file['sha256'], $file['path'])
            ->and(strlen($entries[$file['path']]))->toBe($file['size_bytes']);
    }

    // Cada versão confere também com o resumo gravado no banco.
    foreach (['original' => 'v1-original', 'consolidated' => 'v2-consolidado', 'evidence' => 'v3-evidencias', 'final' => 'v4-final'] as $kind => $name) {
        expect(hash('sha256', $entries["documentos/01-contrato/{$name}.pdf"]))->toBe($versions[$kind]->sha256);
    }

    $finalEntry = collect($manifest['files'])->firstWhere('path', 'documentos/01-contrato/v4-final.pdf');
    expect($finalEntry['matches_record'])->toBeTrue()
        ->and($manifest['published_hashes']['final_sha256'])->toBe($versions['final']->sha256)
        ->and($export->manifest_sha256)->toBe(hash('sha256', $entries['manifest.json']))
        ->and($export->timestamp_status)->toBe('disabled')
        ->and($entries['LEIA-ME.txt'])->toContain('não contém carimbo do tempo');
});

it('protege a trilha CSV contra fórmula e aplica a política de IP e e-mail (padrão: mascarado)', function () {
    [, $entries] = ktsaRequestAndDownload($this, $this->envelope->ulid);

    expect($entries['trilha/auditoria.csv'])->toStartWith("\xEF\xBB\xBF")
        ->and($entries['trilha/auditoria.csv'])->toMatch("/;\"?'=HYPERLINK/")
        ->and($entries['trilha/auditoria.csv'])->not->toMatch('/;"?=HYPERLINK/');

    $all = implode("\n", $entries);
    $manifest = json_decode($entries['manifest.json'], true);

    expect($manifest['ip_policy'])->toBe('masked')
        ->and($manifest['participants'][0]['ip'])->toBe('203.0.***.***')
        ->and($all)->not->toContain('203.0.113.10')
        ->and($all)->not->toContain('maria.alves@exemplo.test');
});

it('com a política "full" o dossiê mostra IP e e-mail por extenso', function () {
    $this->organization->forceFill(['settings' => [...($this->organization->settings ?? []), 'evidence_show_ip' => 'full']])->save();

    [, $entries] = ktsaRequestAndDownload($this, $this->envelope->ulid);
    $manifest = json_decode($entries['manifest.json'], true);

    expect($manifest['ip_policy'])->toBe('full')
        ->and($manifest['participants'][0]['ip'])->toBe('203.0.113.10')
        ->and($manifest['participants'][0]['email'])->toBe('maria.alves@exemplo.test');
});

it('nenhum segredo, token ou senha dentro do ZIP (varredura de todo o conteúdo)', function () {
    putenv('KTSA_FAKE_COMPANY_CERT_PASSWORD=senha-do-pfx-NAO-PODE-VAZAR');

    try {
        [, $entries] = ktsaRequestAndDownload($this, $this->envelope->ulid);
    } finally {
        putenv('KTSA_FAKE_COMPANY_CERT_PASSWORD');
    }

    $all = implode("\n", array_keys($entries))."\n".implode("\n", $entries);
    $appKey = (string) config('app.key');

    $forbidden = [
        KTSA_RAW_ACCESS_TOKEN,
        hash('sha256', KTSA_RAW_ACCESS_TOKEN),
        KTSA_PAYLOAD_SECRET,
        'senha-do-pfx-NAO-PODE-VAZAR',
        substr($appKey, 7, 20),
        'PRIVATE KEY',
        'storage/app',
        'orgs/'.$this->organization->ulid,
        'signatures/signature-',
    ];

    foreach ($forbidden as $needle) {
        expect(str_contains($all, $needle))->toBeFalse("Encontrado no ZIP: {$needle}");
    }

    expect($entries['trilha/auditoria.json'])->toContain('[omitido no dossiê]');
});

it('é idempotente por (envelope, versão final) e reprodutível', function () {
    actingAsMember($this->owner, $this->organization);

    $first = $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->json('export.id');
    $second = $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertOk()->json('export.id');

    expect($second)->toBe($first)
        ->and(DossierExport::withoutOrganizationScope()->count())->toBe(1)
        ->and(DossierExport::withoutOrganizationScope()->first()->attempts)->toBe(1);

    // Mesmo envelope, mesma trilha → mesmo manifesto, byte a byte.
    $builder = app(DossierBuilder::class);
    $a = ktsaZipEntries((string) file_get_contents($builder->build($this->envelope, $this->work.'/a.zip', app(PdfToolClient::class)->temporaryDirectory(), null, false)->path), $this->work);
    $b = ktsaZipEntries((string) file_get_contents($builder->build($this->envelope, $this->work.'/b.zip', app(PdfToolClient::class)->temporaryDirectory(), null, false)->path), $this->work);

    expect($a['manifest.json'])->toBe($b['manifest.json']);
});

it('recusa envelope não concluído', function () {
    $this->envelope->forceFill(['status' => EnvelopeStatus::InProgress])->save();
    actingAsMember($this->owner, $this->organization);

    $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertStatus(422);
});

it('o Baixar em lote gera um dossiê por envelope num ZIP externo', function () {
    $second = ktsaCompletedEnvelope($this->organization, $this->owner, $this->work, 'Aditivo contratual')['envelope'];
    actingAsMember($this->owner, $this->organization);

    $id = $this->postJson(route('dossiers.bulk'), ['ids' => [$this->envelope->ulid, $second->ulid]])->assertSuccessful()->json('export.id');
    $export = DossierExport::withoutOrganizationScope()->where('ulid', $id)->firstOrFail();
    $url = app(DossierExports::class)->statusProps($export)['download_url'];
    $outer = ktsaZipEntries($this->get($url)->assertOk()->streamedContent(), $this->work);

    expect(array_keys($outer))->toEqualCanonicalizing([
        $this->envelope->display_code.'.zip',
        $second->display_code.'.zip',
        'indice.json',
        'LEIA-ME.txt',
    ]);

    $index = json_decode($outer['indice.json'], true);
    expect($index['envelopes'])->toHaveCount(2);

    foreach ($index['envelopes'] as $row) {
        expect(hash('sha256', $outer[$row['file']]))->toBe($row['dossier_sha256']);
        expect(array_keys(ktsaZipEntries($outer[$row['file']], $this->work)))->toContain('manifest.json');
    }
});

it('o lote respeita a visibilidade: um membro não inclui envelope de colega', function () {
    $member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($member, $this->organization);

    $this->postJson(route('dossiers.bulk'), ['ids' => [$this->envelope->ulid]])->assertStatus(422);

    expect(DossierExport::withoutOrganizationScope()->count())->toBe(0);
});
