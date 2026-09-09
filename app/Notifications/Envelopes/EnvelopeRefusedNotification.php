<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Recusa comunicada a QUEM ENVIOU (usuário da conta), com o motivo informado pelo
 * signatário. Vai por e-mail rastreado e pelo sino do app (canal `database`).
 */
class EnvelopeRefusedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly Recipient $recipient,
        public readonly string $correlationId,
        public readonly ?string $reason = null,
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
            purpose: DeliveryPurpose::Refused,
            correlationId: $this->correlationId,
            envelopeId: $this->envelope->getKey(),
            recipientId: $this->recipient->getKey(),
            meta: ['envelope' => $this->envelope->display_code, 'audience' => 'sender'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Assinatura recusada: '.$this->envelope->title)
            ->greeting('Olá!')
            ->line('**'.$this->recipient->name.'** recusou assinar o documento **'.$this->envelope->title.'** ('.$this->envelope->display_code.').');

        if (filled($this->reason)) {
            $message->line('Motivo informado: "'.$this->reason.'"');
        }

        return $message
            ->line('A solicitação foi encerrada e os demais signatários pendentes foram avisados. Se quiser tentar de novo, duplique o documento e envie uma nova solicitação.')
            ->action('Ver o documento', route('envelopes.show', $this->envelope))
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->envelope->organization_id,
            'title' => 'Assinatura recusada',
            'body' => $this->recipient->name.' recusou assinar '.$this->envelope->title.'.',
            'url' => route('envelopes.show', $this->envelope),
            'event' => 'recipient_refused',
        ];
    }
}
