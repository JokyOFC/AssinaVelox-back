<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Tag;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Tags\EnvelopeTagIndex;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrgHelpers.php';

beforeEach(fn () => $this->withoutVite());

function tagIn($organization, string $name, string $color = 'blue'): Tag
{
    $tag = new Tag;
    $tag->forceFill(['organization_id' => $organization->id, 'name' => $name, 'color' => $color])->save();

    return $tag;
}

test('com a flag desligada a página mostra o estado Fase 2 e as ações respondem 403', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('settings.tags'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/tags')
        ->where('enabled', false)
        ->missing('tags'));

    $this->post(route('tags.store'), ['name' => 'Locação', 'color' => 'blue'])->assertForbidden();
    expect(Tag::withoutOrganizationScope()->count())->toBe(0);
});

test('owner cria, renomeia e exclui etiquetas com trilha; nome é único sem diferenciar maiúsculas', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['tags']);
    actingAsMember($owner, $organization);

    $this->post(route('tags.store'), ['name' => '  Locação   residencial ', 'color' => 'green'])->assertRedirect();
    $tag = Tag::query()->firstOrFail();
    expect($tag->name)->toBe('Locação residencial')->and($tag->color->value)->toBe('green');

    $this->post(route('tags.store'), ['name' => 'LOCAÇÃO RESIDENCIAL', 'color' => 'blue'])->assertSessionHasErrors('name');
    $this->post(route('tags.store'), ['name' => 'Outra', 'color' => '#ff0000'])->assertSessionHasErrors('color');

    $this->patch(route('tags.update', $tag), ['name' => 'Locação', 'color' => 'red'])->assertRedirect();
    expect($tag->fresh()->name)->toBe('Locação');

    $envelope = orgEnvelope($organization, $owner, EnvelopeStatus::Draft);
    DB::table('envelope_tag')->insert(['organization_id' => $organization->id, 'envelope_id' => $envelope->id, 'tag_id' => $tag->id, 'created_at' => now()]);

    $this->delete(route('tags.destroy', $tag))->assertRedirect();
    expect(Tag::query()->count())->toBe(0)
        ->and(DB::table('envelope_tag')->count())->toBe(0)
        ->and(Envelope::query()->whereKey($envelope->id)->exists())->toBeTrue();

    $types = AuditEvent::forOrganization($organization)->pluck('event_type')->map->value->all();
    expect($types)->toContain('tag.created', 'tag.updated', 'tag.deleted');
    expect(AuditEvent::forOrganization($organization)->whereNotNull('envelope_id')->where('event_type', 'like', 'tag%')->count())->toBe(0);
});

test('criar etiqueta exige manage_tags; operador só vê a lista', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    orgEnableTools($organization, ['tags']);
    $member = attachMember($organization, MembershipRole::Member);
    tagIn($organization, 'Urgente');
    actingAsMember($member, $organization);

    $this->get(route('settings.tags'))->assertInertia(fn (Assert $page) => $page
        ->where('enabled', true)
        ->where('can.manage', false)
        ->has('tags', 1));

    $this->post(route('tags.store'), ['name' => 'Nova', 'color' => 'blue'])->assertForbidden();
    $this->delete(route('tags.destroy', Tag::query()->first()))->assertForbidden();
});

test('etiquetas são isoladas por organização (binding, aplicação e listagem)', function () {
    $a = createOrganizationWithOwner();
    $b = createOrganizationWithOwner();
    orgEnableTools($a['organization'], ['tags']);
    orgEnableTools($b['organization'], ['tags']);

    $tagB = tagIn($b['organization'], 'Da outra conta');
    $envelopeA = orgEnvelope($a['organization'], $a['owner'], EnvelopeStatus::Draft);
    $envelopeB = orgEnvelope($b['organization'], $b['owner'], EnvelopeStatus::Draft);

    actingAsMember($a['owner'], $a['organization']);

    $this->patch(route('tags.update', $tagB->ulid), ['name' => 'X', 'color' => 'blue'])->assertNotFound();
    $this->delete(route('tags.destroy', $tagB->ulid))->assertNotFound();

    // Etiqueta de outra organização não passa na validação.
    $this->post(route('envelopes.tags.apply'), ['tag' => $tagB->ulid, 'ids' => [$envelopeA->ulid]])->assertSessionHasErrors('tag');

    // Envelope de outra organização é simplesmente ignorado.
    $tagA = tagIn($a['organization'], 'Minha');
    $this->post(route('envelopes.tags.apply'), ['tag' => $tagA->ulid, 'ids' => [$envelopeB->ulid]])->assertRedirect();
    expect(DB::table('envelope_tag')->count())->toBe(0);

    $this->get(route('settings.tags'))->assertInertia(fn (Assert $page) => $page
        ->has('tags', 1)
        ->where('tags.0.name', 'Minha'));
});

