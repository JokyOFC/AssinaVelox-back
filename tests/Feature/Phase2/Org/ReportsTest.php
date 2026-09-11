<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrgHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('com a flag desligada relatório e exportação mostram só o estado Fase 2', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnvelope($organization, $owner, EnvelopeStatus::Completed);
    actingAsMember($owner, $organization);

    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->where('enabled', false)
        ->missing('report'));

    $this->get(route('reports.export'))->assertInertia(fn (Assert $page) => $page->where('enabled', false));
});

test('owner vê totais, tempo médio, taxa de conclusão e agrupamento por usuário', function () {
    Carbon::setTestNow('2026-09-11 12:00:00');
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['reports']);
    $member = attachMember($organization, MembershipRole::Member);

    orgEnvelope($organization, $owner, EnvelopeStatus::Completed);   // 4 h
    orgEnvelope($organization, $member, EnvelopeStatus::Completed, ['completed_at' => Carbon::now()->subDays(3)->addHours(2)]); // 2 h
    orgEnvelope($organization, $owner, EnvelopeStatus::Refused);
    orgEnvelope($organization, $owner, EnvelopeStatus::InProgress);
    orgEnvelope($organization, $owner, EnvelopeStatus::Draft);
    // Fora do período (60 dias atrás).
    orgEnvelope($organization, $owner, EnvelopeStatus::Completed, ['sent_at' => Carbon::now()->subDays(60), 'completed_at' => Carbon::now()->subDays(59)]);

    actingAsMember($owner, $organization);

    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->where('enabled', true)
        ->where('report.totals.sent', 4)
        ->where('report.totals.completed', 2)
        ->where('report.totals.refused', 1)
        ->where('report.totals.pending', 1)
        ->where('report.totals.avg_minutes_to_complete', fn ($value) => (float) $value === 180.0)
        ->where('report.totals.completion_rate', fn ($value) => (float) $value === 50.0)
        ->has('report.by_user', 2)
        ->has('report.series', 30)
        ->where('scope.all_envelopes', true)
        ->has('plan_usage.plan_name'));

    Carbon::setTestNow();
});

test('relatório nunca agrega envelope invisível ao usuário (papel restrito)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['reports']);
    $member = attachMember($organization, MembershipRole::Member);

    orgEnvelope($organization, $member, EnvelopeStatus::Completed, ['title' => 'Contrato do operador']);
    orgEnvelope($organization, $owner, EnvelopeStatus::Completed, ['title' => 'Contrato secreto do dono']);
    orgEnvelope($organization, $owner, EnvelopeStatus::Refused, ['title' => 'Outro secreto']);

    // Outra organização — nunca aparece.
    $other = createOrganizationWithOwner();
    orgEnvelope($other['organization'], $other['owner'], EnvelopeStatus::Completed, ['title' => 'Alheio']);

    actingAsMember($member, $organization);

    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.sent', 1)
        ->where('report.totals.completed', 1)
        ->where('report.totals.refused', 0)
        ->has('report.by_user', 1)
        ->where('report.by_user.0.user.id', (string) $member->id)
        ->has('options.creators', 1)
        ->where('scope.all_envelopes', false)
        ->where('plan_usage', null));

    // Filtrar pelo criador "owner" não fura a regra: continua zero.
    $this->get(route('reports.index', ['creator' => $owner->id]))->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.sent', 0)
        ->where('report.totals.completed', 0));

    $csv = $this->get(route('reports.export'))->assertOk()->streamedContent();
    expect($csv)->toContain('Contrato do operador')
        ->not->toContain('secreto')
        ->not->toContain('Alheio');
});

test('função personalizada com acesso por pasta agrega só a pasta liberada; sem view_reports é 403', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['reports']);
    $folder = folderIn($organization, 'Jurídico');
    // Funções personalizadas exigem a flag `custom_roles` (sem ela a função não concede nada).
    config(['assinavelox.features.custom_roles' => true]);
    $role = createCustomRole($organization, 'Analista', [Permission::ViewReports]);
    $analyst = attachWithCustomRole($organization, $role);
    grantFolder($folder, 'role', $role->id);

    orgEnvelope($organization, $owner, EnvelopeStatus::Completed, ['folder_id' => $folder->id]);
    orgEnvelope($organization, $owner, EnvelopeStatus::Completed);

    actingAsMember($analyst, $organization);

    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.completed', 1)
        ->has('options.folders', 1)
        ->where('can.export', false));

    // Sem export_data → exportação negada.
    $this->get(route('reports.export'))->assertForbidden();

    $noReports = createCustomRole($organization, 'Sem relatórios', [Permission::CreateEnvelopes]);
    $blind = attachWithCustomRole($organization, $noReports);
    actingAsMember($blind, $organization);
    $this->get(route('reports.index'))->assertForbidden();
});

test('filtros por etiqueta, time, pasta de outra organização e período', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['reports', 'tags']);
    $member = attachMember($organization, MembershipRole::Member);

    $tagged = orgEnvelope($organization, $owner, EnvelopeStatus::Completed);
    orgEnvelope($organization, $member, EnvelopeStatus::Completed);

    $tag = new Tag;
    $tag->forceFill(['organization_id' => $organization->id, 'name' => 'Filtro', 'color' => 'blue'])->save();
    DB::table('envelope_tag')->insert(['organization_id' => $organization->id, 'envelope_id' => $tagged->id, 'tag_id' => $tag->id, 'created_at' => now()]);

    $team = new Team;
    $team->forceFill(['organization_id' => $organization->id, 'name' => 'Comercial'])->save();
    $team->memberships()->attach(membershipOf($member, $organization)->id);

    $otherFolder = folderIn(createOrganizationWithOwner()['organization'], 'Alheia');

    actingAsMember($owner, $organization);

    $this->get(route('reports.index', ['tag' => $tag->ulid]))->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.completed', 1)
        ->has('options.tags', 1));

    $this->get(route('reports.index', ['team' => $team->ulid]))->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.completed', 1)
        ->where('report.by_user.0.user.id', (string) $member->id));

    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('report.by_team.0.team.name', 'Comercial')
        ->where('report.by_team.0.completed', 1)
        ->where('report.by_team.1.team.name', 'Sem time'));

    $this->get(route('reports.index', ['folder' => $otherFolder->ulid]))->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.completed', 0));

    $this->get(route('reports.index', ['from' => '2025-01-01', 'to' => '2026-09-11']))->assertSessionHasErrors('from');
    $this->get(route('reports.index', ['from' => '2026-09-10', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
});

test('CSV do relatório neutraliza injeção de fórmula e registra a exportação sem dados pessoais', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    orgEnableTools($organization, ['reports', 'tags']);

    $envelope = orgEnvelope($organization, $owner, EnvelopeStatus::Completed, ['title' => '=HYPERLINK("http://evil","x")']);

    $tag = new Tag;
    $tag->forceFill(['organization_id' => $organization->id, 'name' => '+cmd', 'color' => 'red'])->save();
    DB::table('envelope_tag')->insert(['organization_id' => $organization->id, 'envelope_id' => $envelope->id, 'tag_id' => $tag->id, 'created_at' => now()]);

    actingAsMember($owner, $organization);

    $response = $this->get(route('reports.export'));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")
        ->toContain("'+cmd")
        ->not->toContain(';=HYPERLINK');

    $event = AuditEvent::forOrganization($organization)->where('event_type', AuditEventType::ReportExported->value)->firstOrFail();
    expect($event->envelope_id)->toBeNull()
        ->and($event->payload['report'])->toBe('envelopes')
        ->and(json_encode($event->payload))->not->toContain('@');
});
