<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PublicForm;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Formulários públicos (descoberta automática: App\Models\PublicForm → PublicFormPolicy).
 *
 *  - ver, criar, configurar, publicar, pausar, revogar e revisar envios: `manage_templates`
 *    (quem gere os modelos decide como eles viram formulário público);
 *  - aprovar um envio da fila (o que ENVIA o documento): `manage_templates` e `send_envelopes`.
 *
 * A flag `public_forms` NÃO é conferida aqui: os controllers respondem 404 com ela desligada.
 * O isolamento vem do binding escopado e de `membershipFor($user, organization_id)`.
 */
class PublicFormPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ManageTemplates);
    }

    public function view(User $user, PublicForm $form): bool
    {
        return $this->allows($user, Permission::ManageTemplates, $form->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageTemplates);
    }

    public function update(User $user, PublicForm $form): bool
    {
        return $this->allows($user, Permission::ManageTemplates, $form->organization_id);
    }

    public function review(User $user, PublicForm $form): bool
    {
        return $this->update($user, $form);
    }

    public function approve(User $user, PublicForm $form): bool
    {
        return $this->update($user, $form)
            && $this->allows($user, Permission::SendEnvelopes, $form->organization_id);
    }
}
