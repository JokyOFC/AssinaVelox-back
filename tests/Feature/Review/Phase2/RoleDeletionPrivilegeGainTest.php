<?php

/*
| Revisão adversarial da Fase 2, onda A — escalada de privilégio.
|
| RoleController::destroy move quem tinha a função para Operador ("a direção segura: nunca
| ganha poder"). Mas uma função personalizada pode ser MAIS restrita que Operador (ex.: só
| `view_reports`, sem criar/enviar/exportar). Excluir essa função dá aos seus membros — e aos
| convites pendentes com ela — `create_envelopes`, `send_envelopes` e `export_data`, que
| ninguém concedeu. O mesmo acontece com os convites pendentes.
*/

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\MembershipInvitation;
use App\Support\PermissionsInvitationGrants;

require_once __DIR__.'/../../Phase2/Permissions/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    enableCustomRoles();
});

test('excluir uma função mais restrita que Operador não dá permissões novas a quem a tinha', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $role = createCustomRole($organization, 'Somente relatórios', [Permission::ViewReports]);
    $auditor = attachWithCustomRole($organization, $role);

    $before = membershipOf($auditor, $organization)->grantedPermissions();
    expect($before)->toBe([Permission::ViewReports]);

    actingAsMember($owner, $organization);
    $this->delete(route('roles.destroy', $role))->assertRedirect();

    $after = membershipOf($auditor, $organization)->fresh()->grantedPermissions();

    // Nenhuma permissão que a pessoa não tinha pode aparecer só porque a função sumiu.
    $gained = array_values(array_filter($after, fn (Permission $p): bool => ! in_array($p, $before, true)));

    expect(array_map(fn (Permission $p): string => $p->value, $gained))->toBe([]);
});

test('excluir uma função restrita não transforma convites pendentes em convites de Operador', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $role = createCustomRole($organization, 'Somente relatórios', [Permission::ViewReports]);

    [$invitation] = pendingInvitationWithToken([
        'organization_id' => $organization->id,
        'email' => 'auditor-externo@example.com',
        'role' => MembershipRole::Member,
        'invited_by_user_id' => $owner->id,
    ]);
    PermissionsInvitationGrants::store($invitation, $role, []);

    actingAsMember($owner, $organization);
    $this->delete(route('roles.destroy', $role))->assertRedirect();

    $invitation = MembershipInvitation::withoutGlobalScopes()->findOrFail($invitation->id);

    // O convite oferecia só relatórios; depois da exclusão ele passa a oferecer Operador
    // (criar, enviar, exportar) — deveria ser revogado ou bloquear a exclusão.
    $offered = PermissionsInvitationGrants::roleFor($invitation)?->grantedPermissions()
        ?? Permission::systemGrants($invitation->role);

    expect(in_array(Permission::CreateEnvelopes, $offered, true))->toBeFalse();
});
