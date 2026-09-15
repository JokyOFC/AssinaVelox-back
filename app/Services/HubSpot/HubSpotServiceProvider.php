<?php

namespace App\Services\HubSpot;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * App HubSpot (Fase 3 §3.9, G-CONN). Registrado em bootstrap/providers.php.
 *
 * Só o gancho da trilha → atualização do negócio/contato (HubSpotEnvelopeSync). Com a flag
 * global `hubspot` desligada o listener sai antes de qualquer consulta.
 */
final class HubSpotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen('eloquent.created: '.AuditEvent::class, [HubSpotEnvelopeSync::class, 'onAuditEventCreated']);
    }
}
