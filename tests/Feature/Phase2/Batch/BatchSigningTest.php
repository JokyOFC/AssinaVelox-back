<?php

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Services\Batch\Models\BatchSigningItem;
use App\Services\Batch\Models\BatchSigningSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../InPerson/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Assinatura em lote — emissão, código e autorização item a item (Fase 2 §2.7, C-PRES)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/batch-'.uniqid());
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

it('emite o link só com pendências da mesma organização e congela os itens', function () {
    $s = batchScenario();

    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $batch = BatchSigningSession::withoutOrganizationScope()->sole();
    $items = BatchSigningItem::withoutOrganizationScope()->orderBy('position')->get();

    expect($batch->organization_id)->toBe($s['organization']->id)
        ->and($batch->anchor_recipient_id)->toBe($s['maria_one']->id)
        ->and($items->pluck('envelope_id')->all())->toBe([
            $s['one']['envelope']->id,
            $s['two']['envelope']->id,
            $s['three']['envelope']->id,
        ])
        ->and($items->pluck('envelope_id')->all())->not->toContain($s['foreign']['envelope']->id)
        ->and($this->batchLinks)->toHaveCount(1);

    $issued = AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::BatchLinkIssued->value)->sole();
    expect($issued->actor_type)->toBe(ActorType::User)
        ->and($issued->envelope_id)->toBe($s['one']['envelope']->id)
        ->and($issued->payload['items'])->toBe(3);

    // Um documento novo depois da emissão NÃO entra no lote (nada de consentimento futuro).
    signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test']], envelopeAttributes: ['title' => 'Aditivo'], organization: $s['organization'], owner: $s['owner']);

    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);

    expect($props['screen'])->toBe('list')
        ->and(collect($props['items'])->pluck('title')->all())->toBe(['Contrato de locação', 'Termo de vistoria', 'Termo de entrega de chaves'])
        ->and(collect($props['items'])->pluck('state')->unique()->all())->toBe(['available']);
});

it('antes do código, a página não mostra títulos nem abre documento', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);

    $this->get($this->batchLinks[0])->assertRedirect(route('sign.batch.show'));

    $props = $this->get(route('sign.batch.show'))->assertOk()->viewData('page')['props'];
    $json = (string) json_encode($props);

    expect($props['screen'])->toBe('identify')
        ->and($props['items'])->toBe([])
        ->and($props['recipient']['email_masked'])->toBe($s['maria_one']->masked_email)
        ->and($json)->not->toContain('Contrato de locação')
        ->and($json)->not->toContain('maria@exemplo.test');

    $item = BatchSigningItem::withoutOrganizationScope()->orderBy('position')->first();

    $this->post(route('sign.batch.items.open', ['item' => $item->ulid]))->assertSessionHas('error');
    $this->get(route('sign.batch.document', ['item' => $item->ulid]))->assertNotFound();

    expect(BatchSigningItem::withoutOrganizationScope()->whereNotNull('signing_session_id')->count())->toBe(0);
});

it('cada autorização gera um aceite independente, com sessão, snapshot e campos próprios', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);

    [$first, $second, $third] = collect($props['items'])->pluck('id')->all();

    $current = batchOpen($this, $first);
    batchAuthorize($this, $first, $current)->assertRedirect(route('sign.batch.show'))->assertSessionHasNoErrors();

    $current = batchOpen($this, $second);
    $text = collect($current['my_fields'])->firstWhere('type', 'text');
    batchAuthorize($this, $second, $current, [$text['id'] => 'Imóvel conferido'])->assertSessionHasNoErrors();

    $acceptances = SignatureAcceptance::withoutOrganizationScope()->orderBy('id')->get();

    expect($acceptances)->toHaveCount(2)
        ->and($acceptances->pluck('recipient_id')->all())->toBe([$s['maria_one']->id, $s['maria_two']->id])
        ->and($acceptances[0]->envelope_id)->toBe($s['one']['envelope']->id)
        ->and($acceptances[1]->envelope_id)->toBe($s['two']['envelope']->id)
        ->and($acceptances[0]->signing_session_id)->not->toBe($acceptances[1]->signing_session_id)
        ->and($acceptances[0]->document_sha256)->toBe($s['one']['version']->sha256)
        ->and($acceptances[1]->document_sha256)->toBe($s['two']['version']->sha256)
        ->and($acceptances[0]->document_sha256)->not->toBe($acceptances[1]->document_sha256)
        ->and($acceptances[0]->consent_statement)->toContain('Contrato de locação')
        ->and($acceptances[1]->consent_statement)->toContain('Termo de vistoria')
        ->and($acceptances[0]->fields_snapshot)->not->toBe($acceptances[1]->fields_snapshot);

    $textValue = collect($acceptances[1]->fields_snapshot['values'])->firstWhere('type', 'text');
    expect($textValue['text'])->toBe('Imóvel conferido')
        ->and(collect($acceptances[0]->fields_snapshot['values'])->firstWhere('type', 'text'))->toBeNull();

    // O terceiro continua pendente: nada foi autorizado por ele.
    $items = BatchSigningItem::withoutOrganizationScope()->orderBy('position')->get();
    expect($items->pluck('status')->all())->toBe(['authorized', 'authorized', 'pending'])
        ->and($items[0]->signature_acceptance_id)->toBe($acceptances[0]->id)
        ->and($items[1]->signature_acceptance_id)->toBe($acceptances[1]->id)
        ->and(SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $s['maria_three']->id)->exists())->toBeFalse();

    // Trilha de cada envelope: a sessão aberta pelo lote, a entrega do documento e o aceite.
    foreach ([[$s['one'], $first], [$s['two'], $second]] as [$scenario, $itemId]) {
        $types = AuditEvent::withoutGlobalScopes()->where('envelope_id', $scenario['envelope']->id)->pluck('event_type')->map->value->all();

        expect($types)->toContain('session.started', 'batch.item_opened', 'document.presented', 'acceptance.recorded', 'batch.item_authorized');
    }

    $list = $this->get(route('sign.batch.show'))->viewData('page')['props']['items'];
    expect(collect($list)->pluck('state')->all())->toBe(['done', 'done', 'available']);
});

