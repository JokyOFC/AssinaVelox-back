<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Policies\Concerns\ResolvesMembership;
use App\Services\Organizations\EnvelopeVisibility;

/**
 * Webhooks de saída (descoberta automática: App\Models\WebhookEndpoint → esta policy).
 * Toda ação exige `manage_integrations` na organização DO ENDPOINT (membership ativa).
 *
 * A flag `outbound_webhooks` não é conferida aqui: os controllers respondem 404 com ela
 * desligada. O isolamento vem do binding escopado (404 fora da organização corrente) e de
 * `membershipFor($user, organization_id)`. Os REST Hooks (API) devem chamar esta mesma policy
 * com o autor do token, além de conferir a ability `webhooks:manage`.
 */
class WebhookEndpointPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ManageIntegrations);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageIntegrations);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, Permission::ManageIntegrations, $endpoint->organization_id);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, Permission::ManageIntegrations, $endpoint->organization_id);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, Permission::ManageIntegrations, $endpoint->organization_id);
    }

    /**
     * Mudar PARA ONDE (URL) e COM QUE CHAVE (segredo) o endpoint entrega. O endpoint continua
     * agindo com a visibilidade de quem responde por ele (`created_by_user_id`), então só pode
     * redirecioná-lo quem já enxerga pelo menos o mesmo: o próprio responsável ou quem tem
     * `view_all_envelopes`. Sem isso, uma função com `manage_integrations` e visão restrita
     * apontaria o endpoint do proprietário para a própria URL e receberia eventos de envelopes
     * que a interface esconde dela (docs/fase-2/webhooks.md §7). Vale também para REST Hooks: só
     * o criador do token (responsável) ou quem vê tudo.
     */
    public function redirect(User $user, WebhookEndpoint $endpoint): bool
    {
        $membership = $this->membershipFor($user, $endpoint->organization_id);

        if ($membership === null || ! $membership->hasPermission(Permission::ManageIntegrations)) {
            return false;
        }

        return (int) $endpoint->created_by_user_id === (int) $user->getKey()
            || EnvelopeVisibility::canViewAll($membership);
    }
}
