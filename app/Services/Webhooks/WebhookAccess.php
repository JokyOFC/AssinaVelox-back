<?php

namespace App\Services\Webhooks;

use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\WebhookEndpoint;
use App\Services\Organizations\EnvelopeVisibility;

/**
 * Mínimo privilégio dos webhooks (docs/fase-2/webhooks.md §7).
 *
 * Um endpoint age com as permissões de quem RESPONDE por ele (`created_by_user_id`):
 *
 *  - essa pessoa precisa de membership ativa COM `manage_integrations`; se perder, o endpoint
 *    é pausado (`creator_without_access`) e os administradores são avisados;
 *  - o endpoint só recebe eventos de envelopes que essa pessoa pode ver — a MESMA regra da
 *    interface (EnvelopeVisibility). Proprietário e administrador veem todos; uma função
 *    personalizada com `manage_integrations` mas sem `view_all_envelopes` só recebe os
 *    eventos dos envelopes que criou ou das pastas a que tem acesso.
 */
final class WebhookAccess
{
    public function responsibleMembership(WebhookEndpoint $endpoint): ?Membership
    {
        if ($endpoint->created_by_user_id === null) {
            return null;
        }

        /** @var Membership|null $membership */
        $membership = Membership::query()
            ->where('organization_id', $endpoint->organization_id)
            ->where('user_id', $endpoint->created_by_user_id)
            ->first();

        if ($membership === null || ! $membership->isActive()) {
            return null;
        }

        return $membership->hasPermission(Permission::ManageIntegrations) ? $membership : null;
    }

    public function canSee(Membership $membership, Envelope $envelope): bool
    {
        return EnvelopeVisibility::canSee($membership, $envelope);
    }

    /**
     * Assinatura REST Hook (§2.17) só recebe eventos enquanto o token que a criou é utilizável.
     * Revogar pela tela já remove as assinaturas; um token apenas VENCIDO (ou apagado) não
     * tinha ninguém para removê-las e continuaria recebendo eventos — integração I-2D.
     * Endpoints cadastrados pela tela não dependem de token.
     */
    public function restHookTokenUsable(WebhookEndpoint $endpoint): bool
    {
        if ($endpoint->source !== WebhookEndpoint::SOURCE_REST_HOOK) {
            return true;
        }

        if ($endpoint->api_token_id === null) {
            return false;
        }

        /** @var ApiToken|null $token */
        $token = ApiToken::withoutOrganizationScope()->find($endpoint->api_token_id);

        return $token !== null
            && $token->organization_id === $endpoint->organization_id
            && $token->isUsable();
    }
}
