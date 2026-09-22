<?php

namespace App\Console\Commands;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\DeliveryReceiptStatus;
use App\Integrations\Dto\OutboundEmail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `php artisan assinavelox:mail-test destinatario@exemplo.com [--json]`
 *
 * Envia UMA mensagem de teste pelo mesmo caminho dos convites e dos códigos — o
 * {@see EmailProvider} configurado — e mostra o recibo. É o teste que o `assinavelox:doctor`
 * não faz: ele confere a configuração sem abrir conexão; este entrega de verdade, então só
 * roda quando alguém pede, para um endereço que alguém escolheu.
 *
 * Nunca imprime usuário, senha nem URL do transporte. `sent` quer dizer "o servidor SMTP
 * aceitou a mensagem", não "chegou na caixa de entrada" (docs/envio-e-convites.md §9).
 */
class MailTestCommand extends Command
{
    protected $signature = 'assinavelox:mail-test
        {to : Endereço que vai receber a mensagem de teste}
        {--json : Saída em JSON}';

    protected $description = 'Envia um e-mail de teste pelo provedor configurado e mostra o recibo (sem segredos).';

    public function handle(EmailProvider $provider): int
    {
        $to = trim((string) $this->argument('to'));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("\"{$to}\" não é um endereço de e-mail.");

            return self::INVALID;
        }

        $mailer = (string) (config('assinavelox.email.mailer') ?: config('mail.default'));
        $correlationId = (string) Str::ulid();
        $sentAt = CarbonImmutable::now()->toIso8601String();
        $appName = (string) config('app.name');

        $receipt = $provider->send(new OutboundEmail(
            toAddress: $to,
            toName: null,
            subject: "Teste de envio — {$appName}",
            htmlBody: '<p>Esta é uma mensagem de teste do '.e($appName).'.</p>'
                .'<p>Se você a recebeu, o servidor de e-mail configurado aceitou e entregou o envio.</p>'
                .'<p style="color:#6b7891;font-size:12px">Enviada em '.e($sentAt).' · referência '.e($correlationId).'</p>',
            textBody: "Esta é uma mensagem de teste do {$appName}.\n"
                ."Se você a recebeu, o servidor de e-mail configurado aceitou e entregou o envio.\n\n"
                ."Enviada em {$sentAt} · referência {$correlationId}",
            tags: ['mail-test'],
            correlationId: $correlationId,
        ));

        $report = [
            'ok' => $receipt->status === DeliveryReceiptStatus::Sent,
            'to' => $to,
            'provider' => $receipt->provider,
            'mailer' => $mailer,
            'status' => $receipt->status->value,
            'message_id' => $receipt->providerMessageId,
            'error' => $receipt->error,
            'correlation_id' => $correlationId,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Provedor', $receipt->provider);
        $this->components->twoColumnDetail('Mailer', $mailer);
        $this->components->twoColumnDetail('Destinatário', $to);
        $this->components->twoColumnDetail('Recibo', $receipt->status->value);

        if ($receipt->providerMessageId !== null) {
            $this->components->twoColumnDetail('Identificador da mensagem', $receipt->providerMessageId);
        }

        match ($receipt->status) {
            DeliveryReceiptStatus::Sent => $this->components->info('O servidor aceitou a mensagem. Confira a caixa de entrada (e o spam) do destinatário.'),
            DeliveryReceiptStatus::Unknown => $this->components->warn('Nada foi transmitido: '.($receipt->error ?? 'o mailer configurado só registra a mensagem.')),
            default => $this->components->error('O envio falhou: '.($receipt->error ?? 'sem detalhe do provedor.').' Rode `php artisan assinavelox:doctor` para conferir servidor, credenciais e criptografia.'),
        };

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
