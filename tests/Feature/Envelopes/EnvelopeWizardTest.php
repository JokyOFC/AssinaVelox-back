<?php

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Envelopes\EnvelopeReadiness;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/WizardHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('create cria um rascunho com os padrões da organização e redireciona para o wizard', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    actingAsMember($owner, $organization);

    $response = $this->get(route('envelopes.create'));

    $envelope = Envelope::query()->latest('id')->firstOrFail();

    $response->assertRedirect(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 1]));

    expect($envelope->status)->toBe(EnvelopeStatus::Draft);
    expect($envelope->created_by_user_id)->toBe($owner->id);
    expect($envelope->setting('expiration_days'))->toBe(30);
    expect($envelope->number)->toBe(1);
});

test('o wizard traz envelope, documento com pages_meta, signatários, campos e pendências', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 2);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');
    SigningField::factory()->forRecipient($maria, $envelope->document->currentVersion)->signature()->create();

    Folder::factory()->create(['organization_id' => $organization->id, 'created_by_user_id' => $owner->id, 'name' => 'Locação']);

    actingAsMember($owner, $organization);

    $this->get(route('envelopes.edit', $envelope))->assertInertia(fn (Assert $page) => $page
        ->component('envelopes/wizard')
        ->where('envelope.id', $envelope->ulid)
        ->where('envelope.display_code', $envelope->display_code)
        ->where('envelope.signing_order', 'sequential')
        ->where('document.processing.status', 'ready')
        ->where('document.processing.pages', 2)
        ->has('document.page_sizes', 2)
        ->has('document.pages_meta', 2)
        ->where('document.pages_meta.0.page', 1)
        ->where('document.pages_meta.0.rotation', 0)
        ->has('recipients', 1)
        ->where('recipients.0.id', $maria->ulid)
        ->where('recipients.0.client_id', $maria->ulid)
        ->has('fields', 1)
        ->where('fields.0.type', 'signature')
        ->has('folders', 1)
        ->where('completeness.document', true)
        ->where('completeness.recipients', true)
        ->where('completeness.fields', true)
        ->where('issues', [])
        ->has('defaults')
        ->has('plan')
        ->has('limits')
        ->where('limits.max_fields', 200)
        ->where('limits.field_minimums.signature.width_pt', 56)
        ->where('limits.field_minimums.checkbox.height_pt', 8)
        ->has('role_suggestions'));
});

test('o passo pedido é rebaixado quando o anterior está incompleto', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    actingAsMember($owner, $organization);

    $this->get(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 4]))
        ->assertInertia(fn (Assert $page) => $page->where('step', 1));

    $withDocument = draftWithDocument($organization, $owner);

    $this->get(route('envelopes.edit', ['envelope' => $withDocument->ulid, 'step' => 4]))
        ->assertInertia(fn (Assert $page) => $page->where('step', 2));

    addRecipient($withDocument, 'Maria', 'maria@exemplo.com');

    $this->get(route('envelopes.edit', ['envelope' => $withDocument->ulid, 'step' => 4]))
        ->assertInertia(fn (Assert $page) => $page->where('step', 3));
});

test('o wizard redireciona para o detalhe quando o envelope já foi enviado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    actingAsMember($owner, $organization);

    $this->get(route('envelopes.edit', $envelope))->assertRedirect(route('envelopes.show', $envelope));
});

test('update salva metadados e recalcula a prontidão', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $folder = Folder::factory()->create(['organization_id' => $organization->id, 'created_by_user_id' => $owner->id]);

    actingAsMember($owner, $organization);

    $this->patch(route('envelopes.update', $envelope), [
        'title' => 'Contrato de locação — Apto 302',
        'folder_id' => $folder->ulid,
        'expires_in_days' => 15,
        'message' => 'Segue o contrato para assinatura.',
        'signing_order' => 'parallel',
        'send_copy_to_all' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $envelope = $envelope->fresh();

    expect($envelope->title)->toBe('Contrato de locação — Apto 302');
    expect($envelope->folder_id)->toBe($folder->id);
    expect($envelope->setting('expiration_days'))->toBe(15);
    expect($envelope->setting('send_copy_to_all'))->toBeTrue();
    expect($envelope->signing_order->value)->toBe('parallel');
    expect($envelope->status)->toBe(EnvelopeStatus::Draft); // ainda sem signatários/campos
});

test('a prontidão vira ready quando documento, signatários e campos estão completos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 2);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [['id' => $maria->ulid, 'name' => 'Maria', 'email' => 'maria@exemplo.com', 'order' => 1]],
    ])->assertRedirect();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [fieldPayload($maria)],
    ])->assertRedirect();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('readiness bloqueia quando falta campo de assinatura para um dos signatários', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com', 1);
    addRecipient($envelope, 'Carlos', 'carlos@exemplo.com', 2);

    SigningField::factory()->forRecipient($maria, $envelope->document->currentVersion)->signature()->create();

    $issues = EnvelopeReadiness::issues($envelope->fresh());

    expect($issues)->toContain('Sem campo de assinatura: Carlos.');
    expect(EnvelopeReadiness::isReady($envelope->fresh()))->toBeFalse();
    expect(EnvelopeReadiness::completeness($envelope->fresh())['fields'])->toBeFalse();
});

test('readiness lista pendências de documento e de signatários em PT-BR', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    expect(EnvelopeReadiness::issues($envelope))->toBe([
        'Envie o documento que será assinado.',
        'Adicione pelo menos um signatário.',
    ]);
});

