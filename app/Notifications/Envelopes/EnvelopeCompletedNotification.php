<?php

namespace App\Notifications\Envelopes;

use App\Enums\DeliveryPurpose;
use App\Enums\SignatureStatus;
use App\Integrations\Email\DeliveryContext;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Channels\TrackedMailChannel;
use App\Notifications\Concerns\RestrictsChannels;
use App\Notifications\Contracts\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Documento concluído. Vai para cada signatário (com link de download autorizado) e para
 * quem enviou (com o link do detalhe no app).
 *
 * Vocabulário obrigatório (arquitetura §2): quando não há certificado da operadora
 * configurado, o texto diz **aceite eletrônico com evidências** — nunca "assinado
 * digitalmente". A distinção vem de `verification_records.signature_status`, preenchido na
 * finalização (outro incremento); enquanto não existe registro, o texto é o conservador.
 */
class EnvelopeCompletedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly string $correlationId,
        public readonly ?Recipient $recipient = null,
        public readonly ?string $downloadUrl = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->recipient !== null
            ? [TrackedMailChannel::class]
            : $this->channelsFrom(['mail' => TrackedMailChannel::class, 'database' => 'database']);
    }

    public function deliveryContext(object $notifiable): DeliveryContext
    {
        if ($this->recipient !== null) {
            return DeliveryContext::forRecipient(
                $this->recipient,
                DeliveryPurpose::Completed,
                $this->correlationId,
                ['envelope' => $this->envelope->display_code],
            );
        }

        return new DeliveryContext(
            organizationId: (int) $this->envelope->organization_id,
            toAddress: method_exists($notifiable, 'routeNotificationFor')
                ? (string) ($notifiable->routeNotificationFor('mail') ?: '')
                : '',
            purpose: DeliveryPurpose::Completed,
            correlationId: $this->correlationId,
            envelopeId: $this->envelope->getKey(),
            meta: ['envelope' => $this->envelope->display_code, 'audience' => 'sender'],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Documento concluído: '.$this->envelope->title)
            ->greeting('Boa notícia!')
            ->line('Todos os signatários concluíram **'.$this->envelope->title.'** ('.$this->envelope->display_code.').')
            ->line($this->closingStatement());

        $url = $this->downloadUrl ?? ($this->recipient === null ? route('envelopes.show', $this->envelope) : null);

        if ($url !== null) {
            $message->action($this->recipient !== null ? 'Baixar o documento' : 'Abrir no AssinaVelox', $url);
        }

        if ($this->envelope->verification_code !== null) {
            $message->line('Código de verificação pública: **'.$this->envelope->formatted_verification_code.'** — confira em '.route('verify.index').'.');
        }

        return $message->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->envelope->organization_id,
            'title' => 'Documento concluído',
            'body' => $this->envelope->title.' ('.$this->envelope->display_code.') foi concluído.',
            'url' => route('envelopes.show', $this->envelope),
            'event' => 'envelope_completed',
        ];
    }

    /**
     * Frase que descreve o que o arquivo final é — sem prometer assinatura criptográfica
     * que não existe.
     */
    private function closingStatement(): string
    {
        $record = $this->envelope->relationLoaded('verificationRecord')
            ? $this->envelope->verificationRecord
            : $this->envelope->verificationRecord()->first();

        return $record?->signature_status === SignatureStatus::CompanyA1
            ? 'O arquivo final reúne as evidências do aceite eletrônico de cada participante e recebeu a assinatura criptográfica da AssinaVelox (PAdES), que identifica a operadora do serviço.'
            : 'O arquivo final reúne as evidências do aceite eletrônico de cada participante: data e hora do servidor, endereço IP, autenticação por código enviado por e-mail e o hash do documento apresentado.';
    }
}
