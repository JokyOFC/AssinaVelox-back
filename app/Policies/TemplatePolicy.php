<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Template;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Modelos (descoberta automática: App\Models\Template → TemplatePolicy).
 *
 *  - ver a galeria e um modelo: qualquer membro ativo da organização do modelo;
 *  - criar, editar (nova versão), trocar arquivo, duplicar, arquivar e restaurar:
 *    `manage_templates`;
 *  - usar (gerar envelope): `create_envelopes` e o modelo ativo com versão.
 *
 * A flag `templates` NÃO é conferida aqui (ela só liga a interface e as rotas — os
 * controllers respondem 404 com a flag desligada). O isolamento vem do binding escopado e de
 * `membershipFor($user, $template->organization_id)`: membership de outra organização não
 * autoriza nada.
 */
class TemplatePolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    public function view(User $user, Template $template): bool
    {
        return $this->membershipFor($user, $template->organization_id) !== null;
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageTemplates);
    }

    public function update(User $user, Template $template): bool
    {
        return $this->allows($user, Permission::ManageTemplates, $template->organization_id);
    }

    public function duplicate(User $user, Template $template): bool
    {
        return $this->update($user, $template);
    }

    public function archive(User $user, Template $template): bool
    {
        return $this->update($user, $template);
    }

    public function restore(User $user, Template $template): bool
    {
        return $this->update($user, $template);
    }

    public function use(User $user, Template $template): bool
    {
        return $template->isUsable() && $this->allows($user, Permission::CreateEnvelopes, $template->organization_id);
    }
}
