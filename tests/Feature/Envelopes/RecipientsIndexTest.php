<?php

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(function () {
    $this->withoutVite();

    // Relógio congelado no MEIO do dia do fuso da organização (America/Sao_Paulo).
    // `RecipientController::kpis()` recorta "hoje" nesse fuso; sem fixar o tempo, um
    // `signed_at = now()->subHours(2)` executado entre 03:00 e 05:00 UTC cai no dia
    // anterior de São Paulo e o KPI "assinados hoje" responde 0. O teste passava por
    // acidente — provava o relógio da máquina, não o controller.
    $this->travelTo(Carbon::parse('2026-06-10 15:00:00', 'UTC'));
});

test('a listagem de assinaturas traz filtros, KPIs, abas e linhas paginadas', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create([
        'title' => 'Contrato Paulista',
        'sent_at' => now()->subHours(4),
    ]);

    $signed = Recipient::factory()->forEnvelope($envelope)->signed()->create([
        'name' => 'Maria A. Souza',
        'email' => 'maria@exemplo.com',
        'signed_at' => now()->subHours(2),
    ]);
    Recipient::factory()->forEnvelope($envelope, 2)->notified()->create(['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com']);
    Recipient::factory()->forEnvelope($envelope, 3)->viewed()->create(['email' => 'viu@exemplo.com']);
    Recipient::factory()->forEnvelope($envelope, 4)->refused('Valores divergentes.')->create(['email' => 'recusou@exemplo.com']);
    Recipient::factory()->forEnvelope($envelope, 5)->expired()->create(['email' => 'expirou@exemplo.com']);

    actingAsMember($owner, $organization);

    $this->get(route('recipients.index'))->assertInertia(fn (Assert $page) => $page
        ->component('recipients/index')
        ->where('filters.status', 'all')
        ->where('filters.sort', 'recent')
        ->where('tabs.all', 5)
        ->where('tabs.pending', 2)
        ->where('tabs.signed', 1)
        ->where('tabs.refused', 1)
        ->where('tabs.expired', 1)
        ->where('kpis.signed_today.value', 1)
        ->where('kpis.pending.value', 2)
        ->where('kpis.pending.viewed', 1)
        ->where('kpis.refused_30d.value', 1)
        ->where('kpis.refused_30d.pct_of_total', 20)
        ->where('kpis.avg_minutes_to_sign.value', 120)
        ->has('recipients.data', 5)
        ->has('recipients.meta.total')
        ->has('recipients.links')
        ->where('can.resend_pending', true));

    $this->get(route('recipients.index', ['status' => 'signed']))->assertInertia(fn (Assert $page) => $page
        ->has('recipients.data', 1)
        ->where('recipients.data.0.id', $signed->ulid)
        ->where('recipients.data.0.name', 'Maria A. Souza')
        ->where('recipients.data.0.status_label', 'Assinado')
        ->where('recipients.data.0.envelope.display_code', $envelope->display_code)
        ->where('recipients.data.0.envelope.title', 'Contrato Paulista')
        ->where('recipients.data.0.channel', 'email')
        ->where('recipients.data.0.auth_methods.0', 'email_otp'));

    $this->get(route('recipients.index', ['q' => 'Carlos']))
        ->assertInertia(fn (Assert $page) => $page->has('recipients.data', 1));

    $this->get(route('recipients.index', ['q' => 'Paulista']))
        ->assertInertia(fn (Assert $page) => $page->has('recipients.data', 5));

    $this->get(route('recipients.index', ['q' => $envelope->display_code]))
        ->assertInertia(fn (Assert $page) => $page->has('recipients.data', 5));

    $this->get(route('recipients.index', ['status' => 'invalida']))->assertSessionHasErrors('status');
});

