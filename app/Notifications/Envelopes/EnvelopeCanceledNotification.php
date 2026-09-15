<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\AppliesOrganizationBranding;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\Locale\LocalizesRecipientMail;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao signatário de que a solicitação foi encerrada pelo remetente ou por recusa de
 * outro participante. O link já foi revogado quando esta mensagem sai — o texto diz isso
 * em vez de oferecer um botão que devolveria "link inválido".
 *
 * Fase 3 §3.3 (F-I18N): textos de `lang/{idioma}/signer_mail.php`, no idioma do participante
 * quando a flag `multilingual` está ligada.
 */
class EnvelopeCanceledNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, LocalizesRecipientMail, Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $correlationId,
        public readonly ?string $reason = null,
        public readonly bool $refusedByAnother = false,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
        $this->localizeFor($recipient, $envelope->organization);
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
        $body = [
            'title' => MailText::escape($this->envelope->title),
            'code' => $this->envelope->display_code,
            'organization' => MailText::escape($organization->name),
        ];

        $message = (new MailMessage)
            ->subject($this->mailText('canceled.subject', ['title' => $this->envelope->title]))
            ->greeting($this->mailText('greeting'));

        $message->line($this->refusedByAnother
            ? $this->mailText('canceled.refused_line', $body)
            : $this->mailText('canceled.canceled_line', $body));

        if (filled($this->reason)) {
            $message->line($this->mailText('canceled.reason', ['reason' => MailText::escape($this->reason)]));
        }

        $message
            ->line($this->mailText('canceled.no_action'))
            ->line($this->mailText('canceled.contact', ['organization' => MailText::escape($organization->name)]))
            ->salutation($this->mailText('salutation'));

        return $this->applyOrganizationBranding($message, $organization);
    }
}
