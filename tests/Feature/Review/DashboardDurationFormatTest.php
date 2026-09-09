<?php

use App\Models\Envelope;
use App\Models\Recipient;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (experiência) — o mesmo indicador, dois formatos, um deles ilegível
|--------------------------------------------------------------------------
| "Tempo médio para assinar" aparece em duas telas, com o mesmo significado e o mesmo
| dado em minutos:
|
|   pages/recipients/index.tsx:336-338   value={formatDuration(kpis.avg_minutes_to_sign.value)}
|   pages/dashboard.tsx:390-405          value={formatNumber(Math.round(minutes))}  unit="min"
|
| `formatDuration` (lib/format.ts:350) já existe e já resolve o caso: < 60 min → "42 min";
| < 24 h → "3 h 20 min"; acima → "1 d 4 h".
|
| O dashboard não a usa. Com dados reais — contratos levam dias, não minutos — o cartão
| mostra o número cru em minutos com separador de milhar pt-BR. Observado no navegador,
| na organização de demonstração:
|
|   Dashboard    "Tempo médio para assinar  1.698min"
|   Assinaturas  "Tempo médio para assinar  13 h"
|
| "1.698min" é ao mesmo tempo indecifrável (o leitor precisa dividir por 60 e por 24 de
| cabeça) e ambíguo: em pt-BR o ponto separa milhar, mas num cartão de métrica ele é lido
| como decimal por muita gente — "1,7 minuto". Os dois cartões estão a um clique de
| distância e não batem.
|
| O delta tem o mesmo problema (dashboard.tsx:409): `${…delta_minutes} min`, também sem
| conversão.
*/

it('o dashboard usa o mesmo formatador de duração da tela Assinaturas', function () {
    $dashboard = (string) file_get_contents(resource_path('js/pages/dashboard.tsx'));
    $recipients = (string) file_get_contents(resource_path('js/pages/recipients/index.tsx'));

    // Controle: a tela Assinaturas já formata a duração.
    expect($recipients)->toContain('formatDuration(kpis.avg_minutes_to_sign.value)');

    expect($dashboard)->toContain('formatDuration');
});

it('o KPI do dashboard entrega minutos crus mesmo quando a média passa de um dia', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    // Envelope concluído 28 h depois do envio — o normal para um contrato.
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->completed()->create([
        'sent_at' => now()->subDays(3),
        'completed_at' => now()->subDays(3)->addHours(28),
    ]);

    Recipient::factory()->forEnvelope($envelope)->signed()->create();

    actingAsMember($owner, $organization);

    $kpi = $this->withoutVite()
        ->get(route('dashboard'))
        ->assertOk()
        ->viewData('page')['props']['kpis']['avg_time_to_complete'];

    expect($kpi['minutes'])->toBe(28 * 60);

    // O front renderiza formatNumber(minutes) + " min" → "1.680min". Se o dashboard
    // usasse formatDuration, este mesmo valor viraria "1 d 4 h".
    $dashboard = (string) preg_replace(
        '/\s+/',
        ' ',
        (string) file_get_contents(resource_path('js/pages/dashboard.tsx'))
    );

    expect($dashboard)->not->toContain(
        "label=\"Tempo médio para assinar\" value={ kpis.avg_time_to_complete.minutes === null ? '—' : formatNumber("
    );
});
