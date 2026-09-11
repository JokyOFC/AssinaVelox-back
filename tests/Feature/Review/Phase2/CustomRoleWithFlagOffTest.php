<?php

/*
| Revisão adversarial da Fase 2, onda A — flag `custom_roles` desligada (T1/T8).
|
| Membership::customRole() não consulta a flag: com `custom_roles` desligada (interruptor
| global, ou plano rebaixado), uma membership que ficou com `role_id` continua recebendo as
| permissões da função personalizada — inclusive nas rotas `org.role` via
| Permissions::routeAllows. Ao mesmo tempo, `roles.destroy`/`roles.update` respondem 403
| (ensureCustomRoles), então a organização nem consegue remover a função.
|
| (A pendência 2 do relatório de integração cobre só `folder_permissions`; esta é a parte
| das permissões de conta.)
*/

use App\Enums\Permission;

require_once __DIR__.'/../../Phase2/Permissions/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
});

test('com custom_roles desligada, a função personalizada deixa de conceder permissões de conta', function () {
    enableCustomRoles();

    ['organization' => $organization] = createOrganizationWithOwner();
    $role = createCustomRole($organization, 'Gestor de configurações', [
        Permission::CreateEnvelopes,
        Permission::SendEnvelopes,
        Permission::ManageSettings,
        Permission::ViewAllEnvelopes,
    ]);
    $manager = attachWithCustomRole($organization, $role);

    // Plano rebaixado / interruptor global desligado.
    enableCustomRoles(false);

    actingAsMember($manager, $organization);

    // Na Fase 1 (flag desligada) quem não é owner/admin recebe 403 aqui.
    $this->get(route('settings.general'))->assertForbidden();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('organization.permissions.view_all_envelopes', false)
        ->where('organization.permissions.manage_settings', false));
});
