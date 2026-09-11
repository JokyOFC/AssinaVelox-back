<?php

use App\Enums\FieldType;
use App\Models\Document;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 — isolamento entre organizações nas rotas por documento
|--------------------------------------------------------------------------
| O parâmetro `document` (ULID) nunca é confiado: é sempre resolvido DENTRO do envelope
| já autorizado. ULID de outro envelope ou de outra organização responde 404.
*/

beforeEach(function () {
    Queue::fake();

    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();

    ['organization' => $a, 'owner' => $ownerA] = createOrganizationWithOwner(['name' => 'Organização A']);
    ['organization' => $b, 'owner' => $ownerB] = createOrganizationWithOwner(['name' => 'Organização B']);

    domainEnableFlags($a);
    domainEnableFlags($b);

    $this->a = domainEnvelope(['Contrato A', 'Anexo A'], [['name' => 'Maria', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]]], organization: $a, owner: $ownerA, sent: false);
    $this->b = domainEnvelope(['Contrato B', 'Anexo B'], [['name' => 'Ana', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]]], organization: $b, owner: $ownerB, sent: false);

    $this->ownerB = $ownerB;
    $this->orgB = $b;
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('não abre o envelope de outra organização por nenhuma rota de documento', function () {
    actingAsMember($this->ownerB, $this->orgB);

    $foreign = $this->a['envelope']->ulid;
    $doc = $this->a['documents'][1]->ulid;

    $this->get(route('envelopes.document.preview', ['envelope' => $foreign, 'document' => $doc]))->assertNotFound();
    $this->get(route('envelopes.document.status', ['envelope' => $foreign]))->assertNotFound();
    $this->get(route('envelopes.download', ['envelope' => $foreign, 'type' => 'original', 'document' => $doc]))->assertNotFound();
    $this->delete(route('envelopes.document.destroy', ['envelope' => $foreign, 'document' => $doc]))->assertNotFound();

    expect(Document::withoutOrganizationScope()->whereKey($this->a['documents'][1]->id)->exists())->toBeTrue();
});

it('recusa, no próprio envelope, o ULID de um documento de outra organização', function () {
    actingAsMember($this->ownerB, $this->orgB);

    $own = $this->b['envelope']->ulid;
    $foreignDoc = $this->a['documents'][0]->ulid;

    $this->get(route('envelopes.document.preview', ['envelope' => $own, 'document' => $foreignDoc]))->assertNotFound();
    $this->get(route('envelopes.document.status', ['envelope' => $own, 'document' => $foreignDoc]))->assertNotFound();
    $this->get(route('envelopes.download', ['envelope' => $own, 'type' => 'original', 'document' => $foreignDoc]))->assertNotFound();
    $this->delete(route('envelopes.document.destroy', ['envelope' => $own, 'document' => $foreignDoc]))->assertNotFound();

    $this->patch(route('envelopes.update', ['envelope' => $own]), [
        'document_order' => [$foreignDoc, $this->b['documents'][1]->ulid],
    ])->assertSessionHasErrors('document_order');

    expect(Document::withoutOrganizationScope()->whereKey($this->a['documents'][0]->id)->exists())->toBeTrue()
        ->and($this->b['documents'][0]->fresh()->position)->toBe(1);

    // O próprio documento continua acessível.
    $this->get(route('envelopes.document.preview', ['envelope' => $own, 'document' => $this->b['documents'][1]->ulid]))->assertOk();
});
