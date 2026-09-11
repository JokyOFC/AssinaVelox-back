<?php

use App\Services\Signing\Certificates\Exceptions\ParticipantCertificateException;
use App\Services\Signing\Certificates\SealedCertificate;
use App\Services\Signing\Certificates\SealedCertificateStore;
use Tests\Feature\Pdf\Support\PdfFixtures;

/*
|--------------------------------------------------------------------------
| Material temporário cifrado do certificado do participante (K-A1)
|--------------------------------------------------------------------------
| AES-256-GCM com chave derivada da APP_KEY, amarrado ao pedido, consumido uma única vez,
| com prazo curto. O objeto em memória não se descreve nem se serializa.
*/

beforeEach(function () {
    $this->work = PdfFixtures::workspace();
    config()->set('assinavelox.participant_a1.sealed_path', $this->work.DIRECTORY_SEPARATOR.'selado');
    $this->store = app(SealedCertificateStore::class);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

it('cifra o conjunto, consome uma única vez e só abre para o mesmo pedido', function () {
    $pfx = random_bytes(512).'PFX-BYTES-MARCADOR';
    $ulid = $this->store->seal('01REQUESTULIDAAAAAAAAAAAAA', $pfx, 'segredo-do-participante-xyz');
    $file = $this->work.DIRECTORY_SEPARATOR.'selado'.DIRECTORY_SEPARATOR.$ulid.'.sealed';
    $raw = (string) file_get_contents($file);

    expect($raw)->not->toContain('segredo-do-participante-xyz')
        ->and($raw)->not->toContain('PFX-BYTES-MARCADOR');

    $material = $this->store->open($ulid, '01REQUESTULIDAAAAAAAAAAAAA');

    expect($material->password())->toBe('segredo-do-participante-xyz')
        ->and(file_exists($file))->toBeFalse()
        ->and(print_r($material, true))->not->toContain('segredo')
        ->and(fn () => serialize($material))->toThrow(LogicException::class);

    $material->wipe();
    expect(fn () => $material->password())->toThrow(LogicException::class);

    // Segunda abertura (replay, worker duplicado): não há mais nada.
    expect(fn () => $this->store->open($ulid, '01REQUESTULIDAAAAAAAAAAAAA'))
        ->toThrow(ParticipantCertificateException::class, 'não está mais disponível');
});

it('recusa o material de outro pedido e o apaga mesmo assim', function () {
    $ulid = $this->store->seal('PEDIDO-A', 'pfx', 'senha');

    expect(fn () => $this->store->open($ulid, 'PEDIDO-B'))->toThrow(ParticipantCertificateException::class)
        ->and($this->store->exists($ulid))->toBeFalse();
});

it('recusa material adulterado ou vencido', function () {
    $ulid = $this->store->seal('PEDIDO', 'pfx-bytes', 'senha');
    $file = $this->work.DIRECTORY_SEPARATOR.'selado'.DIRECTORY_SEPARATOR.$ulid.'.sealed';
    $raw = (string) file_get_contents($file);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'x' ? 'y' : 'x';
    file_put_contents($file, $raw);

    expect(fn () => $this->store->open($ulid, 'PEDIDO'))->toThrow(ParticipantCertificateException::class);

    $old = $this->store->seal('PEDIDO', 'pfx-bytes', 'senha');
    touch($this->work.DIRECTORY_SEPARATOR.'selado'.DIRECTORY_SEPARATOR.$old.'.sealed', time() - 3600);

    expect(fn () => $this->store->open($old, 'PEDIDO'))->toThrow(ParticipantCertificateException::class, 'prazo');

    $stale = $this->store->seal('PEDIDO', 'pfx', 'senha');
    touch($this->work.DIRECTORY_SEPARATOR.'selado'.DIRECTORY_SEPARATOR.$stale.'.sealed', time() - 3600);
    $fresh = $this->store->seal('PEDIDO', 'pfx', 'senha');

    expect($this->store->purgeOlderThan())->toBe(1)
        ->and($this->store->exists($stale))->toBeFalse()
        ->and($this->store->exists($fresh))->toBeTrue();
});

it('o material em memória não pode ser clonado', function () {
    $material = new SealedCertificate('pfx', 'senha');

    expect(fn () => clone $material)->toThrow(LogicException::class);
});