test('aplicar etiqueta respeita a edição do envelope (operador só nos próprios)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['tags']);
    $member = attachMember($organization, MembershipRole::Member);
    $tag = tagIn($organization, 'Revisar');

    $own = orgEnvelope($organization, $member, EnvelopeStatus::Draft);
    $ownersDraft = orgEnvelope($organization, $owner, EnvelopeStatus::Draft);

    actingAsMember($member, $organization);

    $this->post(route('envelopes.tags.apply'), ['tag' => $tag->ulid, 'ids' => [$own->ulid, $ownersDraft->ulid]])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DB::table('envelope_tag')->pluck('envelope_id')->all())->toBe([$own->id]);

    // Remover do envelope do owner: o member nem o vê → 403.
    DB::table('envelope_tag')->insert(['organization_id' => $organization->id, 'envelope_id' => $ownersDraft->id, 'tag_id' => $tag->id, 'created_at' => now()]);
    $this->delete(route('envelopes.tags.detach', [$ownersDraft->ulid, $tag->ulid]))->assertForbidden();
    expect(DB::table('envelope_tag')->where('envelope_id', $ownersDraft->id)->exists())->toBeTrue();

    $this->delete(route('envelopes.tags.detach', [$own->ulid, $tag->ulid]))->assertRedirect();
    expect(DB::table('envelope_tag')->where('envelope_id', $own->id)->exists())->toBeFalse();

    $applied = AuditEvent::forOrganization($organization)->where('event_type', AuditEventType::TagsApplied->value)->firstOrFail();
    expect($applied->envelope_id)->toBeNull()
        ->and($applied->payload['envelopes'])->toBe([$own->ulid]);
});

test('função personalizada sem edição de outros não etiqueta documentos alheios visíveis por pasta', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['tags']);
    $folder = folderIn($organization, 'Contratos');
    $role = createCustomRole($organization, 'Leitor de contratos', [Permission::CreateEnvelopes, Permission::ManageTags]);
    $reader = attachWithCustomRole($organization, $role);
    grantFolder($folder, 'role', $role->id);

    $inFolder = orgEnvelope($organization, $owner, EnvelopeStatus::Draft, ['folder_id' => $folder->id]);
    $tag = tagIn($organization, 'Pasta');

    actingAsMember($reader, $organization);

    $this->post(route('envelopes.tags.apply'), ['tag' => $tag->ulid, 'ids' => [$inFolder->ulid]])
        ->assertSessionHas('warning');

    expect(DB::table('envelope_tag')->count())->toBe(0);
});

test('EnvelopeTagIndex filtra só dentro do que a pessoa vê e monta chips por envelope', function () {
    ['organization' => $organization, 'owner' => $owner, 'membership' => $ownerMembership] = createOrganizationWithOwner();
    orgEnableTools($organization, ['tags']);
    $member = attachMember($organization, MembershipRole::Member);
    $tag = tagIn($organization, 'Cliente VIP', 'navy');

    $mine = orgEnvelope($organization, $member, EnvelopeStatus::Draft);
    $others = orgEnvelope($organization, $owner, EnvelopeStatus::Draft);
    $untagged = orgEnvelope($organization, $member, EnvelopeStatus::Draft);

    foreach ([$mine, $others] as $envelope) {
        DB::table('envelope_tag')->insert(['organization_id' => $organization->id, 'envelope_id' => $envelope->id, 'tag_id' => $tag->id, 'created_at' => now()]);
    }

    actingAsMember($member, $organization);
    $this->get(route('dashboard')); // resolve a organização corrente

    $memberMembership = membershipOf($member, $organization);
    $index = EnvelopeTagIndex::for($memberMembership, $tag->ulid);

    $ids = $index->constrain(EnvelopeVisibility::envelopes($memberMembership))->pluck('id')->all();
    expect($ids)->toBe([$mine->id]);

    $props = $index->props(Envelope::withoutOrganizationScope()->whereKey([$mine->id, $untagged->id])->get());
    expect($props['enabled'])->toBeTrue()
        ->and($props['can_manage'])->toBeFalse()
        ->and($props['by_envelope'])->toHaveKey($mine->ulid)
        ->and($props['by_envelope'])->not->toHaveKey($untagged->ulid)
        ->and($props['by_envelope'][$mine->ulid][0])->toBe(['id' => $tag->ulid, 'name' => 'Cliente VIP', 'color' => 'navy']);

    // Flag desligada: nada muda na consulta.
    orgEnableTools($organization, ['tags'], false);
    $off = EnvelopeTagIndex::for($ownerMembership->fresh(), $tag->ulid);
    expect($off->enabled())->toBeFalse()
        ->and($off->props([])['enabled'])->toBeFalse();
});
