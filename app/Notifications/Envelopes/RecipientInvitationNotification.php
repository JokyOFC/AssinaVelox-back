<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Enums\RecipientRole;
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
 *
 * Fase 3 §3.3 (F-I18N): os textos vêm de `lang/{idioma}/signer_mail.php`, no idioma do
 * participante quando a flag `multilingual` está ligada (PT-BR, idêntico ao de sempre, quando não).
 */
class RecipientInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, LocalizesRecipientMail, Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $signingUrl,
        public readonly string $correlationId,
        public readonly bool $isReminder = false,
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
            $this->isReminder ? DeliveryPurpose::Resend : DeliveryPurpose::Invitation,
            $this->correlationId,
            ['envelope' => $this->envelope->display_code],
        );
    }

    /**
     * Fase 2 §2.4: papéis que não assinam como signatário ganham texto próprio (arquitetura §2 —
     * aprovar, testemunhar e acompanhar não são "assinar"). O texto do signatário, único papel da
     * Fase 1, continua idêntico.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->role();
        $key = match ($role) {
            RecipientRole::Witness => 'witness',
            RecipientRole::Approver => 'approver',
            RecipientRole::Viewer => 'viewer',
            default => 'signer',
        };

        $organization = $this->envelope->organization;
        $sender = $this->envelope->creator;

        // Corpo em Markdown: texto de terceiros escapado (MailText). Assunto: texto puro.
        $body = [
            'sender' => MailText::escape($sender->name ?? $this->mailText('sender_fallback')),
            'organization' => MailText::escape($organization->name),
            'title' => MailText::escape($this->envelope->title),
            'code' => $this->envelope->display_code,
        ];
        $subject = ['organization' => $organization->name, 'title' => $this->envelope->title];

        $message = (new MailMessage)
            ->subject($this->isReminder
                ? $this->mailText("invitation.{$key}.reminder_subject", $subject)
                : $this->mailText("invitation.{$key}.subject", $subject))
            ->greeting($this->mailText('greeting_named', ['name' => $this->firstName()]))
            ->line($this->isReminder
                ? $this->mailText("invitation.{$key}.reminder_line", $body)
                : $this->mailText("invitation.{$key}.intro", $body));

        if (filled($this->envelope->message)) {
            $message->line($this->mailText('sender_message', ['message' => MailText::escape($this->envelope->message)]));
        }

        $message
            ->action($this->mailText("invitation.{$key}.".($this->isReminder ? 'reminder_action' : 'action')), $this->signingUrl)
            ->line($this->mailText('code_notice'));

        // O visualizador não tem prazo para "assinar": a linha não existe para ele.
        if ($role !== RecipientRole::Viewer && $this->envelope->expires_at !== null) {
            $message->line($this->mailText("invitation.{$key}.deadline", ['deadline' => $this->deadline()]));
        }

        $message
            ->line($this->mailText('personal_link'))
            ->salutation($this->mailText('salutation'));

        // Fase 2 §2.8: com a marca ativa, só o tema (cabeçalho, botão) e o Reply-To mudam.
        return $this->applyOrganizationBranding($message, $organization);
    }

    private function role(): RecipientRole
    {
        // `recipients.role` é cast para o enum; linhas antigas nascem `signer` (default).
        return $this->recipient->role;
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
