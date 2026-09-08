<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\DeliveryReceipt;

/**
 * Fase 2/3 — sem implementação. Envio de SMS (OTP por sms_otp, lembretes).
 *
 * Reservado para manter o vocabulário estável: canal `sms` em
 * delivery_attempts.channel; recibo com o mesmo DeliveryReceipt do e-mail.
 */
interface SmsProvider
{
    /**
     * @param  string  $toE164  número no formato E.164 (ex.: +5511999999999)
     */
    public function send(string $toE164, string $message, ?string $correlationId = null): DeliveryReceipt;

    public function isConfigured(): bool;

    public function name(): string;
}
