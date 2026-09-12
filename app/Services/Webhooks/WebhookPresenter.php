<?php

namespace App\Services\Webhooks;

use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Props das telas de webhooks (contrato em docs/fase-2/webhooks.md §9). Nunca inclui o
 * segredo (só a pista `…abcd`); o segredo em claro chega à tela uma única vez, pela prop
 * `revealed_secret` que o controller lê do flash da sessão.
 *
 * Visibilidade: o vínculo com o envelope (e o payload, que o identifica) só aparece para quem
 * pode ver aquele envelope pela regra da interface (EnvelopeVisibility).
 */
final class WebhookPresenter
{
    public function __construct(private readonly WebhookAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function endpoint(WebhookEndpoint $endpoint): array
    {
        $events = $endpoint->events ?? [];

        return [
            'id' => $endpoint->ulid,
            'url' => $endpoint->url,
            'host' => $endpoint->host(),
            'description' => $endpoint->description,
            'events' => $events,
            'all_events' => in_array(WebhookEventType::ALL, $events, true),
            'status' => $endpoint->is_active ? 'active' : 'paused',
            'status_label' => $endpoint->is_active ? 'Ativo' : 'Pausado',
            'paused_at' => self::date($endpoint->paused_at),
            'paused_reason' => $endpoint->is_active ? null : $endpoint->paused_reason,
            'paused_reason_label' => $endpoint->pausedReasonLabel(),
            'consecutive_failures' => $endpoint->consecutive_failures,
            'last_success_at' => self::date($endpoint->last_success_at),
            'last_failure_at' => self::date($endpoint->last_failure_at),
            'secret_hint' => $endpoint->secret_hint,
            'secret_rotated_at' => self::date($endpoint->secret_rotated_at),
            'previous_secret_expires_at' => $endpoint->previousSecretActive() ? self::date($endpoint->previous_secret_expires_at) : null,
            'source' => $endpoint->source,
            'created_by' => $endpoint->creator?->name,
            'created_at' => self::date($endpoint->created_at),
        ];
    }

    /**
     * Linha "Webhook" nos Detalhes do envelope (roadmap §2.0/§2.16, integração I-2D): a última
     * entrega ligada ao envelope. `null` com a flag desligada ou quando a organização não tem
     * endpoint ativo nem entrega para ele — o front mantém a tela da Fase 1. Só status e rótulos
     * (nada de URL, payload ou resposta); o link para o histórico só para quem gerencia as
     * integrações. Quem chama já autorizou a visualização do envelope.
     *
     * @return array{status: string|null, status_label: string, event_label: string|null, at: string|null, href: string|null}|null
     */
    public function envelopeSummary(Envelope $envelope, ?Membership $viewer): ?array
    {
        if (! WebhooksFeature::enabled($envelope->organization)) {
            return null;
        }

        /** @var WebhookDelivery|null $last */
        $last = WebhookDelivery::withoutOrganizationScope()
            ->where('organization_id', $envelope->organization_id)
            ->where('envelope_id', $envelope->getKey())
            ->where('is_test', false)
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            $hasActiveEndpoint = WebhookEndpoint::withoutOrganizationScope()
                ->where('organization_id', $envelope->organization_id)
                ->where('is_active', true)
                ->exists();

            return $hasActiveEndpoint
                ? ['status' => null, 'status_label' => 'Nenhuma entrega para este documento', 'event_label' => null, 'at' => null, 'href' => null]
                : null;
        }

        $href = null;

        if ($viewer !== null && $viewer->hasPermission(Permission::ManageIntegrations)) {
            $endpoint = WebhookEndpoint::withoutOrganizationScope()->find($last->webhook_endpoint_id);
            $href = $endpoint === null ? null : route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]);
        }

        return [
            'status' => $last->status,
            'status_label' => WebhookDelivery::statusLabel($last->status),
            'event_label' => $last->eventType()?->label() ?? $last->event_type,
            'at' => self::date($last->delivered_at ?? $last->last_attempt_at ?? $last->created_at),
            'href' => $href,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function delivery(WebhookDelivery $delivery, ?Membership $viewer): array
    {
        $type = $delivery->eventType();
        $envelope = $this->visibleEnvelope($delivery, $viewer);

        return [
            'id' => $delivery->ulid,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'event_label' => $type?->label() ?? $delivery->event_type,
            'status' => $delivery->status,
            'status_label' => WebhookDelivery::statusLabel($delivery->status),
            'is_test' => $delivery->is_test,
            'attempts' => $delivery->attempts,
            'max_attempts' => $delivery->is_test ? 1 : (int) config('assinavelox.webhooks.max_attempts', 8),
            'last_response_code' => $delivery->last_response_code,
            'last_duration_ms' => $delivery->last_duration_ms,
            'last_error' => $delivery->last_error,
            'last_error_label' => self::errorLabel($delivery->last_error),
            'next_retry_at' => $delivery->isOpen() ? self::date($delivery->next_retry_at) : null,
            'last_attempt_at' => self::date($delivery->last_attempt_at),
            'delivered_at' => self::date($delivery->delivered_at),
            'created_at' => self::date($delivery->created_at),
            'envelope' => $envelope === null ? null : ['id' => $envelope->ulid, 'code' => $envelope->display_code],
            'can_resend' => ! $delivery->isOpen() || $delivery->status === WebhookDelivery::STATUS_FAILED,
        ];
    }

    /**
     * Detalhe (gaveta): tentativas + corpo enviado. Corpo e vínculo omitidos quando quem olha
     * não pode ver o envelope.
     *
     * @return array<string, mixed>
     */
    public function deliveryDetail(WebhookDelivery $delivery, ?Membership $viewer): array
    {
        $hidden = $delivery->envelope_id !== null && $this->visibleEnvelope($delivery, $viewer) === null;

        return $this->delivery($delivery, $viewer) + [
            'payload' => $hidden ? null : json_decode($delivery->payload, true),
            'payload_hidden' => $hidden,
            // Com o corpo oculto, o trecho da resposta (receptores que ecoam o corpo recebido) e o
            // IP conectado também saem nulos: nada no histórico reexpõe o envelope escondido.
            'history' => array_map(static fn (array $entry): array => [
                ...$entry,
                ...($hidden ? ['response_excerpt' => null, 'remote_ip' => null] : []),
            ] + [
                'outcome_label' => self::outcomeLabel(is_string($entry['outcome'] ?? null) ? $entry['outcome'] : null),
                'error_label' => self::errorLabel(is_string($entry['error'] ?? null) ? $entry['error'] : null),
            ], array_values(array_filter((array) ($delivery->history ?? []), 'is_array'))),
            'headers' => [
                'delivery_id' => WebhookSignature::HEADER_DELIVERY,
                'timestamp' => WebhookSignature::HEADER_TIMESTAMP,
                'signature' => WebhookSignature::HEADER_SIGNATURE,
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function catalog(): array
    {
        return array_map(static fn (WebhookEventType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
        ], WebhookEventType::subscribable());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statuses(): array
    {
        return array_map(static fn (string $status): array => [
            'value' => $status,
            'label' => WebhookDelivery::statusLabel($status),
        ], WebhookDelivery::STATUSES);
    }

    /**
     * @return array<string, mixed>
     */
    public function limits(): array
    {
        return [
            'max_endpoints' => (int) config('assinavelox.webhooks.max_endpoints_per_organization', 10),
            'max_attempts' => (int) config('assinavelox.webhooks.max_attempts', 8),
            'backoff_seconds' => array_values(array_map('intval', (array) config('assinavelox.webhooks.backoff_seconds', []))),
            'pause_after_consecutive_failures' => (int) config('assinavelox.webhooks.pause_after_consecutive_failures', 20),
            'signature_tolerance_seconds' => (int) config('assinavelox.webhooks.signature_tolerance_seconds', 300),
            'secret_rotation_overlap_hours' => (int) config('assinavelox.webhooks.secret_rotation_overlap_hours', 24),
            'max_rotation_overlap_hours' => (int) config('assinavelox.webhooks.max_rotation_overlap_hours', 168),
            'timeout_seconds' => (float) config('assinavelox.webhooks.timeout_seconds', 10),
            'retention_days' => (int) config('assinavelox.webhooks.retention_days', 30),
        ];
    }

    /**
     * Formato Paginated<T> do front (resources/js/types/index.ts).
     *
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  callable(TItem): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    public function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => array_map($map, $paginator->items()),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'path' => $paginator->path(),
                'links' => method_exists($paginator, 'linkCollection') ? $paginator->linkCollection()->toArray() : [],
            ],
        ];
    }

    public static function errorLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (str_starts_with($code, 'http_')) {
            return 'O receptor respondeu HTTP '.substr($code, 5);
        }

        if (str_starts_with($code, 'ssrf_')) {
            return 'Destino bloqueado pela proteção de rede (o domínio não resolve para um endereço público)';
        }

        return match ($code) {
            'timeout' => 'Tempo esgotado — resultado desconhecido: o receptor pode ter recebido (deduplique pelo id da entrega)',
            'connection_failed' => 'Não foi possível conectar ao receptor',
            'redirect_not_followed' => 'O receptor respondeu com redirecionamento, que não é seguido',
            'endpoint_paused' => 'Não enviada: o endpoint está pausado',
            'endpoint_removed' => 'Não enviada: o endpoint foi removido',
            'feature_disabled' => 'Não enviada: webhooks desligados para a conta',
            'internal_error' => 'Falha interna ao enviar; nova tentativa agendada',
            default => $code,
        };
    }

    public static function outcomeLabel(?string $outcome): ?string
    {
        return match ($outcome) {
            TransportResult::SUCCEEDED => 'Entregue',
            TransportResult::FAILED => 'Falhou',
            TransportResult::UNKNOWN => 'Desconhecido (tempo esgotado)',
            TransportResult::BLOCKED => 'Bloqueada (proteção de rede)',
            default => $outcome,
        };
    }

    private function visibleEnvelope(WebhookDelivery $delivery, ?Membership $viewer): ?Envelope
    {
        if ($delivery->envelope_id === null || $viewer === null) {
            return null;
        }

        /** @var Envelope|null $envelope */
        $envelope = $delivery->relationLoaded('envelope')
            ? $delivery->getRelation('envelope')
            : Envelope::withoutOrganizationScope()->withTrashed()->find($delivery->envelope_id);

        if ($envelope === null || ! $this->access->canSee($viewer, $envelope)) {
            return null;
        }

        return $envelope;
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
