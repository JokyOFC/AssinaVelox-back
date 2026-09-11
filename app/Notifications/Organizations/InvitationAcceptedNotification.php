<?php

namespace App\Notifications\Organizations;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Membership;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Novo membro entrou na conta" — evento `invitation_accepted` da tela de Notificações
 * (ROUTES §2.14). Vai para quem convidou e para os proprietários/administradores que
 * mantiveram o interruptor ligado.
 *
 * Não é aviso de cortesia: entrar na organização dá acesso aos documentos dela, e quem
 * responde pela conta precisa saber quando isso acontece, sem depender de abrir a tela de
 * membros. É também o par do convite — a plataforma avisou que o convite saiu e ficava
 * calada quando ele era usado.
 */
class InvitationAcceptedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly Membership $membership,
        public readonly string $correlationId,
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
            organizationId: (int) $this->membership->organization_id,
            toAddress: method_exists($notifiable, 'routeNotificationFor')
                ? (string) ($notifiable->routeNotificationFor('mail') ?: '')
                : '',
            purpose: DeliveryPurpose::InvitationAccepted,
            correlationId: $this->correlationId,
            meta: ['audience' => 'admins'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->membership->organization;
        $member = $this->membership->user;

        return (new MailMessage)
            ->subject('Novo membro em '.$organization->name)
            ->greeting('Olá!')
            ->line('**'.MailText::escape($member->name ?? $member->email).'** aceitou o convite e agora faz parte de **'
                .MailText::escape($organization->name).'** como '.MailText::escape($this->membership->roleLabel()).'.')
            ->action('Ver os membros', route('members.index'))
            ->line('Se não era essa a intenção, remova o acesso em Configurações › Membros.')
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invitation_accepted',
            'organization_ulid' => $this->membership->organization->ulid,
            'member_name' => $this->membership->user->name,
            'member_email' => $this->membership->user->email,
            'role' => $this->membership->role->value,
        ];
    }
}
