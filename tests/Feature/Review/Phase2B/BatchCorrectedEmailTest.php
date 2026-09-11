<?php

use App\Events\EnvelopeReadyForFinalization;
use App\Models\SignatureAcceptance;
use App\Services\Batch\Models\BatchSigningItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/InPerson/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — lote: e-mail corrigido não tira o item do lote
|--------------------------------------------------------------------------
| O lote agrupa participações "com o MESMO e-mail" e o código do lote prova a posse
| DESSE e-mail (BatchChallenges, docstring). Os itens são congelados na emissão.
|
| A única amarração com o e-mail depois disso é a do ÂNCORA: `BatchLinks::resolve()`
| (app/Services/Batch/BatchLinks.php:255–258) mata o link se o e-mail do participante
| âncora mudar. `BatchItems::describe()` (app/Services/Batch/BatchItems.php:92–157) não
| confere o e-mail do participante de CADA item contra `batch.email_digest`.
|
| O remetente corrige, com o envelope em andamento, o e-mail de um participante que estava
| errado (`PATCH documentos/{envelope}/destinatarios/{recipient}` → RecipientSync::
| updatePending, que revoga os links porque "trocar o e-mail invalida o convite"). O dono
| do endereço ANTIGO — justamente quem não deveria assinar — continua com o link de lote,
| recebe o código no endereço antigo e registra o aceite no lugar da pessoa certa.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-batch-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    $this->batchCodes = presenceCaptureBatchCodes();
    $this->batchLinks = presenceCaptureBatchLinks();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o item cujo participante teve o e-mail corrigido sai do lote do endereço antigo', function () {
    $s = batchScenario();

    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one'])
        ->assertSessionHas('success');

    $item = BatchSigningItem::withoutOrganizationScope()->where('recipient_id', $s['maria_three']->id)->sole();

    // O remetente descobre que o e-mail do 3.º documento estava errado e o corrige.
    actingAsMember($s['owner'], $s['organization']);
    $this->patch(route('envelopes.recipients.update', [
        'envelope' => $s['three']['envelope']->ulid,
        'recipient' => $s['maria_three']->ulid,
    ]), ['name' => 'Maria Alves Souza', 'email' => 'maria.correta@exemplo.test'])->assertSessionHasNoErrors();

    expect($s['maria_three']->fresh()->email)->toBe('maria.correta@exemplo.test');

    // Quem tem o endereço ANTIGO abre o link de lote e confirma o código (que chega nele).
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);

    $row = collect($props['items'])->firstWhere('id', $item->ulid);

    // Pelas rotas do lote, não pode registrar o aceite no lugar da pessoa certa.
    $this->post(route('sign.batch.items.open', ['item' => $item->ulid]));
    $page = $this->get(route('sign.batch.show', ['item' => $item->ulid]))->viewData('page')['props'];

    if (($page['screen'] ?? null) === 'item' && isset($page['current']['authorization']['token'])) {
        foreach ($page['current']['documents'] as $document) {
            $this->get($document['pdf_url']);
        }

        batchAuthorize($this, $item->ulid, $page['current']);
    }

    expect(SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $s['maria_three']->id)->exists())->toBeFalse()
        ->and($row === null || $row['state'] !== 'available')->toBeTrue();
});
