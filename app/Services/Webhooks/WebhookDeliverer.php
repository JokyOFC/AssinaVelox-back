<?php

namespace App\Services\Webhooks;

use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * UMA tentativa de entrega (docs/fase-2/webhooks.md §4 e §5).
 *
 * 1. Reivindica a entrega com UPDATE condicional (status aberto, sem trava vigente e, conforme
 *    o gatilho, sem tentativa anterior ou com retentativa vencida). Quem não reivindica sai
 *    sem fazer nada: dois jobs para a mesma entrega nunca geram duas tentativas simultâneas.
 * 2. Para sem tentar (e cancela) se o endpoint foi removido/pausado, se a flag foi desligada
 *    ou se o responsável perdeu `manage_integrations` (pausa com aviso).
 * 3. Revalida a URL (OutboundUrlGuard — o DNS muda), assina "{timestamp}.{corpo}" com os
 *    segredos válidos agora e envia pelo WebhookTransport (pino de IP, sem redirect).
 * 4. Registra a tentativa no histórico e decide: entregue; falha com retentativa agendada
 *    (backoff); ou esgotada. Atualiza a saúde do endpoint e pausa após N falhas seguidas.
 *
 * Tentativas nunca lançam exceção para fora: com a fila `sync` o job roda dentro da requisição
 * de quem provocou o evento (um signatário, por exemplo), e a entrega não pode derrubá-la.
 */
