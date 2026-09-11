<?php

namespace App\Integrations\Contracts;

/**
 * Envio de SMS (código `sms_otp`, aviso de convite) pelo serviço PRÓPRIO do proprietário.
 *
 * Implementações: App\Integrations\Sms\FakeSmsProvider (simulador identificado) e
 * App\Integrations\Sms\HttpSmsProvider (produção, desabilitado até existir documentação).
 * Contrato completo em {@see MessagingProvider}; canal `sms` em delivery_attempts.channel.
 */
interface SmsProvider extends MessagingProvider {}
