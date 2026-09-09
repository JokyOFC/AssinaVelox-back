<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao remetente de que o documento expira em breve e ainda tem pendências
 * (evento `envelope_expiring` do catálogo de preferências, ROUTES §2.14).
 */
class SenderEnvelopeExpiringNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly int $pendingCount,
        public readonly string $correlationId,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFrom(['mail' => TrackedMailChannel::class, 'database' => 'database']);
    }

    public function deliveryContext(object $notifiable): DeliveryContext
    {
        return new DeliveryContext(
            organizationId: (int) $this->envelope->organization_id,
            toAddress: method_exists($notifiable, 'routeNotificationFor')
                ? (string) ($notifiable->routeNotificationFor('mail') ?: '')
                : '',
            purpose: DeliveryPurpose::Resend,
            correlationId: $this->correlationId,
            envelopeId: $this->envelope->getKey(),
            meta: ['envelope' => $this->envelope->display_code, 'audience' => 'sender', 'reason' => 'expiring_soon'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deadline = $this->envelope->expires_at
            ?->setTimezone($this->envelope->organization->timezone)
            ->format('d/m/Y \à\s H:i');

        return (new MailMessage)
            ->subject('Prazo acabando: '.$this->envelope->title)
            ->greeting('Olá!')
            ->line('O documento **'.$this->envelope->title.'** ('.$this->envelope->display_code.') ainda tem '.$this->pendingCount.' signatário(s) sem assinar.')
            ->line($deadline !== null
                ? 'O prazo termina em '.$deadline.'. Depois disso o documento passa a "Expirado" e os links deixam de funcionar.'
                : 'O prazo está próximo do fim.')
            ->action('Abrir o documento', route('envelopes.show', $this->envelope))
            ->line('Se precisar, use "Lembrar pendentes" para reenviar os convites.')
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->envelope->organization_id,
            'title' => 'Documento expira em breve',
            'body' => $this->envelope->title.' tem '.$this->pendingCount.' signatário(s) pendente(s).',
            'url' => route('envelopes.show', $this->envelope),
            'event' => 'envelope_expiring',
        ];
    }
}
