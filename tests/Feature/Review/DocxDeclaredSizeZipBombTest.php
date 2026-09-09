<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — validação de upload
|--------------------------------------------------------------------------
|
| DEFEITO: `App\Services\Documents\UploadInspector::inspectDocx()` mede a "bomba de
| descompressão" pelos tamanhos que o PRÓPRIO PACOTE declara
| (`ZipArchive::statIndex()['size']`, lido do diretório central do ZIP). Esse número é
| escolhido por quem monta o arquivo: nada obriga o dado comprimido a caber nele.
|
| Um DOCX de ~60 KB pode declarar poucas centenas de bytes descompactados e expandir,
| de verdade, para dezenas de MB. Os três limites (`max_uncompressed_mb`,
| `max_compression_ratio`, `max_entries`) passam todos, e o arquivo é aceito, gravado no
| disco e entregue ao conversor — exatamente o cenário que a inspeção existe para impedir.
|
| A fixture `DocumentFixtures::docxZipBomb()` (usada em `DocumentUploadTest`) declara o
| tamanho verdadeiro e por isso é barrada; o teste passa sem exercitar o caso adversarial.
|
| A defesa correta é medir a expansão REAL em streaming (`ZipArchive::getStream()` com
| corte no limite), não acreditar no cabeçalho.
*/

use App\Models\Document;
use App\Models\Envelope;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';

beforeEach(function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive indisponível nesta instalação do PHP.');
    }

    $this->work = storage_path('app/tmp/tests/docx-bomb-'.uniqid());
    @mkdir($this->work, 0700, true);
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    actingAsMember($owner, $organization);
    $this->withoutVite();
    Queue::fake();
});

afterEach(fn () => cleanupDocumentsWorkspace($this->work ?? null));

/**
 * DOCX válido cujo diretório central (e cabeçalhos locais) MENTEM o tamanho
 * descompactado: declaram poucas centenas de bytes, mas os dados deflate expandem para
 * `$megabytes` MB.
 */
function docxWithUnderstatedSizes(string $path, int $megabytes = 60): string
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<w:document/>');
    $zip->addFromString('word/media/anexo.bin', str_repeat("\0", $megabytes * 1024 * 1024));
    $zip->setCompressionName('word/media/anexo.bin', ZipArchive::CM_DEFLATE, 9);
    $zip->close();

    $bytes = (string) file_get_contents($path);
    $real = $megabytes * 1024 * 1024;
    $lie = 512;

    // Cabeçalho local (PK\x03\x04): uncompressed size em +22, 4 bytes little-endian.
    $offset = 0;
    while (($offset = strpos($bytes, "PK\x03\x04", $offset)) !== false) {
        if (unpack('V', substr($bytes, $offset + 22, 4))[1] === $real) {
            $bytes = substr_replace($bytes, pack('V', $lie), $offset + 22, 4);
        }
        $offset += 4;
    }

    // Diretório central (PK\x01\x02): uncompressed size em +24.
    $offset = 0;
    while (($offset = strpos($bytes, "PK\x01\x02", $offset)) !== false) {
        if (unpack('V', substr($bytes, $offset + 24, 4))[1] === $real) {
            $bytes = substr_replace($bytes, pack('V', $lie), $offset + 24, 4);
        }
        $offset += 4;
    }

    file_put_contents($path, $bytes);

    return $path;
}

/** Bytes que a entrada realmente produz ao ser descomprimida. */
function actualUnzippedBytes(string $path, string $entry): int
{
    $zip = new ZipArchive;
    $zip->open($path);
    $stream = $zip->getStream($entry);
    $total = 0;

    if (is_resource($stream)) {
        while (! feof($stream)) {
            $total += strlen((string) fread($stream, 1024 * 1024));
        }
        fclose($stream);
    }

    $zip->close();

    return $total;
}

it('recusa DOCX que declara tamanho descompactado menor do que realmente expande', function () {
    $path = docxWithUnderstatedSizes($this->work.'/anexo-grande.docx', 60);

    // O arquivo é mesmo uma bomba: ~60 KB no disco, ~60 MB ao abrir uma única entrada.
    expect(filesize($path))->toBeLessThan(1024 * 1024)
        ->and(actualUnzippedBytes($path, 'word/media/anexo.bin'))->toBeGreaterThan(50 * 1024 * 1024);

    uploadDocument($this->envelope, $path, 'anexo-grande.docx')->assertSessionHasErrors('file');

    expect(Document::query()->where('envelope_id', $this->envelope->id)->exists())->toBeFalse()
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
});
