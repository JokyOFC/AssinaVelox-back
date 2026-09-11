<?php

namespace App\Services\Batch\Notifications;

use App\Enums\DeliveryPurpose;
use App\Integrations\Email\DeliveryContext;
use App\Models\Organization;
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
 * Código de uso único do lote (docs/fase-2/presencial-e-lote.md §3.3).
 *
 * Mesmas regras de `SignerOtpNotification`: canal rastreado (propósito `otp`), fila cifrada,
 * código fora do assunto, fora de `delivery_attempts`, da trilha e dos logs.
 */
class BatchCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly string $recipientName,
        public readonly string $toAddress,
        public readonly string $code,
        public readonly int $ttlMinutes,
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
            purpose: DeliveryPurpose::Otp,
            correlationId: $this->correlationId,
            meta: ['ttl_minutes' => $this->ttlMinutes, 'audience' => 'batch'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Seu código para ver os documentos de '.$this->organization->name)
            ->greeting('Olá, '.$this->firstName().'!')
            ->line('Use o código abaixo para abrir a lista de documentos que **'
                .MailText::escape($this->organization->name).'** enviou para você.')
            ->line('**'.trim(chunk_split($this->code, 3, ' ')).'**')
            ->line('O código vale por '.$this->ttlMinutes.' minutos e só pode ser usado uma vez. Ele abre a lista; cada documento continua sendo autorizado separadamente.')
            ->line('Se você não pediu este código, ignore esta mensagem: sem ele, nada é aberto nem autorizado.')
            ->salutation('Equipe '.config('app.name'));

        return $this->applyOrganizationBranding($message, $this->organization);
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/', trim($this->recipientName)) ?: [];

        return MailText::escape($parts[0] ?? $this->recipientName);
    }
}
