<?php

namespace App\Notifications\Signing;

use App\Enums\DeliveryPurpose;
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
 *
 * ## Em repouso, na fila
 *
 * A notificação implementa `ShouldBeEncrypted`: o payload do job vai cifrado com a APP_KEY
 * para o armazenamento da fila (`jobs` no banco, Redis em produção), para `failed_jobs` e
 * para o painel do Horizon. Sem isso o segredo ficaria legível — e uma falha definitiva de
 * entrega copia o payload inteiro para `failed_jobs`, que é podado por agendamento
 * (`queue:prune-failed`, routes/console.php) mas não é imediato.
 */
class SignerOtpNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, TracksDelivery
{
    use AppliesOrganizationBranding, LocalizesRecipientMail, Queueable;

    public function __construct(
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly string $code,
        public readonly int $ttlMinutes,
        public readonly string $correlationId,
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
            DeliveryPurpose::Otp,
            $this->correlationId,
            // Metadado, não segredo: só o que ajuda a diagnosticar entrega.
            ['envelope' => $this->envelope->display_code, 'ttl_minutes' => $this->ttlMinutes],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Fase 3 §3.3 (F-I18N): textos de `lang/{idioma}/signer_mail.php`, no idioma do participante
        // quando a flag `multilingual` está ligada (PT-BR, idêntico ao de sempre, quando não).
        $message = (new MailMessage)
            ->subject($this->mailText('otp.subject', ['title' => $this->envelope->title]))
            ->greeting($this->mailText('greeting_named', ['name' => $this->firstName()]))
            ->line($this->mailText('otp.line', [
                'title' => MailText::escape($this->envelope->title),
                'code' => $this->envelope->display_code,
                'organization' => MailText::escape($this->envelope->organization->name),
            ]))
            ->line('**'.$this->spaced().'**')
            ->line($this->mailText('otp.ttl', ['minutes' => $this->ttlMinutes]))
            ->line($this->mailText('otp.ignore'))
            ->salutation($this->mailText('otp.salutation', ['app' => (string) config('app.name')]));

        return $this->applyOrganizationBranding($message, $this->envelope->organization);
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

        return MailText::escape($parts[0] ?? $this->recipient->name);
    }
}
