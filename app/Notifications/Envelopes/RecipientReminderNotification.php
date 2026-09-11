<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\AppliesOrganizationBranding;
use App\Notifications\Contracts\TracksDelivery;
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
 */
class RecipientReminderNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, Queueable;

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
            ->subject('Lembrete: '.$this->envelope->title.' aguarda sua assinatura')
            ->greeting('Olá, '.$this->firstName().'!')
            ->line('Este é um lembrete automático: o documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.'), enviado por **'.MailText::escape($organization->name).'**, ainda aguarda a sua assinatura eletrônica.');

        if (filled($this->envelope->message)) {
            $message->line('Mensagem de quem enviou: "'.MailText::escape($this->envelope->message).'"');
        }

        $message
            ->action('Abrir e assinar', $this->signingUrl)
            ->line('Para confirmar que é você, vamos enviar um código de 6 dígitos para este mesmo e-mail.');

        if ($this->envelope->expires_at !== null) {
            $message->line('O prazo para assinar termina em '.$this->deadline().'.');
        }

        $message
            ->line('Este link substitui os enviados antes — use sempre o e-mail mais recente. Ele é pessoal: não encaminhe esta mensagem.')
            ->salutation('Atenciosamente, AssinaVelox');

        return $this->applyOrganizationBranding($message, $organization);
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->recipient->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return MailText::escape($parts[0] ?? 'tudo bem?');
    }

    private function deadline(): string
    {
        return $this->envelope->expires_at
            ->copy()
            ->setTimezone($this->envelope->organization->timezone)
            ->format('d/m/Y \à\s H:i');
    }
}
