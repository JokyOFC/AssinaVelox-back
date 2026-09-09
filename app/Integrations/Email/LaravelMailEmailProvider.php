<?php

namespace App\Integrations\Email;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\OutboundEmail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provedor de e-mail sobre o mailer do Laravel (config/mail.php).
 *
 * Em produção, o serviço de e-mail do proprietário entra **por configuração** — SMTP
 * (`MAIL_MAILER=smtp` + host/porta/credenciais) ou um transporte HTTP registrado no
 * próprio config/mail.php. Nenhum endpoint é inventado aqui: esta classe só fala com o
 * `MailManager`.
 *
 * Semântica do recibo:
 *  - o transporte aceitou a mensagem sem lançar → `sent` ("aceito para entrega");
 *  - o transporte lançou → `failed` com mensagem legível;
 *  - o mailer configurado não transmite nada (`log`, ver `email.inconclusive_mailers`)
 *    → `unknown`, porque escrever no log não é enviar.
 *
 * `delivered` **nunca** sai daqui: exigiria evidência do provedor (webhook/consulta),
 * gravada por `DeliveryRecorder::markDelivered()`.
 */
class LaravelMailEmailProvider implements EmailProvider
{
    public const NAME = 'laravel_mail';

    public function __construct(private readonly MailFactory $mail) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        $mailer = $this->mailerName();

        return $mailer !== '' && config("mail.mailers.{$mailer}") !== null;
    }

    public function send(OutboundEmail $email): DeliveryReceipt
    {
        $mailer = $this->mailerName();
        $provider = $this->name().':'.$mailer;

        if (! $this->isConfigured()) {
            return DeliveryReceipt::failed(
                $provider,
                "O mailer \"{$mailer}\" não está configurado nesta instalação.",
                $email->correlationId,
            );
        }

        try {
            $sent = $this->mail->mailer($mailer)->html(
                $email->htmlBody,
                fn (Message $message) => $this->compose($message, $email),
            );
        } catch (Throwable $exception) {
            // O corpo (que carrega o link de convite) nunca entra no log.
            Log::warning('Falha ao enviar e-mail pelo mailer configurado.', [
                'mailer' => $mailer,
                'correlation_id' => $email->correlationId,
                'tags' => $email->tags,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 300, ''),
            ]);

            return DeliveryReceipt::failed(
                $provider,
                Str::limit($exception->getMessage(), 500, ''),
                $email->correlationId,
            );
        }

        if ($this->isInconclusiveMailer($mailer)) {
            return DeliveryReceipt::unknown(
                $provider,
                "O mailer \"{$mailer}\" apenas registra a mensagem: nada foi transmitido a um servidor de e-mail.",
                $email->correlationId,
            );
        }

        $messageId = null;

        try {
            $messageId = $sent?->getMessageId();
        } catch (Throwable) {
            // Nem todo transporte devolve Message-ID; a ausência não muda o estado.
        }

        return DeliveryReceipt::sent($provider, $messageId, $email->correlationId);
    }

    private function compose(Message $message, OutboundEmail $email): void
    {
        $message->to($email->toAddress, $email->toName !== null && $email->toName !== '' ? $email->toName : null);
        $message->subject($email->subject);

        if ($email->fromAddress !== null) {
            $message->from($email->fromAddress, $email->fromName);
        }

        if ($email->replyToAddress !== null) {
            $message->replyTo($email->replyToAddress);
        }

        if ($email->textBody !== null && $email->textBody !== '') {
            $message->text($email->textBody);
        }

        foreach ($email->headers as $name => $value) {
            $message->getHeaders()->addTextHeader($name, $value);
        }

        foreach ($email->attachments as $attachment) {
            $message->attach($attachment->path, [
                'as' => $attachment->filename,
                'mime' => $attachment->mimeType,
            ]);
        }
    }

    private function mailerName(): string
    {
        $configured = config('assinavelox.email.mailer');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) config('mail.default', 'smtp');
    }

    private function isInconclusiveMailer(string $mailer): bool
    {
        /** @var list<string> $inconclusive */
        $inconclusive = (array) config('assinavelox.email.inconclusive_mailers', ['log']);

        return in_array($mailer, $inconclusive, true);
    }
}
