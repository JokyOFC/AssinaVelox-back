<?php

namespace App\Services\Webhooks;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\WebhookEndpoint;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Evento `webhook_failed` (roadmap §2.0/§2.16): um endpoint foi pausado automaticamente —
 * falhas seguidas ou responsável sem acesso. Vai para quem tem `manage_integrations`, nos
 * canais que cada pessoa manteve ligados em Configurações › Notificações
 * (NotificationPreferences, evento `webhook_failed`; quem dispara restringe os canais). O
 * e-mail sai pelo TrackedMailChannel (registro em `delivery_attempts`, finalidade
 * `webhook_failed`), como os demais avisos da conta — integração I-2D.
 *
 * Carrega só escalares (nada de modelo serializado, nada de segredo, URL completa ou payload):
 * o host basta para reconhecer o endpoint.
 */
class WebhookEndpointPausedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public readonly string $correlationId;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationName,
        public readonly string $endpointUlid,
        public readonly string $host,
        public readonly string $reason,
        public readonly int $consecutiveFailures,
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? (string) Str::ulid();
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFrom(['mail' => TrackedMailChannel::class, 'database' => 'database']);
    }

    public function deliveryContext(object $notifiable): DeliveryContext
    {
        return new DeliveryContext(
            organizationId: $this->organizationId,
            toAddress: method_exists($notifiable, 'routeNotificationFor')
                ? (string) ($notifiable->routeNotificationFor('mail') ?: '')
                : '',
            purpose: DeliveryPurpose::WebhookFailed,
            correlationId: $this->correlationId,
            meta: ['audience' => 'integrations', 'endpoint' => $this->endpointUlid],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Webhook pausado em '.$this->organizationName)
            ->greeting('Olá!')
            ->line('O endpoint de webhook **'.MailText::escape($this->host).'** da conta **'
                .MailText::escape($this->organizationName).'** foi pausado.')
            ->line($this->reasonText())
            ->line('Enquanto estiver pausado, nenhum evento é entregue a ele. Corrija o problema e reative o endpoint; as entregas que falharam podem ser reenviadas pelo histórico.')
            ->action('Ver o endpoint', $this->url())
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'webhook_failed',
            'organization_id' => $this->organizationId,
            'title' => 'Webhook pausado',
            'body' => $this->host.' — '.$this->reasonText(),
            'url' => $this->url(),
            'endpoint_ulid' => $this->endpointUlid,
            'reason' => $this->reason,
        ];
    }

    private function reasonText(): string
    {
        return match ($this->reason) {
            WebhookEndpoint::PAUSED_CREATOR_WITHOUT_ACCESS => 'Motivo: quem responde pelo endpoint não tem mais acesso a API e webhooks. Quem reativar passa a responder por ele.',
            default => 'Motivo: '.$this->consecutiveFailures.' tentativas de entrega falharam seguidas.',
        };
    }

    private function url(): string
    {
        return route('integrations.webhooks.show', ['webhookEndpoint' => $this->endpointUlid]);
    }
}
