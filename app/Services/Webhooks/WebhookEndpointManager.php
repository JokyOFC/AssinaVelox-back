<?php

namespace App\Services\Webhooks;

use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Correlation;
use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestão dos endpoints — o MOTOR que a tela (web) e os REST Hooks (§2.17, API) reaproveitam.
 * Nenhum método confere permissão: quem chama autoriza antes (WebhookEndpointPolicy na web;
 * ability do token + policy na API).
 *
 * Segredo: gerado aqui, devolvido em claro SÓ por `create()` e `rotateSecret()`; depois disso
 * só existe cifrado. Remover um endpoint sobrescreve o segredo por um valor aleatório.
 */
final class WebhookEndpointManager
{
    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly WebhookAlerts $alerts,
        private readonly WebhookAccess $access,
        private readonly WebhookPayloadFactory $payloads,
    ) {}

    /**
     * @param  list<string>  $events  valores do catálogo ou ["*"]
     * @return array{endpoint: WebhookEndpoint, secret: string}
     *
     * @throws BlockedOutboundUrl
     * @throws WebhookActionRefused
     */
    public function create(
        Organization $organization,
        User $creator,
        string $url,
        array $events,
        ?string $description = null,
        string $source = WebhookEndpoint::SOURCE_WEB,
        ?int $apiTokenId = null,
    ): array {
        $target = $this->guard->inspect($url);
        $events = self::normalizeEvents($events);
        $max = max(1, (int) config('assinavelox.webhooks.max_endpoints_per_organization', 10));

        if (WebhookEndpoint::forOrganization($organization)->count() >= $max) {
            throw new WebhookActionRefused("A conta já tem {$max} endpoints de webhook. Remova um antes de criar outro.");
        }

        $secret = WebhookSignature::generateSecret();

        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $creator->getKey(),
            'url' => $target->url,
            'description' => self::cleanDescription($description),
            'events' => $events,
            'secret' => $secret,
            'secret_hint' => WebhookSignature::hint($secret),
            'secret_rotated_at' => Carbon::now(),
            'is_active' => true,
            'consecutive_failures' => 0,
            'source' => $source,
            'api_token_id' => $apiTokenId,
        ])->save();

        $this->log('webhooks.endpoint_created', $endpoint, ['source' => $source]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * @param  array{url?: string, events?: list<string>, description?: string|null}  $data
     *
     * @throws BlockedOutboundUrl
     * @throws WebhookActionRefused
     */
    public function update(WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        if (array_key_exists('url', $data)) {
            $endpoint->url = $this->guard->inspect((string) $data['url'])->url;
        }

        if (array_key_exists('events', $data)) {
            $endpoint->events = self::normalizeEvents((array) $data['events']);
        }

        if (array_key_exists('description', $data)) {
            $endpoint->description = self::cleanDescription($data['description']);
        }

        $endpoint->save();
        $this->log('webhooks.endpoint_updated', $endpoint);

        return $endpoint;
    }

    /**
     * Gera um segredo novo. O anterior continua assinando por `$overlapHours` (padrão da
     * configuração; 0 encerra na hora). Uma rotação durante outra descarta o mais antigo:
     * nunca há mais de dois segredos válidos.
     */
    public function rotateSecret(WebhookEndpoint $endpoint, ?int $overlapHours = null): string
    {
        $maxOverlap = max(0, (int) config('assinavelox.webhooks.max_rotation_overlap_hours', 168));
        $overlap = min($maxOverlap, max(0, $overlapHours ?? (int) config('assinavelox.webhooks.secret_rotation_overlap_hours', 24)));
        $now = Carbon::now();
        $secret = WebhookSignature::generateSecret();

        $endpoint->forceFill([
            'previous_secret' => $overlap > 0 ? $endpoint->secret : null,
            'previous_secret_expires_at' => $overlap > 0 ? $now->copy()->addHours($overlap) : null,
            'secret' => $secret,
            'secret_hint' => WebhookSignature::hint($secret),
            'secret_rotated_at' => $now,
        ])->save();

        $this->log('webhooks.secret_rotated', $endpoint, ['overlap_hours' => $overlap]);

        return $secret;
    }

    /** Encerra a convivência agora (segredo anterior vazado, por exemplo). */
    public function expirePreviousSecret(WebhookEndpoint $endpoint): void
    {
        $endpoint->forceFill(['previous_secret' => null, 'previous_secret_expires_at' => null])->save();
        $this->log('webhooks.previous_secret_expired', $endpoint);
    }

    public function pause(WebhookEndpoint $endpoint): void
    {
        $this->alerts->pause($endpoint, WebhookEndpoint::PAUSED_MANUAL);
    }

    /**
     * Reativa: revalida a URL, zera as falhas seguidas. Se quem respondia pelo endpoint perdeu
     * o acesso, quem reativa passa a responder por ele.
     *
     * @throws BlockedOutboundUrl
     */
    public function resume(WebhookEndpoint $endpoint, User $actor): void
    {
        $this->guard->inspect($endpoint->url);

        $responsibleStillValid = $this->access->responsibleMembership($endpoint) !== null;

        $endpoint->forceFill([
            'is_active' => true,
            'paused_at' => null,
            'paused_reason' => null,
            'consecutive_failures' => 0,
            'created_by_user_id' => $responsibleStillValid ? $endpoint->created_by_user_id : $actor->getKey(),
        ])->save();

        $this->log('webhooks.endpoint_resumed', $endpoint, ['responsible_changed' => ! $responsibleStillValid]);
    }

    /**
     * Remove (soft delete, para o histórico continuar legível até a limpeza): cancela o que
     * estava na fila e sobrescreve o segredo.
     */
    public function delete(WebhookEndpoint $endpoint): void
    {
        DB::transaction(function () use ($endpoint): void {
            WebhookDelivery::withoutOrganizationScope()
                ->where('webhook_endpoint_id', $endpoint->getKey())
                ->whereIn('status', WebhookDelivery::OPEN_STATUSES)
                ->update([
                    'status' => WebhookDelivery::STATUS_CANCELED,
                    'last_error' => 'endpoint_removed',
                    'next_retry_at' => null,
                    'updated_at' => Carbon::now(),
                ]);

            $scrambled = WebhookSignature::generateSecret();

            $endpoint->forceFill([
                'is_active' => false,
                'secret' => $scrambled,
                'secret_hint' => '—',
                'previous_secret' => null,
                'previous_secret_expires_at' => null,
            ])->save();

            $endpoint->delete();
        });

        $this->log('webhooks.endpoint_deleted', $endpoint);
    }

    /**
     * "Enviar teste": entrega `webhook.ping` (uma tentativa, sem retentativa, não conta para a
     * pausa automática). Funciona com o endpoint pausado, para diagnosticar antes de reativar.
     */
    public function sendTest(WebhookEndpoint $endpoint): WebhookDelivery
    {
        /** @var Organization $organization */
        $organization = Organization::query()->findOrFail($endpoint->organization_id);
        $eventId = (string) Str::ulid();

        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'organization_id' => $endpoint->organization_id,
            'webhook_endpoint_id' => $endpoint->getKey(),
            'envelope_id' => null,
            'event_id' => $eventId,
            'event_type' => WebhookEventType::Ping->value,
            'payload' => $this->payloads->ping($eventId, $organization, $endpoint),
            'status' => WebhookDelivery::STATUS_PENDING,
            'is_test' => true,
            'attempts' => 0,
            'next_retry_at' => Carbon::now()->addSeconds((int) config('assinavelox.webhooks.initial_fallback_seconds', 60)),
            'correlation_id' => Correlation::id(),
        ])->save();

        DeliverWebhook::dispatch((int) $delivery->getKey(), DeliverWebhook::TRIGGER_INITIAL)->afterCommit();

        return $delivery;
    }

    /**
     * Reenvio manual: nova tentativa com o MESMO id de entrega e o MESMO corpo (o timestamp e
     * a assinatura são os do momento do reenvio).
     *
     * @throws WebhookActionRefused
     */
    public function resend(WebhookDelivery $delivery): void
    {
        /** @var WebhookEndpoint|null $endpoint */
        $endpoint = WebhookEndpoint::withoutOrganizationScope()->find($delivery->webhook_endpoint_id);

        if ($endpoint === null) {
            throw new WebhookActionRefused('O endpoint desta entrega foi removido.');
        }

        if (! $endpoint->is_active && ! $delivery->is_test) {
            throw new WebhookActionRefused('Reative o endpoint antes de reenviar.');
        }

        // UPDATE condicional (sem checar no modelo já carregado e gravar depois): se um worker
        // reivindicou a entrega entre a leitura e agora, a trava viva dele não é apagada (§5).
        $now = Carbon::now();
        $claimed = WebhookDelivery::withoutOrganizationScope()
            ->whereKey($delivery->getKey())
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', $now))
            ->update([
                'status' => WebhookDelivery::STATUS_PENDING,
                'next_retry_at' => $now,
                'locked_until' => null,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            throw new WebhookActionRefused('Esta entrega está sendo tentada agora. Aguarde o resultado.');
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'next_retry_at' => $now,
            'locked_until' => null,
        ])->syncOriginal();

        Log::info('webhooks.delivery_resend_requested', [
            'delivery' => $delivery->ulid,
            'endpoint' => $endpoint->ulid,
            'organization_id' => $delivery->organization_id,
            'correlation_id' => Correlation::id(),
        ]);

        DeliverWebhook::dispatch((int) $delivery->getKey(), DeliverWebhook::TRIGGER_MANUAL)->afterCommit();
    }

    /**
     * @param  array<int|string, mixed>  $events
     * @return list<string>
     *
     * @throws WebhookActionRefused
     */
    public static function normalizeEvents(array $events): array
    {
        $values = array_values(array_unique(array_filter($events, 'is_string')));

        if (in_array(WebhookEventType::ALL, $values, true)) {
            return [WebhookEventType::ALL];
        }

        $valid = array_values(array_filter(
            WebhookEventType::subscribableValues(),
            static fn (string $value): bool => in_array($value, $values, true),
        ));

        if ($valid === []) {
            throw new WebhookActionRefused('Escolha pelo menos um evento do catálogo.');
        }

        return $valid;
    }

    private static function cleanDescription(mixed $description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $description = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $description));

        return $description === '' ? null : Str::limit($description, 160, '');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $message, WebhookEndpoint $endpoint, array $context = []): void
    {
        Log::info($message, [
            'endpoint' => $endpoint->ulid,
            'organization_id' => $endpoint->organization_id,
            'host' => $endpoint->host(),
            'actor_id' => auth()->id(),
            'correlation_id' => Correlation::id(),
            ...$context,
        ]);
    }
}
