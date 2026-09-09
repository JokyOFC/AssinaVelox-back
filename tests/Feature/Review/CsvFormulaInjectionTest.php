<?php

use App\Models\Envelope;
use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — exportações CSV (injeção de fórmula)
|--------------------------------------------------------------------------
| fputcsv() só escapa delimitador/aspas; células que começam com = + - @ TAB CR
| são interpretadas como fórmula pelo Excel/LibreOffice ao abrir o CSV (CWE-1236).
| App\Support\Csv::row() prefixa um apóstrofo, que os dois aplicativos consomem
| ao exibir o valor. As três exportações da Fase 1 (documentos, signatários e
| clientes do painel interno) passam por ele.
*/

test('dashboard.export neutraliza o prefixo de fórmula no título do documento', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $payload = '=HYPERLINK("http://atacante.example/x";"Clique para ver")';

    Envelope::factory()->forOrganization($organization, $owner)->create(['title' => $payload]);

    actingAsMember($owner, $organization);

    $response = $this->get(route('dashboard.export', ['range' => '30d']));
    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    // O apóstrofo entra ANTES do "=" (dentro das aspas do campo): a célula deixa de
    // ser fórmula e o texto continua legível.
    expect($csv)->toContain("'=HYPERLINK");
    expect($csv)->not->toContain(';"=HYPERLINK');
});

test('admin.organizations.export neutraliza o prefixo de fórmula no nome do proprietário', function () {
    $ownerName = '=cmd|\' /C calc\'!A0';
    $owner = User::factory()->create(['name' => $ownerName]);
    createOrganizationWithOwner([], $owner);

    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin)->get(route('admin.organizations.export'));
    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain("'=cmd|");
    expect($csv)->not->toContain(';"=cmd|');
});
