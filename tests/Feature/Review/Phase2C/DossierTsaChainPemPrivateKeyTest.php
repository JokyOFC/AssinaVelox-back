<?php

use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase2/Dossier/Support/DossierHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C — custódia e criptografia
|--------------------------------------------------------------------------
| `DossierBuilder::stampManifest()` copia o arquivo de `assinavelox.tsa.chain_pem` BYTE A BYTE
| para `carimbo/cadeia-tsa.pem` de TODO dossiê (linhas 455-458), sem conferir que ele só tem
| certificados. O PEM "cadeia" mais comum de se obter a partir do PKCS#12 da TSA é
| `openssl pkcs12 -in tsa.pfx -nodes -out cadeia.pem`, que grava a CHAVE PRIVADA junto. Com
| esse arquivo configurado, qualquer membro que baixa um dossiê leva a chave da TSA da
| operadora — e passa a emitir carimbos "da operadora".
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    ['organization' => $this->organization, 'owner' => $this->owner] = ktsaDossierSetup($this->work);
    $this->envelope = ktsaCompletedEnvelope($this->organization, $this->owner, $this->work)['envelope'];
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

it('a chave privada da TSA nunca entra no dossiê, mesmo que o PEM da cadeia a traga junto', function () {
    $tsa = ktsaConfigureTsa($this->work);

    // PEM com chave + certificados, como `openssl pkcs12 -nodes` produz (lido pelo venv do pdftool).
    $script = 'import os, sys; from cryptography.hazmat.primitives import serialization as s; '
        .'from cryptography.hazmat.primitives.serialization import pkcs12; '
        .'k, c, extra = pkcs12.load_key_and_certificates(open(sys.argv[1], "rb").read(), os.environ["KTSA_TEST_TSA_PASSWORD"].encode()); '
        .'sys.stdout.write(k.private_bytes(s.Encoding.PEM, s.PrivateFormat.PKCS8, s.NoEncryption()).decode() '
        .'+ c.public_bytes(s.Encoding.PEM).decode() + "".join(x.public_bytes(s.Encoding.PEM).decode() for x in extra or []))';
    $process = new Process([PdfFixtures::pythonBinary(), '-c', $script, $tsa['pfx']], base_path('tools/pdftool'), [KTSA_TSA_PASS_ENV => KTSA_TSA_PASSWORD]);
    $process->mustRun();

    $bundle = $tsa['dir'].DIRECTORY_SEPARATOR.'cadeia-com-chave.pem';
    file_put_contents($bundle, $process->getOutput());
    expect((string) file_get_contents($bundle))->toContain('PRIVATE KEY');

    config()->set('assinavelox.tsa.chain_pem', $bundle);

    actingAsMember($this->owner, $this->organization);
    $id = $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertSuccessful()->json('export.id');
    $export = DossierExport::withoutOrganizationScope()->where('ulid', $id)->firstOrFail();
    $url = app(DossierExports::class)->statusProps($export)['download_url'];
    $entries = ktsaZipEntries($this->get($url)->assertOk()->streamedContent(), $this->work);

    expect($export->timestamp_status)->toBe('granted')
        ->and(implode("\n", $entries))->not->toContain('PRIVATE KEY')
        ->and($entries['carimbo/cadeia-tsa.pem'] ?? '')->toContain('BEGIN CERTIFICATE');
});
