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
 * Aviso de prazo curto ao signatário que ainda não assinou (RECONCILIACAO Q22: "expira em
 * 48 h"). Não é o lembrete automático recorrente da Fase 2 — é um único aviso por
 * envelope, disparado pelo comando `envelopes:notify-expiring`.
 *
 * Propósito de entrega: `resend`. `delivery_attempts.purpose` não tem um caso próprio para
 * "expirando" e o enum é área de outro agente — `meta.reason = expiring_soon` distingue os
 * dois casos. Ver docs/envio-e-convites.md › Limitações.
 *
 * Fase 3 §3.3 (F-I18N): textos de `lang/{idioma}/signer_mail.php`, no idioma do participante
 * quando a flag `multilingual` está ligada.
 */
class EnvelopeExpiringNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, LocalizesRecipientMail, Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $signingUrl,
        public readonly string $correlationId,
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
            DeliveryPurpose::Resend,
            $this->correlationId,
            ['envelope' => $this->envelope->display_code, 'reason' => 'expiring_soon'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deadline = $this->envelope->expires_at === null
            ? null
            : $this->mailDeadline($this->envelope->expires_at, $this->envelope->organization->timezone);

        $message = (new MailMessage)
            ->subject($this->mailText('expiring.subject', ['title' => $this->envelope->title]))
            ->greeting($this->mailText('greeting'))
            ->line($this->mailText('expiring.line', [
                'title' => MailText::escape($this->envelope->title),
                'code' => $this->envelope->display_code,
            ]))
            ->line($deadline !== null
                ? $this->mailText('expiring.deadline', ['deadline' => $deadline])
                : $this->mailText('expiring.deadline_unknown'))
            ->action($this->mailText('expiring.action'), $this->signingUrl)
            ->line($this->mailText('expiring.personal_link'))
            ->salutation($this->mailText('salutation'));

        return $this->applyOrganizationBranding($message, $this->envelope->organization);
    }
}
