<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Recipient;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('a listagem traz filtros, abas, pastas, criadores e envelopes paginados no shape Paginated', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $folder = Folder::factory()->create(['organization_id' => $organization->id, 'created_by_user_id' => $owner->id, 'name' => 'Locações']);

    $inProgress = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['folder_id' => $folder->id, 'title' => 'Contrato Paulista']);
    Recipient::factory()->forEnvelope($inProgress)->signed()->create();
    Recipient::factory()->forEnvelope($inProgress, 2)->notified()->create();
    $awaiting = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    Recipient::factory()->forEnvelope($awaiting)->notified()->create();
    Envelope::factory()->forOrganization($organization, $owner)->completed()->create();
    Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
    Envelope::factory()->forOrganization($organization, $owner)->refused()->create();
    Envelope::factory()->forOrganization($organization, $owner)->canceled()->create();

    actingAsMember($owner, $organization);

    $this->get(route('envelopes.index'))->assertInertia(fn (Assert $page) => $page
        ->component('envelopes/index')
        ->where('filters.status', 'all')
        ->where('filters.sort', 'updated_desc')
        ->where('tabs.all', 6)
        ->where('tabs.awaiting', 1)
        ->where('tabs.in_progress', 1)
        ->where('tabs.completed', 1)
        ->where('tabs.drafts', 1)
        ->where('tabs.refused_expired', 2)
        ->where('summary.total', 6)
        ->where('summary.awaiting', 2)
        ->where('folders.0.id', null)
        ->where('folders.0.name', 'Todos')
        ->where('folders.1.name', 'Locações')
        ->where('folders.1.count', 1)
        ->has('creators', 1)
        ->has('envelopes.data', 6)
        ->has('envelopes.meta.total')
        ->has('envelopes.meta.links')
        ->has('envelopes.links.next')
        ->where('envelopes.meta.per_page', 10)
        ->where('can.create_folder', true)
        ->where('can.bulk_cancel', true));

    $this->get(route('envelopes.index', ['status' => 'in_progress']))->assertInertia(fn (Assert $page) => $page
        ->has('envelopes.data', 1)
        ->where('envelopes.data.0.id', $inProgress->ulid)
        ->where('envelopes.data.0.display_code', $inProgress->display_code)
        ->where('envelopes.data.0.status_label', 'Em andamento')
        ->where('envelopes.data.0.signed_count', 1)
        ->where('envelopes.data.0.recipients_count', 2)
        ->where('envelopes.data.0.folder.name', 'Locações')
        ->where('envelopes.data.0.can.cancel', true)
        ->where('envelopes.data.0.can.download_signed', false));

    $this->get(route('envelopes.index', ['folder' => $folder->ulid, 'q' => 'Paulista']))
        ->assertInertia(fn (Assert $page) => $page->has('envelopes.data', 1)->where('filters.folder', $folder->ulid)->where('filters.q', 'Paulista'));

    $this->get(route('envelopes.index', ['q' => $inProgress->display_code]))
        ->assertInertia(fn (Assert $page) => $page->has('envelopes.data', 1));

    $this->get(route('envelopes.index', ['period_from' => now()->addDay()->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('envelopes.data', 0));

    $this->get(route('envelopes.index', ['status' => 'invalida']))->assertSessionHasErrors('status');
});

test('o detalhe traz envelope, signatários, campos, trilha, pastas e aba', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $signed = Recipient::factory()->forEnvelope($envelope)->signed()->create();
    $pending = Recipient::factory()->forEnvelope($envelope, 2)->notified()->create(['last_notified_at' => now()->subHour()]);

    AuditEvent::factory()->create([
        'organization_id' => $organization->id,
        'envelope_id' => $envelope->id,
        'recipient_id' => $pending->id,
        'event_type' => AuditEventType::InvitationOpened,
        'occurred_at' => now()->subMinutes(30),
    ]);

    actingAsMember($owner, $organization);

    $this->get(route('envelopes.show', ['envelope' => $envelope->ulid, 'tab' => 'audit', 'sent' => 1]))->assertInertia(fn (Assert $page) => $page
        ->component('envelopes/show')
        ->where('envelope.id', $envelope->ulid)
        ->where('envelope.display_code', $envelope->display_code)
        ->where('envelope.status', 'in_progress')
        ->where('envelope.status_label', 'Em andamento')
        ->where('envelope.signed_count', 1)
        ->where('envelope.recipients_count', 2)
        ->where('envelope.creator.id', (string) $owner->id)
        ->where('envelope.signing_order', 'sequential')
        ->has('envelope.expires_label')
        ->has('envelope.downloads.original')
        ->where('envelope.downloads.signed', null)
        ->where('envelope.can.cancel', true)
        ->where('envelope.can.update', false)
        ->has('recipients', 2)
        ->where('recipients.0.id', $signed->ulid)
        ->where('recipients.0.status', 'signed')
        ->where('recipients.0.color_index', 0)
        ->where('recipients.1.status', 'notified')
        // ROUTES_AND_PAGES §6.2: o badge de `notified` é "Pendente" (âmbar); o
        // detalhe "Enviado · não visualizou" fica na nota abaixo do badge.
        ->where('recipients.1.status_label', 'Pendente')
        ->where('recipients.1.can_resend', true)
        ->has('recipients.1.viewed_at')
        ->where('recipients.1.channel', 'email')
        ->where('recipients.1.auth_methods', ['email_otp'])
        ->has('fields')
        ->has('events', 1)
        ->where('events.0.type', 'invitation.opened')
        ->where('events.0.kind', 'info')
        ->has('folders')
        ->where('sent', true)
        ->where('tab', 'audit'));
});

