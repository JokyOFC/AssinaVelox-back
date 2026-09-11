<?php

namespace App\Enums;

/**
 * Método de autenticação do participante, escolhido pelo REMETENTE no wizard.
 *
 * Semântica (arquitetura §2, roadmap T1): cada método prova a **posse de um canal** — a
 * caixa de e-mail ou o número de celular que recebeu o código. Nenhum deles prova
 * identidade civil. O PIN do remetente (Fase 2 §2.9, `recipient_pins`) não é um método:
 * é um segredo compartilhado, exigido DEPOIS do código de qualquer canal.
 *
 * SMS e WhatsApp dependem da flag `sms_whatsapp` e de um provedor disponível
 * (App\Services\Signing\Channels\ChannelAvailability) — ver docs/fase-2/canais-e-pin.md.
 */
enum AuthMethod: string
{
    case EmailOtp = 'email_otp';
    case SmsOtp = 'sms_otp';
    case WhatsappOtp = 'whatsapp_otp';

    public function label(): string
    {
        return match ($this) {
            self::EmailOtp => 'Código por e-mail',
            self::SmsOtp => 'Código por SMS',
            self::WhatsappOtp => 'Código por WhatsApp',
        };
    }

    /**
     * Canal por onde o código deste método sai.
     */
    public function channel(): DeliveryChannel
    {
        return match ($this) {
            self::EmailOtp => DeliveryChannel::Email,
            self::SmsOtp => DeliveryChannel::Sms,
            self::WhatsappOtp => DeliveryChannel::Whatsapp,
        };
    }

    public static function forChannel(DeliveryChannel $channel): self
    {
        return match ($channel) {
            DeliveryChannel::Email => self::EmailOtp,
            DeliveryChannel::Sms => self::SmsOtp,
            DeliveryChannel::Whatsapp => self::WhatsappOtp,
        };
    }

    /**
     * O método exige `recipients.phone` válido em E.164.
     */
    public function requiresPhone(): bool
    {
        return $this->channel()->requiresPhone();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
