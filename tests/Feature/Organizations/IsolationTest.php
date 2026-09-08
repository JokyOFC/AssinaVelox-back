<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\MembershipInvitation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Recipient;
use App\Models\Subscription;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

/**
 * Cria duas organizações e um envelope (com recipient) na organização "alheia".
 *
 * @return array<string, mixed>
 */
function twoOrganizations(): array
{
    $mine = createOrganizationWithOwner(['name' => 'Minha Org']);
    $other = createOrganizationWithOwner(['name' => 'Outra Org']);

    $foreignEnvelope = Envelope::factory()->forOrganization($other['organization'], $other['owner'])->inProgress()->create();
    $foreignRecipient = Recipient::factory()->forEnvelope($foreignEnvelope)->notified()->create();
    $foreignFolder = Folder::factory()->create(['organization_id' => $other['organization']->id, 'created_by_user_id' => $other['owner']->id]);
    $foreignInvitation = MembershipInvitation::factory()->create(['organization_id' => $other['organization']->id, 'invited_by_user_id' => $other['owner']->id]);
    $foreignMembership = $other['membership'];
    $foreignSubscription = Subscription::withoutOrganizationScope()->where('organization_id', $other['organization']->id)->firstOrFail();
    $foreignPayment = Payment::factory()->create([
        'organization_id' => $other['organization']->id,
        'subscription_id' => $foreignSubscription->id,
        'plan_id' => Plan::free()->id,
    ]);

    return compact('mine', 'other', 'foreignEnvelope', 'foreignRecipient', 'foreignFolder', 'foreignInvitation', 'foreignMembership', 'foreignPayment');
}

test('rotas GET parametrizadas devolvem 404 para recursos de outra organização', function (string $routeName, callable $params) {
    $ctx = twoOrganizations();
    actingAsMember($ctx['mine']['owner'], $ctx['mine']['organization']);

    $this->get(route($routeName, $params($ctx)))->assertNotFound();
})->with([
    'envelopes.show' => ['envelopes.show', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.edit' => ['envelopes.edit', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.evidence' => ['envelopes.evidence', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.download' => ['envelopes.download', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid, 'type' => 'original']],
    'envelopes.document.status' => ['envelopes.document.status', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.document.page' => ['envelopes.document.page', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid, 'page' => 1]],
    'billing.payments.receipt' => ['billing.payments.receipt', fn ($ctx) => ['payment' => $ctx['foreignPayment']->ulid]],
]);

test('rotas de escrita parametrizadas devolvem 404 para recursos de outra organização', function (string $method, string $routeName, callable $params, array $payload = []) {
    $ctx = twoOrganizations();
    actingAsMember($ctx['mine']['owner'], $ctx['mine']['organization']);

    $this->{$method}(route($routeName, $params($ctx)), $payload)->assertNotFound();
})->with([
    'envelopes.update' => ['patch', 'envelopes.update', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid], ['title' => 'Novo título']],
    'envelopes.cancel' => ['post', 'envelopes.cancel', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.destroy' => ['delete', 'envelopes.destroy', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.duplicate' => ['post', 'envelopes.duplicate', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.move' => ['patch', 'envelopes.move', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid], ['folder_id' => null]],
    'envelopes.send' => ['post', 'envelopes.send', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.resend' => ['post', 'envelopes.resend', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid]],
    'envelopes.recipients.resend' => ['post', 'envelopes.recipients.resend', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid, 'recipient' => $ctx['foreignRecipient']->ulid]],
    'envelopes.recipients.update' => ['patch', 'envelopes.recipients.update', fn ($ctx) => ['envelope' => $ctx['foreignEnvelope']->ulid, 'recipient' => $ctx['foreignRecipient']->ulid], ['name' => 'X', 'email' => 'x@x.com']],
    'folders.update' => ['patch', 'folders.update', fn ($ctx) => ['folder' => $ctx['foreignFolder']->ulid], ['name' => 'Renomeada']],
    'folders.destroy' => ['delete', 'folders.destroy', fn ($ctx) => ['folder' => $ctx['foreignFolder']->ulid]],
    'members.update' => ['patch', 'members.update', fn ($ctx) => ['membership' => $ctx['foreignMembership']->id], ['role' => 'member']],
    'members.status' => ['patch', 'members.status', fn ($ctx) => ['membership' => $ctx['foreignMembership']->id], ['status' => 'suspended']],
    'members.destroy' => ['delete', 'members.destroy', fn ($ctx) => ['membership' => $ctx['foreignMembership']->id]],
    'members.transfer_ownership' => ['post', 'members.transfer_ownership', fn ($ctx) => ['membership' => $ctx['foreignMembership']->id]],
    'invitations.resend' => ['post', 'invitations.resend', fn ($ctx) => ['invitation' => $ctx['foreignInvitation']->ulid]],
    'invitations.destroy' => ['delete', 'invitations.destroy', fn ($ctx) => ['invitation' => $ctx['foreignInvitation']->ulid]],
]);

test('recipient de outro envelope da mesma organização também é 404 (binding aninhado)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $a = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $b = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $recipientOfB = Recipient::factory()->forEnvelope($b)->notified()->create();

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.recipients.resend', ['envelope' => $a->ulid, 'recipient' => $recipientOfB->ulid]))->assertNotFound();
});

test('a listagem de documentos não vaza envelopes de outra organização', function () {
    $ctx = twoOrganizations();
    $ownEnvelope = Envelope::factory()->forOrganization($ctx['mine']['organization'], $ctx['mine']['owner'])->completed()->create();

    actingAsMember($ctx['mine']['owner'], $ctx['mine']['organization']);

    $response = $this->get(route('envelopes.index'));
    $response->assertOk();

    $ids = collect($response->viewData('page')['props']['envelopes']['data'])->pluck('id');

    expect($ids->all())->toBe([$ownEnvelope->ulid])
        ->and($ids)->not->toContain($ctx['foreignEnvelope']->ulid);
});

test('a busca global não vaza envelopes nem signatários de outra organização', function () {
    $ctx = twoOrganizations();
    $ctx['foreignEnvelope']->update(['title' => 'Contrato Secreto Alheio']);
    Envelope::factory()->forOrganization($ctx['mine']['organization'], $ctx['mine']['owner'])->create(['title' => 'Contrato Meu']);

    actingAsMember($ctx['mine']['owner'], $ctx['mine']['organization']);

    $response = $this->getJson(route('search.index', ['q' => 'Contrato']));

    $response->assertOk();
    expect(collect($response->json('envelopes'))->pluck('title')->all())->toBe(['Contrato Meu'])
        ->and($response->json('recipients'))->toBe([]);
});

test('membro (member) só vê os próprios envelopes; owner e admin veem todos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $member = attachMember($organization, MembershipRole::Member);

    $ownerEnvelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $memberEnvelope = Envelope::factory()->forOrganization($organization, $member)->inProgress()->create();

    actingAsMember($member, $organization);
    $list = $this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'];
    expect(collect($list)->pluck('id')->all())->toBe([$memberEnvelope->ulid]);
    $this->get(route('envelopes.show', $ownerEnvelope))->assertForbidden();
    $this->get(route('envelopes.show', $memberEnvelope))->assertOk();

    actingAsMember($admin, $organization);
    $list = $this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'];
    expect(collect($list)->pluck('id')->sort()->values()->all())->toBe(collect([$ownerEnvelope->ulid, $memberEnvelope->ulid])->sort()->values()->all());
    $this->get(route('envelopes.show', $memberEnvelope))->assertOk();
});
