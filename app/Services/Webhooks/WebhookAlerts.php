<?php

namespace App\Services\Webhooks;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Services\Organizations\NotificationPreferences;
use App\Support\Correlation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pausa de endpoint (manual ou automática) e aviso aos administradores.
 *
 * A pausa é condicional (`WHERE is_active = true`): duas falhas simultâneas no limite pausam
 * uma vez e avisam uma vez. Pausa manual não gera aviso.
 */
final class WebhookAlerts
{
    public function pause(WebhookEndpoint $endpoint, string $reason): bool
    {
        $now = Carbon::now();

        $updated = WebhookEndpoint::withoutOrganizationScope()
            ->whereKey($endpoint->getKey())
            ->where('is_active', true)
            ->update(['is_active' => false, 'paused_at' => $now, 'paused_reason' => $reason, 'updated_at' => $now]);

        if ($updated === 0) {
            return false;
        }

        $endpoint->forceFill(['is_active' => false, 'paused_at' => $now, 'paused_reason' => $reason])->syncOriginal();

        Log::warning('webhooks.endpoint_paused', [
            'endpoint' => $endpoint->ulid,
            'organization_id' => $endpoint->organization_id,
            'reason' => $reason,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'correlation_id' => Correlation::id(),
        ]);

        if ($reason !== WebhookEndpoint::PAUSED_MANUAL) {
            $this->notifyAdministrators($endpoint, $reason);
        }

        return true;
    }

    private function notifyAdministrators(WebhookEndpoint $endpoint, string $reason): void
    {
        try {
            $organization = Organization::query()->find($endpoint->organization_id);

            if ($organization === null) {
                return;
            }

            $memberships = Membership::query()
                ->where('organization_id', $endpoint->organization_id)
                ->with('user')
                ->get()
                ->filter(static fn (Membership $membership): bool => $membership->isActive()
                    && $membership->hasPermission(Permission::ManageIntegrations));

            $preferences = app(NotificationPreferences::class);

            foreach ($memberships as $membership) {
                // Canais que a pessoa manteve ligados (evento `webhook_failed`); nenhum = nada.
                $channels = $preferences->for($membership)['webhook_failed'] ?? [];

                if ($channels === []) {
                    continue;
                }

                $membership->user->notify((new WebhookEndpointPausedNotification(
                    organizationId: (int) $endpoint->organization_id,
                    organizationName: (string) $organization->name,
                    endpointUlid: $endpoint->ulid,
                    host: $endpoint->host(),
                    reason: $reason,
                    consecutiveFailures: (int) $endpoint->consecutive_failures,
                ))->restrictChannels($channels));
            }
        } catch (Throwable $exception) {
            // O aviso nunca desfaz a pausa nem derruba a entrega que a provocou.
            Log::error('webhooks.pause_notification_failed', [
                'endpoint' => $endpoint->ulid,
                'exception' => $exception::class,
            ]);
        }
    }
}
