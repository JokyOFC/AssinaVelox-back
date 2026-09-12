<?php

namespace App\Services\Webhooks;

use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * `webhooks:retry` (a cada minuto): despacha as entregas abertas cuja hora chegou — as
 * retentativas agendadas pelo backoff e, como rede de segurança, as iniciais cujo job se
 * perdeu. As retentativas não usam `delay()` da fila de propósito: com o driver `sync` o
 * atraso seria ignorado e todas as tentativas aconteceriam de uma vez.
 *
 * Também apaga os segredos anteriores cuja janela de rotação acabou. Inerte com a flag
 * global desligada.
 */
final class WebhookRetrySweeper
{
    public function run(?int $limit = null): int
    {
        if (! WebhooksFeature::globallyEnabled()) {
            return 0;
        }

        $now = Carbon::now();
        $limit ??= max(1, (int) config('assinavelox.webhooks.retry_batch_size', 200));

        $ids = WebhookDelivery::withoutOrganizationScope()
            ->whereIn('status', WebhookDelivery::OPEN_STATUSES)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $now)
            ->where(static fn (Builder $query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverWebhook::dispatch((int) $id, DeliverWebhook::TRIGGER_RETRY);
        }

        WebhookEndpoint::withoutOrganizationScope()
            ->whereNotNull('previous_secret_expires_at')
            ->where('previous_secret_expires_at', '<=', $now)
            ->update(['previous_secret' => null, 'previous_secret_expires_at' => null]);

        return $ids->count();
    }
}
