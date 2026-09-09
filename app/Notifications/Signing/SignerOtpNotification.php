<?php

namespace App\Notifications\Signing;

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
 * Código de uso único enviado ao signatário (arquitetura §4.2).
 *
 * Vai pelo canal rastreado, como as demais mensagens do envelope: cada envio produz uma
 * linha em `delivery_attempts` com propósito `otp`, e "enviado" nunca é confundido com
 * "entregue".
 *
 * ## O código e a fila — o compromisso, dito com todas as letras
 *
 * A notificação é enfileirada, então o código viaja no payload do job enquanto ele espera
 * na fila (segundos, normalmente). É exatamente o mesmo compromisso já assumido pelo convite,
 * que carrega o link de assinatura em claro no payload — e a alternativa, enviar no ciclo da
 * requisição, prenderia a resposta da página pública ao tempo do SMTP.
 *
 * O que **não** acontece em nenhum caso: o código não é gravado em `auth_challenges` (lá vai
 * só o HMAC), não entra em `delivery_attempts` (nem em `meta`, nem em `tags`), não aparece em
 * `audit_events` e não é escrito em log — inclusive no `LogEmailProvider`, que registra
 * metadados e não o corpo.
 *
 * O assunto não contém o código: assuntos aparecem em notificações de tela bloqueada e em
 * pré-visualizações de clientes de e-mail.
 */
class SignerOtpNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
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
        return DeliveryContext::forRecipient(
            $this->recipient,
            DeliveryPurpose::Otp,
            $this->correlationId,
            // Metadado, não segredo: só o que ajuda a diagnosticar entrega.
            ['envelope' => $this->envelope->display_code, 'ttl_minutes' => $this->ttlMinutes],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu código para assinar '.$this->envelope->title)
            ->greeting('Olá, '.$this->firstName().'!')
            ->line('Use o código abaixo para confirmar sua identidade e assinar o documento **'
                .$this->envelope->title.'** ('.$this->envelope->display_code.'), enviado por **'
                .$this->envelope->organization->name.'**.')
            ->line('**'.$this->spaced().'**')
            ->line('O código vale por '.$this->ttlMinutes.' minutos e só pode ser usado uma vez.')
            ->line('Se você não pediu este código, ignore esta mensagem: sem ele, nada é assinado.')
            ->salutation('Equipe '.config('app.name'));
    }

    /**
     * "123 456" — só para leitura; a verificação ignora espaços do usuário mas o servidor
     * compara o valor sem espaços.
     */
    private function spaced(): string
    {
        return trim(chunk_split($this->code, 3, ' '));
    }

    private function firstName(): string
    {
        $parts = preg_split('/\s+/', trim($this->recipient->name)) ?: [];

        return $parts[0] ?? $this->recipient->name;
    }
}
