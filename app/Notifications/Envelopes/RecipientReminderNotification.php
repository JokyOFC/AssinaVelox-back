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
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lembrete automático ao signatário (Fase 2 §2.5).
 *
 * Mesmo cuidado do convite (RecipientInvitationNotification): o link em claro só existe
 * nesta mensagem e o payload da fila é cifrado (`ShouldBeEncrypted`). `afterCommit`: a
 * mensagem só sai se a transação que emitiu o link e registrou o lembrete for confirmada.
 *
 * Vocabulário (arquitetura §2): "assinar eletronicamente", nunca "assinatura digital".
 *
 * Fase 3 §3.3 (F-I18N): textos de `lang/{idioma}/signer_mail.php`, no idioma do participante
 * quando a flag `multilingual` está ligada.
 */
class RecipientReminderNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, LocalizesRecipientMail, Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $signingUrl,
        public readonly string $correlationId,
        public readonly int $sequence,
        public readonly int $maxCount,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
        $this->afterCommit();
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
            DeliveryPurpose::Reminder,
            $this->correlationId,
            ['envelope' => $this->envelope->display_code, 'sequence' => $this->sequence],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->envelope->organization;

        $message = (new MailMessage)
            ->subject($this->mailText('reminder.subject', ['title' => $this->envelope->title]))
            ->greeting($this->mailText('greeting_named', ['name' => $this->firstName()]))
            ->line($this->mailText('reminder.line', [
                'title' => MailText::escape($this->envelope->title),
                'code' => $this->envelope->display_code,
                'organization' => MailText::escape($organization->name),
            ]));

        if (filled($this->envelope->message)) {
            $message->line($this->mailText('sender_message', ['message' => MailText::escape($this->envelope->message)]));
        }

        $message
            ->action($this->mailText('reminder.action'), $this->signingUrl)
            ->line($this->mailText('code_notice'));

        if ($this->envelope->expires_at !== null) {
            $message->line($this->mailText('reminder.deadline', ['deadline' => $this->deadline()]));
        }

        $message
            ->line($this->mailText('reminder.link_notice'))
            ->salutation($this->mailText('salutation'));

        return $this->applyOrganizationBranding($message, $organization);
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->recipient->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return MailText::escape($parts[0] ?? $this->mailText('first_name_fallback'));
    }

    private function deadline(): string
    {
        return $this->mailDeadline($this->envelope->expires_at, $this->envelope->organization->timezone);
    }
}
