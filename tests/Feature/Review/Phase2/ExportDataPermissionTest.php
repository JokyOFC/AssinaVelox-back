<?php

/*
| Revisão adversarial da Fase 2, onda A — autorização.
|
| A permissão `export_data` ("Exportar dados — Planilhas CSV dos documentos e assinaturas
| visíveis") só é conferida na exportação de Relatórios. As duas exportações CSV da Fase 1
| (`dashboard.export` e `recipients.export`) não a exigem: uma função personalizada SEM
| `export_data` baixa as planilhas mesmo assim.
*/

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Envelope;

require_once __DIR__.'/../../Phase2/Permissions/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    enableCustomRoles();
});

test('função personalizada sem export_data não baixa o CSV do dashboard nem o de assinaturas', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $role = createCustomRole($organization, 'Assistente sem exportação', [
        Permission::CreateEnvelopes,
        Permission::SendEnvelopes,
    ]);
    $assistant = attachWithCustomRole($organization, $role);

    Envelope::factory()->forOrganization($organization, $assistant)->create([
        'status' => EnvelopeStatus::InProgress,
        'sent_at' => now()->subDay(),
    ]);

    expect(membershipOf($assistant, $organization)->hasPermission(Permission::ExportData))->toBeFalse();

    actingAsMember($assistant, $organization);

    // Controle: a exportação de Relatórios já exige export_data (403 com a flag ligada, ou
    // o estado "Fase 2" com a flag desligada) — as outras duas deveriam seguir a mesma regra.
    $this->get(route('dashboard.export'))->assertForbidden();
    $this->get(route('recipients.export'))->assertForbidden();
});

test('controle: Operador de sistema (tem export_data) continua exportando como na Fase 1', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($member, $organization);

    $this->get(route('dashboard.export'))->assertOk();
    $this->get(route('recipients.export'))->assertOk();
});
