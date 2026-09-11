<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\RetentionEvent;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Phase2/Retention/Support/RetentionHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C (ciclo de vida) — remoção do arquivo de rascunho preservado
|--------------------------------------------------------------------------
| LegalHolds::guardEnvelope diz ao preservar: "Nada dele pode ser excluído até que alguém com
| permissão libere a preservação", e docs/fase-2/retencao-e-preservacao.md §5.2 afirma que a
| regra é "consultada por todos os caminhos que apagam". A exclusão do rascunho inteiro
| (EnvelopeController::destroy) é barrada — e é só um soft delete.
|
| Já `DELETE documentos/{envelope}/documento` (EnvelopeDocumentController::destroy →
| DocumentIntake::remove) apaga DE VEZ o documento, todas as versões e os bytes no disco, sem
| consultar LegalHolds e sem registrar a tentativa na trilha da retenção.
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
});

it('remover o arquivo de um rascunho preservado é recusado e nada sai do banco nem do disco', function () {
    $draft = readyEnvelope($this->organization, $this->owner);
    $document = Document::withoutOrganizationScope()->where('envelope_id', $draft->id)->sole();

    $paths = [];

    foreach (DocumentVersion::withoutOrganizationScope()->where('document_id', $document->id)->get() as $version) {
        Storage::disk('documents')->put($version->storage_path, '%PDF-1.7 '.$version->ulid);
        $paths[] = $version->storage_path;
    }

    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Pedido do jurídico: minuta em disputa', envelope: $draft);

    actingAsMember($this->owner, $this->organization);

    $this->from(route('envelopes.index'))
        ->delete(route('envelopes.document.destroy', ['envelope' => $draft->ulid]));

    expect(Document::withoutOrganizationScope()->whereKey($document->id)->exists())->toBeTrue()
        ->and(DocumentVersion::withoutOrganizationScope()->where('document_id', $document->id)->count())->toBe(count($paths));

    foreach ($paths as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }

    expect(RetentionEvent::withoutOrganizationScope()
        ->where('event_type', RetentionEvent::HOLD_BLOCKED_DELETION)
        ->where('actor_user_id', $this->owner->id)
        ->exists())->toBeTrue();
});
