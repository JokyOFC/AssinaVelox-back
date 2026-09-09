<?php

namespace App\Integrations\Email;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\OutboundEmail;
use Illuminate\Support\Facades\Log;

/**
 * Fake de DESENVOLVIMENTO. **Nada é enviado.**
 *
 * Cada chamada escreve uma linha marcada `[FAKE]` no log, com metadados apenas — assunto,
 * destinatário, propósito e correlation_id. O CORPO nunca é registrado: é ele que carrega
 * o link de convite (token) e, no futuro, o código OTP.
 *
 * O recibo é sempre `unknown`, jamais `sent`: escrever no log não é enviar, e o resto da
 * aplicação trata `unknown` como não-sucesso. Isso torna impossível confundir o ambiente de
 * desenvolvimento com entregas reais em `delivery_attempts`.
 */
class LogEmailProvider implements EmailProvider
{
    public const NAME = 'log_fake';

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundEmail $email): DeliveryReceipt
    {
        $message = '[FAKE] E-mail NÃO enviado — LogEmailProvider (desenvolvimento).';
        $context = [
            'provider' => self::NAME,
            'to' => $email->toAddress,
            'subject' => $email->subject,
            'tags' => $email->tags,
            'correlation_id' => $email->correlationId,
            'attachments' => count($email->attachments),
            'note' => 'Corpo omitido de propósito: carrega o link de convite.',
        ];

        $channel = config('assinavelox.email.log_channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->info($message, $context);
        } else {
            Log::info($message, $context);
        }

        return DeliveryReceipt::unknown(
            self::NAME,
            'Provedor de log (desenvolvimento): a mensagem foi apenas registrada, nada foi transmitido.',
            $email->correlationId,
        );
    }
}