it('não existe "autorizar todos": o POST autoriza só o item do caminho', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);
    $ids = collect($props['items'])->pluck('id')->all();

    $current = batchOpen($this, $ids[0]);
    batchAuthorize($this, $ids[0], $current, extra: ['items' => $ids, 'all' => true])->assertSessionHasNoErrors();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(1)
        ->and(SignatureAcceptance::withoutOrganizationScope()->value('recipient_id'))->toBe($s['maria_one']->id);
});

it('o token de autorização de um item não serve para outro', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);
    [$first, , $third] = collect($props['items'])->pluck('id')->all();

    $currentFirst = batchOpen($this, $first);
    batchOpen($this, $third);

    batchAuthorize($this, $third, $currentFirst)->assertSessionHasErrors('signature');

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0)
        ->and(BatchSigningItem::withoutOrganizationScope()->where('ulid', $third)->value('last_error_code'))->toBe('stale_presentation');
});

it('uma falha num item não afeta os outros', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);
    [$first, $second] = collect($props['items'])->pluck('id')->all();

    // Item 2 sem o campo de texto obrigatório: recusado, com o erro no campo.
    $currentSecond = batchOpen($this, $second);
    $text = collect($currentSecond['my_fields'])->firstWhere('type', 'text');
    batchAuthorize($this, $second, $currentSecond)->assertSessionHasErrors('fields.'.$text['id']);

    // Item 1 segue normalmente.
    $currentFirst = batchOpen($this, $first);
    batchAuthorize($this, $first, $currentFirst)->assertSessionHasNoErrors();

    // Item 2 continua aberto e pode ser corrigido.
    $retry = $this->get(route('sign.batch.show', ['item' => $second]))->viewData('page')['props']['current'];
    batchAuthorize($this, $second, $retry, [$text['id'] => 'Conferido'])->assertSessionHasNoErrors();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(2)
        ->and(AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::BatchItemFailed->value)->count())->toBe(1)
        ->and(SigningField::withoutGlobalScopes()->where('recipient_id', $s['maria_two']->id)->where('type', 'text')->count())->toBe(1);
});

it('o código e os tokens do lote não aparecem na trilha', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    batchAuthenticate($this, $this->batchLinks[0]);

    $token = basename((string) parse_url($this->batchLinks[0], PHP_URL_PATH));
    $trail = (string) json_encode(AuditEvent::withoutGlobalScopes()->get(['payload', 'event_type'])->toArray());

    expect($trail)->not->toContain($token)
        ->and($trail)->not->toContain('maria@exemplo.test')
        ->and($trail)->toContain('batch.challenge_verified');

    foreach ($this->batchCodes as $code) {
        expect($trail)->not->toContain('"'.$code.'"');
    }

    // O banco guarda só digests.
    $batch = BatchSigningSession::withoutOrganizationScope()->sole();
    expect($batch->token_digest)->toBe(hash('sha256', $token))
        ->and(json_encode($batch->getAttributes()))->not->toContain($token);
});
