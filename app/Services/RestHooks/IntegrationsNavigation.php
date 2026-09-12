<?php

namespace App\Services\RestHooks;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\Api\ApiFeature;
use App\Services\Webhooks\WebhooksFeature;

/**
 * Quais abas de "API e integrações" existem para a organização (telas da Fase 2, §2.15–§2.17).
 *
 *  - nenhuma das flags `api_integrations`/`outbound_webhooks` → placeholder da Fase 1;
 *  - Documentação: qualquer uma delas;
 *  - Chaves e Logs: `api_integrations`;
 *  - Webhooks: `outbound_webhooks`;
 *  - REST Hooks (seção da documentação): `rest_hooks` (que já exige as duas).
 *
 * As telas reais exigem `manage_integrations`; a flag só liga a interface.
 */
final class IntegrationsNavigation
{
    public static function anyEnabled(?Organization $organization): bool
    {
        return ApiFeature::enabled($organization) || WebhooksFeature::enabled($organization);
    }

    /**
     * @return array{docs: bool, keys: bool, webhooks: bool, logs: bool, rest_hooks: bool}
     */
    public static function tabs(?Organization $organization): array
    {
        $api = ApiFeature::enabled($organization);
        $webhooks = WebhooksFeature::enabled($organization);

        return [
            'docs' => $api || $webhooks,
            'keys' => $api,
            'webhooks' => $webhooks,
            'logs' => $api,
            'rest_hooks' => RestHooksFeature::enabled($organization),
        ];
    }

    public static function canManage(?Membership $membership): bool
    {
        return $membership !== null
            && $membership->isActive()
            && $membership->hasPermission(Permission::ManageIntegrations);
    }
}
