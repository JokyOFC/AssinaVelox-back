<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 *
 * @property int $organization_id
 * @property-read Organization $organization
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('organization_id'))) {
                $currentId = CurrentOrganization::instance()->id();

                if ($currentId !== null) {
                    $model->setAttribute('organization_id', $currentId);
                }
            }
        });
    }

    /**
     * Route model binding escopado (ajuste B2, documentado em docs/autorizacao-e-isolamento.md):
     * além do escopo global, exige explicitamente a organização corrente. Sem organização
     * corrente (rota fora do middleware `org`) o binding falha fechado → 404. Rotas admin
     * nunca fazem binding de models escopados; usam forOrganization() nas consultas.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $organizationId = CurrentOrganization::instance()->id();

        if ($organizationId === null) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this->newQuery(), $value, $field)
            ->where($this->qualifyColumn('organization_id'), $organizationId)
            ->first();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Consulta sem o escopo da organização corrente (jobs, comandos, admin).
     *
     * @return Builder<static>
     */
    public static function withoutOrganizationScope(): Builder
    {
        return static::query()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Consulta explícita para uma organização, ignorando a organização corrente.
     *
     * @return Builder<static>
     */
    public static function forOrganization(Organization|int $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        $query = static::withoutOrganizationScope();

        return $query->where($query->qualifyColumn('organization_id'), $id);
    }

    /**
     * Escopo local equivalente a forOrganization(), encadeável.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutOrganizationScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class);
    }
}
