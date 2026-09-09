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
 * Aviso ao signatário de que a solicitação foi encerrada pelo remetente ou por recusa de
 * outro participante. O link já foi revogado quando esta mensagem sai — o texto diz isso
 * em vez de oferecer um botão que devolveria "link inválido".
 */
class EnvelopeCanceledNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $correlationId,
        public readonly ?string $reason = null,
        public readonly bool $refusedByAnother = false,
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
            $this->refusedByAnother ? DeliveryPurpose::Refused : DeliveryPurpose::Canceled,
            $this->correlationId,
            ['envelope' => $this->envelope->display_code],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->envelope->organization;

        $message = (new MailMessage)
            ->subject('Documento encerrado: '.$this->envelope->title)
            ->greeting('Olá!');

        if ($this->refusedByAnother) {
            $message->line('A solicitação de assinatura do documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.') foi encerrada porque um dos signatários recusou assinar.');
        } else {
            $message->line('**'.MailText::escape($organization->name).'** cancelou a solicitação de assinatura do documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.').');
        }

        if (filled($this->reason)) {
            $message->line('Motivo informado: "'.MailText::escape($this->reason).'"');
        }

        return $message
            ->line('O link que você recebeu não é mais válido e nenhuma ação é necessária da sua parte.')
            ->line('Em caso de dúvida, fale com '.MailText::escape($organization->name).'.')
            ->salutation('Atenciosamente, AssinaVelox');
    }
}
