<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Contracts\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite para assinar (primeiro envio) e reenvio manual — RECONCILIACAO Q11.
 *
 * O link em claro existe apenas nesta mensagem. Ele viaja no payload da fila (como já
 * ocorre em MembershipInvitationNotification) e é descartado quando o job termina; o banco
 * de domínio guarda somente o digest.
 *
 * Vocabulário (arquitetura §2): o texto fala em **assinar eletronicamente** e em
 * confirmação de identidade por código — nunca em "assinatura digital ICP-Brasil".
 */
class RecipientInvitationNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $signingUrl,
        public readonly string $correlationId,
        public readonly bool $isReminder = false,
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
            $this->isReminder ? DeliveryPurpose::Resend : DeliveryPurpose::Invitation,
            $this->correlationId,
            ['envelope' => $this->envelope->display_code],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->envelope->organization;
        $sender = $this->envelope->creator;

        $message = (new MailMessage)
            ->subject($this->isReminder
                ? 'Lembrete: '.$this->envelope->title.' aguarda sua assinatura'
                : $organization->name.' enviou um documento para você assinar')
            ->greeting('Olá, '.$this->firstName().'!');

        if ($this->isReminder) {
            $message->line('Este é um lembrete: o documento **'.$this->envelope->title.'** ('.$this->envelope->display_code.') ainda aguarda a sua assinatura.');
        } else {
            $message->line(($sender->name ?? 'Um usuário').', de **'.$organization->name.'**, enviou o documento **'.$this->envelope->title.'** ('.$this->envelope->display_code.') para você assinar eletronicamente.');
        }

        if (filled($this->envelope->message)) {
            $message->line('Mensagem de quem enviou: "'.$this->envelope->message.'"');
        }

        $message
            ->action($this->isReminder ? 'Abrir e assinar' : 'Revisar e assinar', $this->signingUrl)
            ->line('Para confirmar que é você, vamos enviar um código de 6 dígitos para este mesmo e-mail.');

        if ($this->envelope->expires_at !== null) {
            $message->line('O prazo para assinar termina em '.$this->deadline().'.');
        }

        return $message
            ->line('Este link é pessoal e foi criado só para você — não encaminhe este e-mail.')
            ->salutation('Atenciosamente, AssinaVelox');
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->recipient->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? 'tudo bem?';
    }

    private function deadline(): string
    {
        return $this->envelope->expires_at
            ->setTimezone($this->envelope->organization->timezone)
            ->format('d/m/Y \à\s H:i');
    }
}
