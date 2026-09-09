<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — integridade documental
|--------------------------------------------------------------------------
|
| DEFEITO: `App\Services\Documents\DocumentIntake::store()` remove o documento anterior
| (registro, versões, BYTES no disco e todos os `signing_fields` do envelope) ANTES de
| gravar o arquivo novo:
|
|     $previous = $envelope->document()->first();
|     if ($previous !== null) { $this->removeDocument(...); }   // apaga tudo, commitado
|     ...
|     $this->storage->putFile($localPath, $storagePath);        // pode falhar
|     DB::transaction(...)                                      // pode falhar
|
| Se `putFile()` falhar (disco cheio, S3 fora do ar, permissão), o envelope fica SEM
| documento algum e sem os campos que o remetente havia posicionado, e a exceção sobe
| crua até o handler — o usuário perde trabalho por causa de um erro transitório de
| infraestrutura, num fluxo que ele entendeu como "trocar o arquivo".
|
| O próprio docblock do serviço promete o oposto ("o arquivo só é gravado depois de
| aprovado; … uma falha no banco apaga o arquivo recém-escrito") — a garantia não cobre o
| documento que já estava lá.
|
| Correção esperada: gravar os bytes novos e persistir a nova versão primeiro; só depois
| retirar a anterior (e, em caso de falha, deixar o documento anterior intacto).
*/

use App\Models\Document;
use App\Models\Envelope;
use App\Models\SigningField;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentStorage;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Envelopes/WizardHelpers.php';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    actingAsMember($owner, $organization);
    $this->withoutVite();
});

afterEach(fn () => cleanupDocumentsWorkspace($this->work ?? null));

it('preserva o documento anterior quando a gravação do substituto falha', function () {
    uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/original.pdf', 'Contrato original'), 'original.pdf')
        ->assertSessionHasNoErrors();

    $previous = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();
    $previousVersion = $previous->currentVersion;

    $maria = addRecipient($this->envelope->fresh(), 'Maria Alves', 'maria@exemplo.com');
    SigningField::factory()->forRecipient($maria, $previousVersion)->signature()->create();

    // Disco indisponível no instante da substituição.
    $this->instance(DocumentStorage::class, new class(app('config')) extends DocumentStorage
    {
        public function putFile(string $localPath, string $storagePath): void
        {
            throw new RuntimeException('disco indisponível');
        }
    });

    try {
        app(DocumentIntake::class)->store(
            $this->envelope->fresh(),
            DocumentFixtures::upload(PdfFixtures::onePagePdf($this->work.'/substituto.pdf', 'Contrato novo'), 'substituto.pdf'),
            $this->owner,
        );
    } catch (Throwable) {
        // A substituição falhou — o que importa é o que sobrou.
    }

    // O documento que já estava no envelope (e os campos posicionados sobre ele) têm de
    // continuar exatamente onde estavam.
    expect(Document::query()->whereKey($previous->id)->exists())
        ->toBeTrue('o documento anterior foi destruído por uma substituição que nem chegou a gravar')
        ->and(SigningField::query()->where('envelope_id', $this->envelope->id)->count())->toBe(1)
        ->and(Storage::disk('documents')->exists($previousVersion->storage_path))
        ->toBeTrue('os bytes do documento anterior foram apagados do disco');
});
