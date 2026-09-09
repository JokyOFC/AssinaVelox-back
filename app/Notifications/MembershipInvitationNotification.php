<?php

namespace App\Notifications;

use App\Models\MembershipInvitation;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite para entrar em uma organização. O token bruto só existe nesta mensagem
 * (link /convites/{token}); o banco guarda apenas o digest.
 *
 * `ShouldBeEncrypted` porque o token viaja no payload do job: o que fica no armazenamento
 * da fila, em `failed_jobs` e no painel do Horizon é cifrado com a APP_KEY.
 */
class MembershipInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MembershipInvitation $invitation,
        public readonly string $token,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization;
        $inviter = $this->invitation->inviter;
        $expiresAt = $this->invitation->expires_at
            ->setTimezone($organization->timezone)
            ->format('d/m/Y \à\s H:i');

        return (new MailMessage)
            ->subject('Convite para participar de '.$organization->name.' no AssinaVelox')
            ->greeting('Olá!')
            ->line(MailText::escape($inviter->name ?? 'Um administrador').' convidou você para participar da organização **'.MailText::escape($organization->name).'** como '.$this->invitation->role->label().'.')
            ->action('Aceitar convite', $this->acceptUrl())
            ->line('Este convite expira em '.$expiresAt.'.')
            ->line('Se você não esperava este convite, ignore este e-mail.')
            ->salutation('Atenciosamente, equipe AssinaVelox');
    }

    public function acceptUrl(): string
    {
        return route('invitations.accept', ['token' => $this->token]);
    }
}
