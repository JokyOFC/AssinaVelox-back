<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('visitantes são redirecionados para o login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('usuário autenticado sem organização é enviado para criar uma', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));
});

test('o dashboard renderiza as props do contrato com dados reais', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    Envelope::factory()->forOrganization($organization, $owner)->inProgress()->count(2)->create();
    Envelope::factory()->forOrganization($organization, $member)->completed()->create();
    Envelope::factory()->forOrganization($organization, $member)->draft()->create();

    actingAsMember($owner, $organization);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('range', '30d')
            ->where('greeting.pending_count', 2)
            ->has('kpis.sent.value')
            ->has('kpis.pending.expiring_48h')
            ->has('kpis.completed.completion_rate_pct')
            ->has('kpis.avg_time_to_complete.minutes')
            ->has('chart.buckets', 30)
            ->has('chart.axis_labels')
            ->has('pending_recipients')
            ->has('plan_usage.plan_name')
            ->where('plan_usage.envelopes.limit', 5)
            ->has('recent_envelopes', 4)
            ->where('recent_total', 4)
        );
});

test('membro vê apenas os próprios documentos no dashboard', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    Envelope::factory()->forOrganization($organization, $owner)->inProgress()->count(3)->create();
    Envelope::factory()->forOrganization($organization, $member)->inProgress()->create();

    actingAsMember($member, $organization);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('greeting.pending_count', 1)
            ->where('recent_total', 1)
            ->has('recent_envelopes', 1)
        );
});

test('o intervalo aceita 90d e 12m e gera os buckets correspondentes', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('dashboard', ['range' => '12m']))
        ->assertInertia(fn (Assert $page) => $page->where('range', '12m')->has('chart.buckets', 12));

    $this->get(route('dashboard', ['range' => '90d']))
        ->assertInertia(fn (Assert $page) => $page->where('range', '90d')->has('chart.buckets', 90));

    $this->get(route('dashboard', ['range' => 'xx']))->assertSessionHasErrors('range');
});

test('a exportação devolve CSV com cabeçalho em português', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    Envelope::factory()->forOrganization($organization, $owner)->completed()->create(['title' => 'Contrato CSV']);
    actingAsMember($owner, $organization);

    $response = $this->get(route('dashboard.export', ['range' => '12m']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    $content = $response->streamedContent();
    expect($content)->toContain('Código;Título;Status')->toContain('Contrato CSV');
});
