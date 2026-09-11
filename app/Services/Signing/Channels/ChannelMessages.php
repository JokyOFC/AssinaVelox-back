<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use Illuminate\Support\Str;

/**
 * Textos e templates das mensagens por SMS/WhatsApp. Texto de usuário (título, nome da
 * organização) entra limpo de quebras e caracteres de controle e com tamanho limitado.
 *
 * O WhatsApp exige template pré-aprovado por finalidade (`channels.whatsapp.templates`); o
 * SMS usa o texto pronto. Os dois recebem os mesmos parâmetros nomeados.
 */
final class ChannelMessages
{
    public static function template(DeliveryChannel $channel, DeliveryPurpose $purpose): string
    {
        if ($channel === DeliveryChannel::Whatsapp) {
            $templates = (array) config('assinavelox.channels.whatsapp.templates', []);

            return (string) ($templates[$purpose->value] ?? $templates['invitation'] ?? 'assinavelox_'.$purpose->value);
        }

        return 'sms_'.$purpose->value;
    }

    public static function otpText(string $code, string $title, int $ttlMinutes): string
    {
        return sprintf(
            '%s: seu código de confirmação para o documento "%s" é %s. Vale %d min. Não compartilhe este código.',
            self::plain((string) config('app.name', 'AssinaVelox'), 40),
            self::plain($title, 40),
            $code,
            $ttlMinutes,
        );
    }

    public static function invitationText(string $organization, string $title, string $url, bool $isReminder): string
    {
        return sprintf(
            $isReminder ? 'Lembrete: %s aguarda você no documento "%s" pelo %s. Acesse: %s' : '%s enviou o documento "%s" para você pelo %s. Acesse: %s',
            self::plain($organization, 40),
            self::plain($title, 40),
            self::plain((string) config('app.name', 'AssinaVelox'), 40),
            $url,
        );
    }

    /**
     * Uma linha, sem controle, com tamanho máximo.
     */
    public static function plain(string $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return Str::limit($clean, $limit, '…');
    }

    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return self::plain($parts[0] ?? $name, 30);
    }
}
