<?php

namespace App\Services\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;

/**
 * `webhooks:prune` (diário): o histórico de entregas não é trilha de auditoria (a trilha é
 * `audit_events`, intocada) e não precisa ficar para sempre.
 *
 *  - entregas encerradas (entregue, esgotada, cancelada) com mais de `retention_days` saem;
 *  - endpoints removidos há mais de `retention_days` saem de vez (e as entregas deles, em
 *    cascata) — o segredo, já sobrescrito na remoção, deixa de existir.
 *
 * Idempotente; roda com a flag desligada também (só apaga o que já existia).
 */
final class WebhookPruner
{
    private const CHUNK = 1000;

    /**
     * @return array{deliveries: int, endpoints: int}
     */
    public function run(): array
    {
        $cutoff = Carbon::now()->subDays(max(1, (int) config('assinavelox.webhooks.retention_days', 30)));
        $deliveries = 0;

        do {
            $ids = WebhookDelivery::withoutOrganizationScope()
                ->whereIn('status', WebhookDelivery::TERMINAL_STATUSES)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deliveries += WebhookDelivery::withoutOrganizationScope()->whereKey($ids)->delete();
        } while (count($ids) === self::CHUNK);

        $endpoints = 0;

        WebhookEndpoint::withoutOrganizationScope()
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('id')
            ->get()
            ->each(function (WebhookEndpoint $endpoint) use (&$endpoints): void {
                $endpoint->forceDelete();
                $endpoints++;
            });

        return ['deliveries' => $deliveries, 'endpoints' => $endpoints];
    }
}
