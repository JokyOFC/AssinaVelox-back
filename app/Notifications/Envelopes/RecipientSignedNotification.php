<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Fulano assinou" — aviso a QUEM ENVIOU, a cada aceite concluído (ROUTES §2.14, evento
 * `recipient_signed`, ligado por padrão no e-mail e no sino do app).
 *
 * O interruptor existia na tela de Notificações desde o começo, ligado por padrão nos dois
 * canais, e nada o produzia: o remetente marcava a opção, via "Preferências salvas." e nunca
 * recebia nada. Esta é a notificação que o produto mais promete no fluxo.
 *
 * Vocabulário (arquitetura §2): **aceite eletrônico** registrado, nunca "assinatura digital".
 * O aviso não afirma nada sobre assinatura criptográfica — isso é assunto da conclusão.
 */
class RecipientSignedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly Recipient $recipient,
        public readonly string $correlationId,
        public readonly int $signedCount = 0,
        public readonly int $totalCount = 0,
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
            purpose: DeliveryPurpose::Signed,
            correlationId: $this->correlationId,
            envelopeId: $this->envelope->getKey(),
            recipientId: $this->recipient->getKey(),
            meta: ['envelope' => $this->envelope->display_code, 'audience' => 'sender'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Assinatura registrada: '.$this->envelope->title)
            ->greeting('Olá!')
            ->line('**'.MailText::escape($this->recipient->name).'** concluiu o aceite eletrônico do documento **'
                .MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.').');

        if ($this->totalCount > 0) {
            $message->line($this->progressLine());
        }

        return $message
            ->action('Ver o documento', route('envelopes.show', $this->envelope))
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recipient_signed',
            'envelope_ulid' => $this->envelope->ulid,
            'envelope_title' => $this->envelope->title,
            'display_code' => $this->envelope->display_code,
            'recipient_ulid' => $this->recipient->ulid,
            'recipient_name' => $this->recipient->name,
            'signed_count' => $this->signedCount,
            'total_count' => $this->totalCount,
        ];
    }

    private function progressLine(): string
    {
        $remaining = max(0, $this->totalCount - $this->signedCount);

        return $remaining === 0
            ? 'Todos os signatários concluíram. O documento está sendo finalizado e você recebe o arquivo final em seguida.'
            : 'Faltam '.$remaining.' de '.$this->totalCount.' signatário(s).';
    }
}
