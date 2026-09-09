<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de prazo curto ao signatário que ainda não assinou (RECONCILIACAO Q22: "expira em
 * 48 h"). Não é o lembrete automático recorrente da Fase 2 — é um único aviso por
 * envelope, disparado pelo comando `envelopes:notify-expiring`.
 *
 * Propósito de entrega: `resend`. `delivery_attempts.purpose` não tem um caso próprio para
 * "expirando" e o enum é área de outro agente — `meta.reason = expiring_soon` distingue os
 * dois casos. Ver docs/envio-e-convites.md › Limitações.
 */
class EnvelopeExpiringNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $signingUrl,
        public readonly string $correlationId,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [TrackedMailChannel::class];
    }

    public function deliveryContext(object $notifiable): DeliveryContext
    {
        return DeliveryContext::forRecipient(
            $this->recipient,
            DeliveryPurpose::Resend,
            $this->correlationId,
            ['envelope' => $this->envelope->display_code, 'reason' => 'expiring_soon'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deadline = $this->envelope->expires_at
            ?->setTimezone($this->envelope->organization->timezone)
            ->format('d/m/Y \à\s H:i');

        return (new MailMessage)
            ->subject('Seu prazo para assinar '.$this->envelope->title.' está acabando')
            ->greeting('Olá!')
            ->line('O documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.') ainda aguarda a sua assinatura.')
            ->line($deadline !== null
                ? 'O prazo termina em '.$deadline.'. Depois disso o link deixa de funcionar e a solicitação precisa ser reenviada.'
                : 'O prazo está próximo do fim. Depois disso o link deixa de funcionar.')
            ->action('Assinar agora', $this->signingUrl)
            ->line('Este link é pessoal — não encaminhe este e-mail.')
            ->salutation('Atenciosamente, AssinaVelox');
    }
}
