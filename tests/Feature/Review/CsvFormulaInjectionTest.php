<?php

use App\Models\Envelope;
use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — exportações CSV (injeção de fórmula)
|--------------------------------------------------------------------------
| fputcsv() só escapa delimitador/aspas; células que começam com = + - @
| são interpretadas como fórmula pelo Excel/LibreOffice ao abrir o CSV.
*/

test('dashboard.export grava o título do documento sem neutralizar prefixo de fórmula', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $payload = '=HYPERLINK("http://atacante.example/x";"Clique para ver")';

    Envelope::factory()->forOrganization($organization, $owner)->create(['title' => $payload]);

    actingAsMember($owner, $organization);

    $response = $this->get(route('dashboard.export', ['range' => '30d']));
    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    // A célula começa com "=" logo após a aspa de abertura (fputcsv só dobra as aspas internas):
    // ao abrir no Excel/LibreOffice a célula vira fórmula. Nenhum apóstrofo/TAB de neutralização.
    expect($csv)->toContain(';"=HYPERLINK(""http://atacante.example/x"";""Clique para ver"")";');
    expect($csv)->not->toContain("'=HYPERLINK");
});

test('admin.organizations.export grava nome/e-mail do proprietário sem neutralizar prefixo de fórmula', function () {
    $ownerName = '=cmd|\' /C calc\'!A0';
    $owner = User::factory()->create(['name' => $ownerName]);
    createOrganizationWithOwner([], $owner);

    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin)->get(route('admin.organizations.export'));
    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('=cmd|');
    expect($csv)->not->toContain("'=cmd|");
});
