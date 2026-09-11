<?php

namespace App\Services\PublicForms\Notifications;

use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link de confirmação do e-mail de quem preencheu o formulário público
 * (docs/fase-2/formulario-publico.md §5).
 *
 * O link carrega o token de confirmação em claro — como o convite carrega o link de
 * assinatura. Por isso a notificação é `ShouldBeEncrypted` (payload cifrado na fila e em
 * `failed_jobs`); o banco guarda só o HMAC do token, e nada disso vai para log ou trilha.
 * O assunto não contém o link nem o nome do formulário digitado por terceiros.
 *
 * Canal `mail` simples (sem `delivery_attempts`): o rastreio de entrega exige um propósito
 * novo em `DeliveryPurpose`, fora da área deste agente — pendência registrada no documento.
 */
class PublicFormConfirmationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $confirmationUrl,
        public readonly string $formTitle,
        public readonly string $organizationName,
        public readonly string $fillerName,
        public readonly int $ttlMinutes,
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
        $parts = preg_split('/\s+/', trim($this->fillerName)) ?: [];
        $first = MailText::escape($parts[0] ?? $this->fillerName);

        return (new MailMessage)
            ->subject('Confirme seu e-mail para gerar o documento')
            ->greeting('Olá, '.$first.'!')
            ->line('Recebemos as respostas do formulário **'.MailText::escape($this->formTitle).'**, de **'.MailText::escape($this->organizationName).'**.')
            ->line('Para gerar o documento e receber o convite de assinatura, confirme que este e-mail é seu:')
            ->action('Confirmar e-mail', $this->confirmationUrl)
            ->line('O link vale por '.$this->ttlMinutes.' minutos e só pode ser usado uma vez. Sem a confirmação nada é gerado, e as respostas são apagadas quando o link vence.')
            ->line('Se não foi você quem preencheu, ignore esta mensagem.')
            ->salutation('Equipe '.config('app.name'));
    }
}
