<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\OutboundEmail;

/**
 * Envio de e-mail transacional (convites, OTP, conclusão).
 *
 * Implementações previstas (incremento 3): LaravelMailEmailProvider (mailer
 * configurado) e LogEmailProvider (fake de desenvolvimento, identificado).
 * Contrato: nunca lançar por falha do provedor — devolver DeliveryReceipt
 * failed|unknown com `error` legível; o chamador grava delivery_attempts.
 * Resposta inconclusiva (unknown) ≠ sucesso. Repetição deve ser idempotente
 * por correlationId.
 */
interface EmailProvider
{
    public function send(OutboundEmail $email): DeliveryReceipt;

    public function isConfigured(): bool;

    /**
     * Nome curto do provedor gravado em delivery_attempts.provider.
     */
    public function name(): string;
}
