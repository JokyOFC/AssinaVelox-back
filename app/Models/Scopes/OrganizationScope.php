<?php

namespace App\Models\Scopes;

use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restringe consultas à organização corrente quando há uma definida.
 * Sem organização corrente (jobs, comandos, admin) nada é filtrado — o chamador deve usar
 * forOrganization() explicitamente nesses contextos.
 *
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = CurrentOrganization::instance()->id();

        if ($organizationId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
