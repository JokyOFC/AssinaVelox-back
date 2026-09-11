<?php

namespace App\Http\Requests\Roles\Concerns;

use App\Models\Membership;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use App\Support\Permissions;

/**
 * Organização e membership do ATOR (sempre a corrente — nunca um organization_id vindo do
 * navegador) e a checagem da flag `custom_roles` para os FormRequests de funções, times e
 * acesso por pasta.
 */
trait ResolvesAccessActor
{
    protected function organization(): ?Organization
    {
        return CurrentOrganization::instance()->get();
    }

    protected function actor(): ?Membership
    {
        return CurrentOrganization::instance()->membership();
    }

    protected function ensureFeature(): void
    {
        Permissions::ensureCustomRoles($this->organization());
    }
}