test('duplicar cria um rascunho independente com documento, signatários e campos, sem herdar estado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 2);
    $envelope->forceFill(['title' => 'Contrato Paulista'])->save();

    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com', 1);
    $maria->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now(), 'notification_count' => 3, 'role_label' => 'Locatária'])->save();
    SigningField::factory()->forRecipient($maria, $envelope->document->currentVersion)->signature()->create();

    $envelope->forceFill([
        'status' => EnvelopeStatus::InProgress,
        'sent_at' => now(),
        'verification_code' => Envelope::generateVerificationCode(),
    ])->save();

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.duplicate', $envelope))->assertRedirect();

    $copy = Envelope::query()->where('id', '!=', $envelope->id)->latest('id')->firstOrFail();

    expect($copy->title)->toBe('Contrato Paulista (cópia)');
    expect($copy->status)->toBe(EnvelopeStatus::Ready);
    expect($copy->sent_at)->toBeNull();
    expect($copy->verification_code)->toBeNull();
    expect($copy->number)->not->toBe($envelope->number);

    $copiedRecipient = $copy->recipients()->firstOrFail();
    expect($copiedRecipient->id)->not->toBe($maria->id);
    expect($copiedRecipient->status)->toBe(RecipientStatus::Pending);
    expect($copiedRecipient->signed_at)->toBeNull();
    expect($copiedRecipient->notification_count)->toBe(0);
    expect($copiedRecipient->role_label)->toBe('Locatária');

    expect($copy->document)->not->toBeNull();
    expect($copy->document->id)->not->toBe($envelope->document->id);
    expect($copy->document->page_count)->toBe(2);

    $copiedField = $copy->fields()->firstOrFail();
    expect($copiedField->recipient_id)->toBe($copiedRecipient->id);
    expect($copiedField->document_version_id)->toBe($copy->document->current_version_id);

    // O original segue intacto.
    expect($envelope->fresh()->recipients()->count())->toBe(1);
    expect($maria->fresh()->status)->toBe(RecipientStatus::Signed);
});

test('mover e cancelar registram a trilha e o cancelamento encerra os pendentes', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->notified()->create();
    $folder = Folder::factory()->create(['organization_id' => $organization->id, 'created_by_user_id' => $owner->id, 'name' => 'Arquivados']);

    actingAsMember($owner, $organization);

    $this->patch(route('envelopes.move', $envelope), ['folder_id' => $folder->ulid])->assertRedirect();
    expect($envelope->fresh()->folder_id)->toBe($folder->id);

    $this->post(route('envelopes.cancel', $envelope), ['reason' => 'Cliente desistiu.'])->assertRedirect();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Canceled);
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Canceled);
    expect($envelope->fresh()->setting('cancel_reason'))->toBe('Cliente desistiu.');

    $types = $envelope->auditEvents()->pluck('event_type')->map(fn ($t) => $t->value)->all();
    expect($types)->toContain('envelope.moved');
    expect($types)->toContain('envelope.canceled');
});

test('excluir só vale para rascunho', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $draft = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
    $sent = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    actingAsMember($owner, $organization);

    $this->delete(route('envelopes.destroy', $draft))->assertRedirect(route('envelopes.index'));
    expect(Envelope::query()->whereKey($draft->id)->exists())->toBeFalse();

    $this->from(route('envelopes.show', $sent))
        ->delete(route('envelopes.destroy', $sent))
        ->assertSessionHas('error', 'Só rascunhos podem ser excluídos.');
});

test('ações em lote autorizam item a item e ignoram o que não pode', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    $mine = Envelope::factory()->forOrganization($organization, $member)->inProgress()->create();
    $theirs = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $folder = Folder::factory()->create(['organization_id' => $organization->id, 'created_by_user_id' => $owner->id]);

    actingAsMember($member, $organization);

    $this->post(route('envelopes.bulk', ['action' => 'move']), [
        'ids' => [$mine->ulid, $theirs->ulid],
        'folder_id' => $folder->ulid,
    ])->assertRedirect();

    expect($mine->fresh()->folder_id)->toBe($folder->id);
    expect($theirs->fresh()->folder_id)->toBeNull();

    $this->post(route('envelopes.bulk', ['action' => 'cancel']), [
        'ids' => [$mine->ulid, $theirs->ulid],
        'reason' => 'Lote',
    ])->assertRedirect();

    expect($mine->fresh()->status)->toBe(EnvelopeStatus::Canceled);
    expect($theirs->fresh()->status)->toBe(EnvelopeStatus::InProgress);
});

test('o wizard de outra organização devolve 404', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    ['organization' => $other, 'owner' => $intruder] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    actingAsMember($intruder, $other);

    $this->get(route('envelopes.edit', $envelope))->assertNotFound();
    $this->patch(route('envelopes.update', $envelope), ['title' => 'Sequestrado'])->assertNotFound();
    $this->post(route('envelopes.duplicate', $envelope))->assertNotFound();
    $this->patch(route('envelopes.move', $envelope), ['folder_id' => null])->assertNotFound();
    $this->post(route('envelopes.cancel', $envelope))->assertNotFound();
    $this->delete(route('envelopes.destroy', $envelope))->assertNotFound();

    expect($envelope->fresh()->title)->not->toBe('Sequestrado');
});