test('envelope excluído (soft delete) e ulid inexistente devolvem 404', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
    $envelope->delete();

    actingAsMember($owner, $organization);

    $this->get(route('envelopes.show', $envelope->ulid))->assertNotFound();
    $this->get(route('envelopes.show', str_repeat('0', 26)))->assertNotFound();
});

test('pastas: owner/admin criam, renomeiam e excluem; member não pode; excluir devolve envelopes para a raiz', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($member, $organization);
    $this->post(route('folders.store'), ['name' => 'Bloqueada'])->assertForbidden();

    actingAsMember($owner, $organization);
    $this->post(route('folders.store'), ['name' => 'Vendas'])->assertRedirect()->assertSessionHas('success');
    $folder = Folder::query()->where('name', 'Vendas')->firstOrFail();
    expect($folder->organization_id)->toBe($organization->id);

    $this->post(route('folders.store'), ['name' => 'Vendas'])->assertSessionHasErrors('name');
    $this->post(route('folders.store'), ['name' => 'V'])->assertSessionHasErrors('name');

    $this->patch(route('folders.update', $folder), ['name' => 'Vendas 2026'])->assertRedirect();
    expect($folder->fresh()->name)->toBe('Vendas 2026');

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create(['folder_id' => $folder->id]);

    $this->delete(route('folders.destroy', $folder))->assertRedirect(route('envelopes.index'));
    expect(Folder::query()->find($folder->id))->toBeNull()
        ->and($envelope->fresh()->folder_id)->toBeNull();
});

test('ações básicas: criar rascunho, mover, cancelar e excluir rascunho', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $folder = Folder::factory()->create(['organization_id' => $organization->id, 'created_by_user_id' => $owner->id]);

    actingAsMember($owner, $organization);

    $response = $this->get(route('envelopes.create'));
    $draft = Envelope::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('envelopes.edit', ['envelope' => $draft->ulid, 'step' => 1]));
    expect($draft->status)->toBe(EnvelopeStatus::Draft)
        ->and($draft->number)->toBe(1)
        ->and($draft->organization_id)->toBe($organization->id);

    $this->get(route('envelopes.edit', $draft))->assertInertia(fn (Assert $page) => $page
        ->component('envelopes/wizard')
        ->where('envelope.id', $draft->ulid)
        ->where('step', 1)
        ->where('document', null)
        ->has('defaults.expires_in_days')
        ->has('plan.can_send')
        ->has('limits.accepted_mimes'));

    $this->patch(route('envelopes.move', $draft), ['folder_id' => $folder->ulid])->assertRedirect();
    expect($draft->fresh()->folder_id)->toBe($folder->id);

    $inProgress = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $this->post(route('envelopes.cancel', $inProgress), ['reason' => 'Erro no contrato'])->assertRedirect()->assertSessionHas('success');
    expect($inProgress->fresh()->status)->toBe(EnvelopeStatus::Canceled);

    $this->get(route('envelopes.edit', $inProgress))->assertRedirect(route('envelopes.show', $inProgress));

    $this->delete(route('envelopes.destroy', $inProgress))->assertRedirect()->assertSessionHas('error');
    $this->delete(route('envelopes.destroy', $draft))->assertRedirect(route('envelopes.index'));
    expect(Envelope::query()->find($draft->id))->toBeNull();
});
