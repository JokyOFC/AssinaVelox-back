<?php

namespace App\Services\Webhooks;

use App\Listeners\Webhooks\QueueWebhookDeliveries;
use App\Models\AuditEvent;
use App\Support\Http\DnsResolver;
use App\Support\Http\SystemDnsResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Webhooks de saída (D-HOOK). Registrado em bootstrap/providers.php.
 *
 * O gancho escuta a criação de `audit_events` (a trilha é a fonte dos eventos publicáveis,
 * roadmap §2.16) sem tocar em nenhum serviço de domínio. Com a flag global desligada o
 * listener sai antes de qualquer consulta.
 */
final class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        Event::listen('eloquent.created: '.AuditEvent::class, [QueueWebhookDeliveries::class, 'onAuditEventCreated']);
    }
}
