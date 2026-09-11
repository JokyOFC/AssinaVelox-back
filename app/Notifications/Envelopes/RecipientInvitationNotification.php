<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Enums\RecipientRole;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite para assinar (primeiro envio) e reenvio manual — RECONCILIACAO Q11.
 *
 * O link em claro existe apenas nesta mensagem. Ele viaja no payload da fila (como já
 * ocorre em MembershipInvitationNotification) e é descartado quando o job termina; o banco
 * de domínio guarda somente o digest. Como o payload carrega o token bruto, a notificação
 * implementa `ShouldBeEncrypted`: o que fica em `jobs`, em `failed_jobs` e na tela do
 * Horizon é cifrado com a APP_KEY.
 *
 * Vocabulário (arquitetura §2): o texto fala em **assinar eletronicamente** e em
 * confirmação de identidade por código — nunca em "assinatura digital ICP-Brasil".
 */
class RecipientInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
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
        $role = $this->role();

        // Fase 2 §2.4: papéis que não assinam como signatário ganham texto próprio
        // (arquitetura §2 — aprovar, testemunhar e acompanhar não são "assinar"). O texto do
        // signatário, único papel da Fase 1, continua idêntico.
        if ($role !== RecipientRole::Signer) {
            return $this->roleMail($role);
        }

        $organization = $this->envelope->organization;
        $sender = $this->envelope->creator;

        $message = (new MailMessage)
            ->subject($this->isReminder
                ? 'Lembrete: '.$this->envelope->title.' aguarda sua assinatura'
                : $organization->name.' enviou um documento para você assinar')
            ->greeting('Olá, '.$this->firstName().'!');

        if ($this->isReminder) {
            $message->line('Este é um lembrete: o documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.') ainda aguarda a sua assinatura.');
        } else {
            $message->line(MailText::escape($sender->name ?? 'Um usuário').', de **'.MailText::escape($organization->name).'**, enviou o documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.') para você assinar eletronicamente.');
        }

        if (filled($this->envelope->message)) {
            $message->line('Mensagem de quem enviou: "'.MailText::escape($this->envelope->message).'"');
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

    private function role(): RecipientRole
    {
        // `recipients.role` é cast para o enum; linhas antigas nascem `signer` (default).
        return $this->recipient->role;
    }

    /**
     * Convite de testemunha, aprovador ou visualizador (Fase 2 §2.4).
     */
    private function roleMail(RecipientRole $role): MailMessage
    {
        $organization = $this->envelope->organization;
        $sender = $this->envelope->creator;
        $title = MailText::escape($this->envelope->title);
        $code = $this->envelope->display_code;
        $from = MailText::escape($sender->name ?? 'Um usuário').', de **'.MailText::escape($organization->name).'**,';

        [$subject, $reminderSubject, $intro, $reminderLine, $action, $deadline] = match ($role) {
            RecipientRole::Witness => [
                $organization->name.' pediu que você assine um documento como testemunha',
                'Lembrete: '.$this->envelope->title.' aguarda sua assinatura como testemunha',
                $from.' pediu que você assine eletronicamente o documento **'.$title.'** ('.$code.') como testemunha.',
                'Este é um lembrete: o documento **'.$title.'** ('.$code.') ainda aguarda a sua assinatura como testemunha.',
                'Revisar e assinar como testemunha',
                'O prazo para assinar termina em ',
            ],
            RecipientRole::Approver => [
                $organization->name.' enviou um documento para a sua aprovação',
                'Lembrete: '.$this->envelope->title.' aguarda a sua aprovação',
                $from.' enviou o documento **'.$title.'** ('.$code.') para você revisar e aprovar eletronicamente.',
                'Este é um lembrete: o documento **'.$title.'** ('.$code.') ainda aguarda a sua aprovação.',
                'Revisar e aprovar',
                'O prazo para aprovar termina em ',
            ],
            default => [
                $organization->name.' compartilhou um documento com você',
                $organization->name.' compartilhou um documento com você',
                $from.' incluiu você para acompanhar o documento **'.$title.'** ('.$code.'). Você não precisa assinar nada; quando o documento for concluído, você receberá a cópia final.',
                $from.' incluiu você para acompanhar o documento **'.$title.'** ('.$code.').',
                'Acompanhar o documento',
                null,
            ],
        };

        $message = (new MailMessage)
            ->subject($this->isReminder ? $reminderSubject : $subject)
            ->greeting('Olá, '.$this->firstName().'!')
            ->line($this->isReminder ? $reminderLine : $intro);

        if (filled($this->envelope->message)) {
            $message->line('Mensagem de quem enviou: "'.MailText::escape($this->envelope->message).'"');
        }

        $message
            ->action($action, $this->signingUrl)
            ->line('Para confirmar que é você, vamos enviar um código de 6 dígitos para este mesmo e-mail.');

        if ($deadline !== null && $this->envelope->expires_at !== null) {
            $message->line($deadline.$this->deadline().'.');
        }

        return $message
            ->line('Este link é pessoal e foi criado só para você — não encaminhe este e-mail.')
            ->salutation('Atenciosamente, AssinaVelox');
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->recipient->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return MailText::escape($parts[0] ?? 'tudo bem?');
    }

    private function deadline(): string
    {
        return $this->envelope->expires_at
            ->setTimezone($this->envelope->organization->timezone)
            ->format('d/m/Y \à\s H:i');
    }
}
