<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Recipient;
use App\Services\AdminLog\OrganizationTrail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrgHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('com a flag desligada o registro de atividades mostra o estado Fase 2', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('settings.audit'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/audit')
        ->where('enabled', false)
        ->missing('events'));
});

test('log administrativo exige view_audit_log', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    orgEnableTools($organization, ['audit_log']);

    $member = attachMember($organization, MembershipRole::Member);
    actingAsMember($member, $organization);
    $this->get(route('settings.audit'))->assertForbidden();

    $admin = attachMember($organization, MembershipRole::Admin);
    actingAsMember($admin, $organization);
    $this->get(route('settings.audit'))->assertOk();

    // Funções personalizadas exigem a flag `custom_roles` (sem ela a função não concede nada).
    config(['assinavelox.features.custom_roles' => true]);
    $role = createCustomRole($organization, 'Auditor', [Permission::ViewAuditLog]);
    $auditor = attachWithCustomRole($organization, $role);
    actingAsMember($auditor, $organization);
    $this->get(route('settings.audit'))->assertOk();
});

test('lista só eventos administrativos da própria organização, sem dados de signatário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['audit_log']);

    // Evento administrativo (etiqueta) e evento de cobrança.
    $this->actingAs($owner);
    OrganizationTrail::record($organization->id, AuditEventType::TagCreated, ['tag' => str_repeat('A', 26), 'name' => 'Urgente', 'color' => 'red'], $owner);
    OrganizationTrail::record($organization->id, AuditEventType::RoleCreated, ['role' => str_repeat('B', 26), 'name' => 'Gerente', 'permissions' => ['view_reports', 'export_data']], $owner);

    // Eventos do ciclo do envelope, com e-mail de signatário no payload: nunca entram.
    $envelope = orgEnvelope($organization, $owner, EnvelopeStatus::InProgress);
    $recipient = Recipient::factory()->forEnvelope($envelope)->create(['email' => 'signataria@exemplo.com.br', 'name' => 'Signatária Sigilosa']);
    AuditEvent::factory()->forEnvelope($envelope)->byRecipient($recipient, '200.1.2.3')
        ->ofType(AuditEventType::AcceptanceRecorded, ['recipient_email' => 'signataria@exemplo.com.br'])->create();
    AuditEvent::factory()->forEnvelope($envelope)->ofType(AuditEventType::InvitationSent, ['email' => 'signataria@exemplo.com.br'])->create();

    // Outra organização.
    $other = createOrganizationWithOwner();
    OrganizationTrail::record($other['organization']->id, AuditEventType::TagCreated, ['name' => 'Da outra'], $other['owner']);

    actingAsMember($owner, $organization);

    $response = $this->get(route('settings.audit'));
    $response->assertInertia(fn (Assert $page) => $page
        ->where('enabled', true)
        ->has('events.data', 2)
        ->where('events.data.0.type', 'role.created')
        ->where('events.data.0.actor.name', $owner->name)
        ->where('events.data.1.label', 'Etiqueta criada')
        ->where('events.data.1.category', 'tags'));

    $content = $response->getContent();
    expect($content)->not->toContain('signataria@exemplo.com.br')
        ->not->toContain('Signatária Sigilosa')
        ->not->toContain('Da outra')
        ->not->toContain('200.1.2.3');

    // Payload bruto nunca vai para a página: só detalhes da lista fechada.
    $response->assertInertia(fn (Assert $page) => $page
        ->missing('events.data.0.payload')
        ->where('events.data.0.details.0.label', 'Nome')
        ->where('events.data.0.details.1', ['label' => 'Permissões', 'value' => '2']));

    $this->get(route('settings.audit', ['category' => 'tags']))->assertInertia(fn (Assert $page) => $page
        ->has('events.data', 1));

    $this->get(route('settings.audit', ['category' => 'envelopes']))->assertSessionHasErrors('category');
});
