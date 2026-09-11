<?php

namespace App\Services\Batch\Notifications;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Organization;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\AppliesOrganizationBranding;
use App\Notifications\Contracts\TracksDelivery;
use App\Support\MailText;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link de assinatura em lote (docs/fase-2/presencial-e-lote.md §3.2).
 *
 * Mesmo canal rastreado das demais mensagens (`delivery_attempts`, propósito `invitation`,
 * sem envelope: o lote cobre vários). O link em claro viaja só no corpo; a fila é cifrada
 * (`ShouldBeEncrypted`), como no convite individual. O texto diz o que o link faz e o que ele
 * NÃO faz: cada documento é revisado e autorizado separadamente.
 *
 * Mora em `App\Services\Batch\Notifications` porque `app/Notifications` está fora da área
 * C-PRES.
 */
class BatchLinkNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly string $recipientName,
        public readonly string $toAddress,
        public readonly string $url,
        public readonly int $count,
        public readonly CarbonInterface $expiresAt,
        public readonly string $correlationId,
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
        return new DeliveryContext(
            organizationId: (int) $this->organization->getKey(),
            toAddress: $this->toAddress,
            purpose: DeliveryPurpose::Invitation,
            correlationId: $this->correlationId,
            meta: ['batch_items' => $this->count, 'audience' => 'batch'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = MailText::escape($this->organization->name);
        $timezone = $this->organization->timezone ?: 'America/Sao_Paulo';

        $message = (new MailMessage)
            ->subject('Documentos aguardando seu aceite · '.$this->organization->name)
            ->greeting('Olá, '.$this->firstName().'!')
            ->line('**'.$organization.'** tem '.$this->count.' documentos aguardando o seu aceite.')
            ->line('Pelo link abaixo você vê a lista, abre e revisa cada documento e autoriza um de cada vez. Cada autorização registra o aceite só daquele documento; nada é autorizado em conjunto, e documentos enviados depois não entram nesta lista.')
            ->action('Ver documentos pendentes', $this->url)
            ->line('Para abrir, você vai confirmar um código enviado para este e-mail. O link vale até '.$this->expiresAt->copy()->setTimezone($timezone)->format('d/m/Y').'.')
            ->line('Se preferir, continue usando o link individual de cada documento, recebido no convite original.')
            ->salutation('Equipe '.config('app.name'));

        return $this->applyOrganizationBranding($message, $this->organization);
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/', trim($this->recipientName)) ?: [];

        return MailText::escape($parts[0] ?? $this->recipientName);
    }
}
