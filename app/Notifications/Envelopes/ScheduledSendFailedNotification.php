<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao remetente: o envio agendado NÃO saiu (Fase 2 §2.5, roadmap: "envio agendado que
 * falha por cota volta a `ready` com notificação ao remetente").
 *
 * O envelope continua como estava (`ready` ou rascunho) e o agendamento foi cancelado; o
 * texto diz por quê, com a mesma mensagem PT-BR que o botão "Enviar" mostraria.
 *
 * Operacional, não opcional: não há evento no catálogo de preferências para desligá-lo —
 * um documento que o remetente acredita ter sido enviado e não foi é exatamente o que ele
 * precisa saber. Sai nos dois canais (e-mail e sino).
 */
class ScheduledSendFailedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly string $reason,
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
            organizationId: (int) $this->envelope->organization_id,
            toAddress: method_exists($notifiable, 'routeNotificationFor')
                ? (string) ($notifiable->routeNotificationFor('mail') ?: '')
                : '',
            purpose: DeliveryPurpose::ScheduledSend,
            correlationId: $this->correlationId,
            envelopeId: $this->envelope->getKey(),
            meta: ['envelope' => $this->envelope->display_code, 'audience' => 'sender'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Envio agendado não realizado: '.$this->envelope->title)
            ->greeting('Olá!')
            ->line('O envio agendado do documento **'.MailText::escape($this->envelope->title).'** ('.$this->envelope->display_code.') não foi realizado e o agendamento foi cancelado.')
            ->line('Motivo: '.MailText::escape($this->reason))
            ->action('Abrir o documento', route('envelopes.edit', $this->envelope))
            ->line('Nenhum convite foi enviado e nada foi descontado do seu plano. Corrija o que for preciso e envie ou agende de novo.')
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->envelope->organization_id,
            'title' => 'Envio agendado não realizado',
            'body' => $this->envelope->title.': '.$this->reason,
            'url' => route('envelopes.edit', $this->envelope),
            'event' => 'scheduled_send_failed',
        ];
    }
}
