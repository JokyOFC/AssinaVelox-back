<?php

namespace App\Services\Webhooks;

use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Correlation;
use Illuminate\Support\Carbon;

/**
 * Transforma um evento da trilha de auditoria em entregas (uma por endpoint assinante).
 *
 * Roda no MESMO processo e transação que gravou o evento (outbox): se a operação de domínio
 * for desfeita, as entregas também somem. O job só é despachado DEPOIS do commit.
 *
 * Idempotência: `createOrFirst` sobre o único (endpoint, tipo, event_id) — processar o mesmo
 * evento duas vezes não cria duas entregas nem despacha dois jobs.
 */
final class WebhookFanOut
{
    public function __construct(
        private readonly WebhookPayloadFactory $payloads,
        private readonly WebhookAccess $access,
        private readonly WebhookAlerts $alerts,
    ) {}

    /**
     * @return list<WebhookDelivery> entregas criadas agora
     */
    public function handle(AuditEvent $event): array
    {
        if (! WebhooksFeature::globallyEnabled()) {
            return [];
        }

        $type = WebhookEventType::fromAudit($event->event_type);

        if ($type === null) {
            return [];
        }

        $endpoints = WebhookEndpoint::forOrganization($event->organization_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($type));

        if ($endpoints->isEmpty()) {
            return [];
        }

        $organization = Organization::query()->find($event->organization_id);

        if ($organization === null || ! WebhooksFeature::enabled($organization)) {
            return [];
        }

        /** @var Envelope|null $envelope */
        $envelope = $event->envelope_id === null
            ? null
            : Envelope::withoutOrganizationScope()->withTrashed()->find($event->envelope_id);

        /** @var Recipient|null $recipient */
        $recipient = $event->recipient_id === null
            ? null
            : Recipient::withoutOrganizationScope()->find($event->recipient_id);

        $payload = null;
        $created = [];

        foreach ($endpoints as $endpoint) {
            $responsible = $this->access->responsibleMembership($endpoint);

            if (! $responsible instanceof Membership) {
                $this->alerts->pause($endpoint, WebhookEndpoint::PAUSED_CREATOR_WITHOUT_ACCESS);

                continue;
            }

            // REST Hook de token vencido/revogado/apagado: não recebe mais nada (a varredura
            // `rest-hooks:prune` remove a assinatura em seguida).
            if (! $this->access->restHookTokenUsable($endpoint)) {
                continue;
            }

            // Mesma regra de visibilidade da interface: evento de envelope que o responsável
            // não pode ver não sai para o endpoint dele.
            if ($envelope !== null && ! $this->access->canSee($responsible, $envelope)) {
                continue;
            }

            $payload ??= $this->payloads->forAuditEvent($event, $type, $organization, $envelope, $recipient);

            $delivery = WebhookDelivery::withoutOrganizationScope()->createOrFirst(
                [
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event_type' => $type->value,
                    'event_id' => $event->ulid,
                ],
                [
                    'organization_id' => $event->organization_id,
                    'envelope_id' => $envelope?->getKey(),
                    'payload' => $payload,
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'attempts' => 0,
                    'next_retry_at' => Carbon::now()->addSeconds((int) config('assinavelox.webhooks.initial_fallback_seconds', 60)),
                    'correlation_id' => $event->correlation_id ?? Correlation::current(),
                ],
            );

            if ($delivery->wasRecentlyCreated) {
                DeliverWebhook::dispatch((int) $delivery->getKey(), DeliverWebhook::TRIGGER_INITIAL)->afterCommit();
                $created[] = $delivery;
            }
        }

        return $created;
    }
}
