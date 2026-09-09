<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — teste que passava por acidente
|--------------------------------------------------------------------------
|
| `tests/Feature/Envelopes/RecipientsIndexTest.php` ("a listagem de assinaturas traz
| filtros, KPIs, abas e linhas paginadas") criava um signatário com
| `signed_at = now()->subHours(2)` e afirmava `kpis.signed_today.value === 1`.
|
| `RecipientController::kpis()` conta "hoje" no fuso da ORGANIZAÇÃO
| (`America/Sao_Paulo`, UTC-3). Entre 03:00 e 05:00 UTC — isto é, entre 00:00 e 02:00 em
| São Paulo — `now()->subHours(2)` cai no dia ANTERIOR do fuso da organização, o KPI
| responde 0 e o teste falhava. Ele não provava o que dizia provar: provava o relógio da
| máquina. Foi observado falhando de verdade nesta revisão, às 03:11 UTC.
|
| O controller estava certo; o teste é que era frouxo.
|
| VERSÃO DESTA CORREÇÃO — o teste original passou a congelar o relógio
| (`travelTo`), e este arquivo virou a guarda dessa decisão em dois níveis:
|
|  1. comportamento: com o relógio parado às 00:30 de São Paulo, o recorte "hoje" é o
|     dia LOCAL — quem assinou às 00:10 de hoje entra, quem assinou há duas horas
|     (22:30 de ontem em São Paulo) não entra;
|  2. fixture: o teste original não pode voltar a depender da hora em que a suíte roda.
*/

use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

it('conta o KPI "assinados hoje" pelo dia do fuso da organização, não pelo relógio da máquina', function () {
    // 00:30 em São Paulo = 03:30 UTC. É a janela em que o teste original quebrava.
    $this->travelTo(Carbon::parse('2026-06-10 03:30:00', 'UTC'));

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $timezone = $organization->timezone !== '' ? $organization->timezone : Organization::DEFAULT_TIMEZONE;
    $localDayStart = Carbon::now($timezone)->startOfDay();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create([
        'title' => 'Contrato Paulista',
        'sent_at' => now()->subHours(4),
    ]);

    // 00:10 de HOJE em São Paulo: dentro da janela do KPI.
    Recipient::factory()->forEnvelope($envelope)->signed()->create([
        'name' => 'Maria A. Souza',
        'email' => 'maria@exemplo.com',
        'signed_at' => $localDayStart->copy()->addMinutes(10)->utc(),
    ]);

    // 22:30 de ONTEM em São Paulo (duas horas atrás em UTC): fora da janela.
    Recipient::factory()->forEnvelope($envelope, 2)->signed()->create([
        'name' => 'Carlos Mendes',
        'email' => 'carlos@exemplo.com',
        'signed_at' => now()->subHours(2),
    ]);

    actingAsMember($owner, $organization);

    $this->get(route('recipients.index'))->assertInertia(
        fn (Assert $page) => $page
            ->component('recipients/index')
            ->where('kpis.signed_today.value', 1)
    );
});

it('mantém o teste original de KPIs independente da hora em que a suíte roda', function () {
    $source = (string) file_get_contents(base_path('tests/Feature/Envelopes/RecipientsIndexTest.php'));

    expect($source)->toContain('travelTo');
});