test('a nota da linha reflete o status (ROUTES §2.9)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    $signed = Recipient::factory()->forEnvelope($envelope)->signed()->create(['email' => 'assinou@exemplo.com']);
    SignatureAcceptance::factory()->create([
        'recipient_id' => $signed->id,
        'envelope_id' => $envelope->id,
        'organization_id' => $organization->id,
        'ip_address' => '187.12.44.9',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Safari/604.1',
    ]);

    Recipient::factory()->forEnvelope($envelope, 2)->refused('Valores divergentes.')->create(['email' => 'recusou@exemplo.com']);
    $pending = Recipient::factory()->forEnvelope($envelope, 3)->pending()->create(['email' => 'aguarda@exemplo.com']);

    actingAsMember($owner, $organization);

    $notes = collect($this->get(route('recipients.index'))->viewData('page')['props']['recipients']['data'])
        ->keyBy('id');

    expect($notes[$signed->ulid]['note'])->toContain('IP 187.12.44.9');
    expect($notes[$signed->ulid]['note'])->toContain('iPhone (Safari)');
    expect($notes[$pending->ulid]['note'])->toBe('Aguarda a vez · 3.º na ordem');
    expect(collect($notes)->pluck('note')->join(' | '))->toContain('Motivo: “Valores divergentes.”');
});

test('a listagem respeita a visibilidade por papel', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    $mine = Envelope::factory()->forOrganization($organization, $member)->inProgress()->create();
    Recipient::factory()->forEnvelope($mine)->notified()->create(['email' => 'meu@exemplo.com']);

    $theirs = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    Recipient::factory()->forEnvelope($theirs)->notified()->create(['email' => 'deles@exemplo.com']);

    actingAsMember($member, $organization);

    $this->get(route('recipients.index'))->assertInertia(fn (Assert $page) => $page
        ->has('recipients.data', 1)
        ->where('recipients.data.0.email', 'meu@exemplo.com')
        ->where('tabs.all', 1)
        ->where('kpis.pending.value', 1)
        ->where('can.resend_pending', false));

    actingAsMember($owner, $organization);

    $this->get(route('recipients.index'))->assertInertia(fn (Assert $page) => $page
        ->has('recipients.data', 2)
        ->where('tabs.all', 2));
});

test('signatários de outra organização nunca aparecem', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();

    $alien = Envelope::factory()->forOrganization($other, $otherOwner)->inProgress()->create();
    Recipient::factory()->forEnvelope($alien)->notified()->create(['email' => 'alheio@exemplo.com']);

    actingAsMember($owner, $organization);

    $this->get(route('recipients.index'))->assertInertia(fn (Assert $page) => $page
        ->has('recipients.data', 0)
        ->where('tabs.all', 0));
});

test('o filtro de período usa a data de referência da linha', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    Recipient::factory()->forEnvelope($envelope)->signed()->create(['email' => 'recente@exemplo.com', 'signed_at' => now()->subDay()]);
    Recipient::factory()->forEnvelope($envelope, 2)->signed()->create(['email' => 'antigo@exemplo.com', 'signed_at' => now()->subDays(60)]);

    actingAsMember($owner, $organization);

    $this->get(route('recipients.index', ['period_from' => now()->subDays(7)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('recipients.data', 1)
            ->where('recipients.data.0.email', 'recente@exemplo.com'));
});

test('o CSV usa os mesmos filtros e neutraliza prefixo de fórmula', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    Recipient::factory()->forEnvelope($envelope)->signed()->create([
        'name' => '=HYPERLINK("http://atacante.example/x";"Clique")',
        'email' => 'assinou@exemplo.com',
    ]);
    Recipient::factory()->forEnvelope($envelope, 2)->notified()->create(['email' => 'pendente@exemplo.com']);

    actingAsMember($owner, $organization);

    $response = $this->get(route('recipients.export', ['status' => 'signed']));
    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain("'=HYPERLINK");
    expect($csv)->toContain('assinou@exemplo.com');
    expect($csv)->not->toContain('pendente@exemplo.com');
});

test('a nota de "visualizou" usa o evento invitation.opened da trilha', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->viewed()->create(['email' => 'viu@exemplo.com']);

    AuditEvent::query()->create([
        'organization_id' => $organization->id,
        'envelope_id' => $envelope->id,
        'recipient_id' => $recipient->id,
        'actor_type' => ActorType::Recipient,
        'actor_id' => $recipient->id,
        'event_type' => AuditEventType::InvitationOpened,
        'occurred_at' => now()->subHour(),
    ]);

    actingAsMember($owner, $organization);

    $response = $this->get(route('recipients.index'));
    $response->assertOk();

    $row = collect($response->viewData('page')['props']['recipients']['data'])->first();

    expect($row['note'])->toStartWith('Visualizou em ');
    expect($row['when'])->not->toBe('');
});
