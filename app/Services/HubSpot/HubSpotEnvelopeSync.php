<?php

namespace App\Services\HubSpot;

use App\Models\AuditEvent;
use App\Models\HubSpotActionExecution;
use App\Services\HubSpot\Jobs\SyncHubSpotObject;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gancho `eloquent.created` de AuditEvent → atualização do negócio/contato no HubSpot quando um
 * envelope criado por uma ação de workflow muda de estado (docs/fase-3/conectores.md §5.3).
 *
 * Registrado por HubSpotServiceProvider (método que NÃO se chama `handle`, para a descoberta
 * automática de eventos não registrá-lo de novo). Com a flag global desligada sai antes de
 * qualquer consulta. Nunca lança: o evento veio de uma operação de domínio que não pode ser
 * desfeita por um problema no HubSpot.
 */
final class HubSpotEnvelopeSync
{
    /** Evento da trilha → valor gravado na propriedade do HubSpot. */
    public const STATUS_BY_EVENT = [
        'envelope.sent' => 'sent',
        'envelope.completed' => 'completed',
        'envelope.refused' => 'refused',
        'envelope.canceled' => 'canceled',
        'envelope.expired' => 'expired',
    ];

    public function onAuditEventCreated(AuditEvent $event): void
    {
        if (! HubSpotFeature::globallyEnabled() || $event->envelope_id === null) {
            return;
        }

        $status = self::STATUS_BY_EVENT[$event->event_type->value] ?? null;

        if ($status === null) {
            return;
        }

        try {
            $executions = HubSpotActionExecution::withoutOrganizationScope()
                ->where('envelope_id', $event->envelope_id)
                ->where('organization_id', $event->organization_id)
                ->pluck('id');

            foreach ($executions as $id) {
                HubSpotActionExecution::withoutOrganizationScope()->whereKey($id)->update(['sync_status' => HubSpotActionExecution::SYNC_PENDING]);
                SyncHubSpotObject::dispatch((int) $id, $status)->afterCommit();
            }
        } catch (Throwable $exception) {
            Log::error('hubspot.sync_enqueue_failed', [
                'audit_event' => $event->ulid,
                'exception' => $exception::class,
            ]);
        }
    }
}