final class WebhookDeliverer
{
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly WebhookTransport $transport,
        private readonly WebhookAlerts $alerts,
        private readonly WebhookAccess $access,
    ) {}

    public function attempt(int $deliveryId, string $trigger): ?WebhookDelivery
    {
        $now = Carbon::now();

        if (! $this->claim($deliveryId, $trigger, $now)) {
            return null;
        }

        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::withoutOrganizationScope()->find($deliveryId);

        if ($delivery === null) {
            return null;
        }

        /** @var WebhookEndpoint|null $endpoint */
        $endpoint = WebhookEndpoint::withoutOrganizationScope()->withTrashed()->find($delivery->webhook_endpoint_id);

        $stop = $this->stopReason($delivery, $endpoint);

        if ($stop !== null || $endpoint === null) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_CANCELED,
                'last_error' => $stop ?? 'endpoint_removed',
                'next_retry_at' => null,
                'locked_until' => null,
            ])->save();

            Log::info('webhooks.delivery_canceled', [
                'delivery' => $delivery->ulid,
                'organization_id' => $delivery->organization_id,
                'reason' => $delivery->last_error,
                'correlation_id' => $delivery->correlation_id,
            ]);

            return $delivery;
        }

        $attempt = $delivery->attempts + 1;
        $timestamp = $now->getTimestamp();
        $secrets = $endpoint->signingSecrets($now);

        $headers = [
            'User-Agent' => (string) config('assinavelox.webhooks.user_agent', 'AssinaVelox-Webhooks/1.0'),
            'Accept' => '*/*',
            WebhookSignature::HEADER_DELIVERY => $delivery->ulid,
            WebhookSignature::HEADER_EVENT => $delivery->event_type,
            WebhookSignature::HEADER_EVENT_ID => $delivery->event_id,
            WebhookSignature::HEADER_TIMESTAMP => (string) $timestamp,
            WebhookSignature::HEADER_ATTEMPT => (string) $attempt,
            WebhookSignature::HEADER_SIGNATURE => WebhookSignature::header($secrets, $timestamp, $delivery->payload),
        ];

        $redact = $secrets;

        foreach ($secrets as $secret) {
            $redact[] = WebhookSignature::compute($secret, $timestamp, $delivery->payload);
        }

        try {
            $target = $this->guard->inspect($endpoint->url);
            $result = $this->transport->post($target, $delivery->payload, $headers, $redact);
        } catch (BlockedOutboundUrl $blocked) {
            Log::warning('webhooks.delivery_blocked', [
                'delivery' => $delivery->ulid,
                'endpoint' => $endpoint->ulid,
                'reason' => $blocked->reason,
                'detail' => $blocked->detail,
                'correlation_id' => $delivery->correlation_id,
            ]);
            $result = TransportResult::blocked($blocked->reason);
        } catch (Throwable $exception) {
            Log::error('webhooks.delivery_internal_error', [
                'delivery' => $delivery->ulid,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 300),
                'correlation_id' => $delivery->correlation_id,
            ]);
            $result = new TransportResult(TransportResult::FAILED, errorCode: 'internal_error');
        }

        $this->record($delivery, $endpoint, $attempt, $trigger, $result, $now);

        return $delivery;
    }

    private function claim(int $deliveryId, string $trigger, Carbon $now): bool
    {
        $lockSeconds = (int) ceil(
            (float) config('assinavelox.webhooks.connect_timeout_seconds', 5)
            + (float) config('assinavelox.webhooks.timeout_seconds', 10)
        ) + 30;

        $query = WebhookDelivery::withoutOrganizationScope()
            ->whereKey($deliveryId)
            ->whereIn('status', WebhookDelivery::OPEN_STATUSES)
            ->where(static fn (Builder $query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', $now));

        if ($trigger === DeliverWebhook::TRIGGER_INITIAL) {
            $query->where('attempts', 0);
        } else {
            $query->where(static fn (Builder $query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now));
        }

        return $query->update(['locked_until' => $now->copy()->addSeconds($lockSeconds)]) === 1;
    }

    private function stopReason(WebhookDelivery $delivery, ?WebhookEndpoint $endpoint): ?string
    {
        if ($endpoint === null || $endpoint->trashed()) {
            return 'endpoint_removed';
        }

        if (! WebhooksFeature::enabled(Organization::query()->find($delivery->organization_id))) {
            return 'feature_disabled';
        }

        if ($this->access->responsibleMembership($endpoint) === null) {
            $this->alerts->pause($endpoint, WebhookEndpoint::PAUSED_CREATOR_WITHOUT_ACCESS);

            return 'endpoint_paused';
        }

        if (! $endpoint->is_active && ! $delivery->is_test) {
            return 'endpoint_paused';
        }

        // REST Hook cujo token venceu ou foi revogado depois de a entrega nascer.
        if (! $this->access->restHookTokenUsable($endpoint)) {
            return 'api_token_inactive';
        }

        return null;
    }

    private function record(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $attempt,
        string $trigger,
        TransportResult $result,
        Carbon $now,
    ): void {
        $entry = [
            'attempt' => $attempt,
            'trigger' => $trigger === DeliverWebhook::TRIGGER_MANUAL ? 'manual' : 'automatic',
            'outcome' => $result->outcome,
            'response_code' => $result->statusCode,
            'duration_ms' => $result->durationMs,
            'error' => $result->errorCode,
            'response_excerpt' => $result->excerpt,
            'remote_ip' => $result->remoteIp,
            'attempted_at' => $now->toIso8601String(),
        ];

        $history = array_slice([...($delivery->history ?? []), $entry], -self::HISTORY_LIMIT);
        $maxAttempts = $delivery->is_test ? 1 : max(1, (int) config('assinavelox.webhooks.max_attempts', 8));

        if ($result->succeeded()) {
            $status = WebhookDelivery::STATUS_DELIVERED;
            $nextRetryAt = null;
        } elseif ($attempt >= $maxAttempts) {
            $status = WebhookDelivery::STATUS_EXHAUSTED;
            $nextRetryAt = null;
        } else {
            $status = WebhookDelivery::STATUS_FAILED;
            $nextRetryAt = $now->copy()->addSeconds(self::backoffAfter($attempt));
        }

        $delivery->forceFill([
            'status' => $status,
            'attempts' => $attempt,
            'next_retry_at' => $nextRetryAt,
            'locked_until' => null,
            'last_response_code' => $result->statusCode,
            'last_duration_ms' => $result->durationMs,
            'last_error' => $result->succeeded() ? null : $result->errorCode,
            'last_attempt_at' => $now,
            'delivered_at' => $result->succeeded() ? $now : $delivery->delivered_at,
            'history' => $history,
        ])->save();

        if (! $delivery->is_test) {
            $this->updateEndpointHealth($endpoint, $result, $now);
        }

        Log::info('webhooks.delivery_attempt', [
            'delivery' => $delivery->ulid,
            'endpoint' => $endpoint->ulid,
            'organization_id' => $delivery->organization_id,
            'event_type' => $delivery->event_type,
            'attempt' => $attempt,
            'trigger' => $entry['trigger'],
            'outcome' => $result->outcome,
            'response_code' => $result->statusCode,
            'duration_ms' => $result->durationMs,
            'status' => $status,
            'correlation_id' => $delivery->correlation_id,
        ]);
    }

    private function updateEndpointHealth(WebhookEndpoint $endpoint, TransportResult $result, Carbon $now): void
    {
        $query = WebhookEndpoint::withoutOrganizationScope()->whereKey($endpoint->getKey());

        if ($result->succeeded()) {
            $query->update(['consecutive_failures' => 0, 'last_success_at' => $now]);

            return;
        }

        $query->increment('consecutive_failures', 1, ['last_failure_at' => $now]);

        $failures = (int) WebhookEndpoint::withoutOrganizationScope()->whereKey($endpoint->getKey())->value('consecutive_failures');
        $endpoint->consecutive_failures = $failures;
        $threshold = (int) config('assinavelox.webhooks.pause_after_consecutive_failures', 20);

        if ($threshold > 0 && $failures >= $threshold) {
            $this->alerts->pause($endpoint, WebhookEndpoint::PAUSED_FAILURES);
        }
    }

    /**
     * Espera depois da n-ésima tentativa falha (1 min, 5 min, 30 min, 2 h, 12 h, 24 h, 24 h...).
     */
    public static function backoffAfter(int $attempt): int
    {
        /** @var list<int> $schedule */
        $schedule = array_values(array_map('intval', (array) config('assinavelox.webhooks.backoff_seconds', [60])));

        if ($schedule === []) {
            return 60;
        }

        return max(1, $schedule[min(max($attempt, 1) - 1, count($schedule) - 1)]);
    }
}
