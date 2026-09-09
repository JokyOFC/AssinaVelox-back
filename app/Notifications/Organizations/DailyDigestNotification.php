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
 * "Resumo diário de pendências" — evento `daily_digest` da tela de Notificações
 * (ROUTES §2.14), o único do catálogo que promete HORÁRIO na interface
 * ("enviado às 08:00 (America/Sao_Paulo)").
 *
 * Um e-mail por dia útil, só quando há o que contar: um resumo que chega dizendo "nada
 * pendente" todo dia é ruído e treina o destinatário a ignorá-lo.
 *
 * O canal `database` é travado no catálogo — o sino do app já mostra os documentos, e
 * repetir a lista ali não acrescenta nada.
 */
class DailyDigestNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    /**
     * @param  list<array{title: string, code: string, pending: int, expires_at: string|null}>  $items
     */
    public function __construct(
        public readonly Membership $membership,
        public readonly array $items,
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
            purpose: DeliveryPurpose::DailyDigest,
            correlationId: $this->correlationId,
            meta: ['audience' => 'sender', 'items' => count($this->items)],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->membership->organization;

        $message = (new MailMessage)
            ->subject('Resumo de pendências — '.$organization->name)
            ->greeting('Bom dia!')
            ->line('Documentos de **'.MailText::escape($organization->name).'** que ainda aguardam assinatura:');

        foreach ($this->items as $item) {
            $line = '• '.MailText::escape($item['title']).' ('.$item['code'].') — '
                .$item['pending'].' signatário(s) pendente(s)';

            if ($item['expires_at'] !== null) {
                $line .= ', prazo até '.$item['expires_at'];
            }

            $message->line($line.'.');
        }

        return $message
            ->action('Abrir o painel', route('dashboard'))
            ->line('Para deixar de receber este resumo, desligue "Resumo diário de pendências" em Configurações › Notificações.')
            ->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'daily_digest',
            'organization_ulid' => $this->membership->organization->ulid,
            'items' => $this->items,
        ];
    }
}
