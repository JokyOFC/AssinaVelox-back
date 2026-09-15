<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Support\Locale\SignerLocale;
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

    /**
     * `$locale`: idioma do participante (SignerLocales::forRecipient — já considera a flag
     * `multilingual`). Nulo ou PT-BR = exatamente o texto de sempre; `en`/`es` saem de
     * `lang/{locale}/signer_mail.php` (`channel.*`), com os valores inseridos como texto.
     */
    public static function otpText(string $code, string $title, int $ttlMinutes, ?SignerLocale $locale = null): string
    {
        if ($locale !== null && ! $locale->isReference()) {
            return self::translated('signer_mail.channel.otp', [
                'app' => self::plain((string) config('app.name', 'AssinaVelox'), 40),
                'title' => self::plain($title, 40),
                'code' => $code,
                'minutes' => (string) $ttlMinutes,
            ], $locale);
        }

        return sprintf(
            '%s: seu código de confirmação para o documento "%s" é %s. Vale %d min. Não compartilhe este código.',
            self::plain((string) config('app.name', 'AssinaVelox'), 40),
            self::plain($title, 40),
            $code,
            $ttlMinutes,
        );
    }

    public static function invitationText(string $organization, string $title, string $url, bool $isReminder, ?SignerLocale $locale = null): string
    {
        if ($locale !== null && ! $locale->isReference()) {
            return self::translated($isReminder ? 'signer_mail.channel.reminder' : 'signer_mail.channel.invitation', [
                'organization' => self::plain($organization, 40),
                'title' => self::plain($title, 40),
                'app' => self::plain((string) config('app.name', 'AssinaVelox'), 40),
                'url' => $url,
            ], $locale);
        }

        return sprintf(
            $isReminder ? 'Lembrete: %s aguarda você no documento "%s" pelo %s. Acesse: %s' : '%s enviou o documento "%s" para você pelo %s. Acesse: %s',
            self::plain($organization, 40),
            self::plain($title, 40),
            self::plain((string) config('app.name', 'AssinaVelox'), 40),
            $url,
        );
    }

    /**
     * Substituição de marcadores sem reinterpretar o valor (um título com ":code" continua texto).
     *
     * @param  array<string, string>  $values
     */
    private static function translated(string $key, array $values, SignerLocale $locale): string
    {
        $template = trans($key, [], $locale->value);

        if (! is_string($template) || $template === $key) {
            $template = (string) trans($key, [], SignerLocale::reference()->value);
        }

        $pairs = [];

        foreach ($values as $name => $value) {
            $pairs[':'.$name] = $value;
        }

        // Marcadores mais longos primeiro; strtr não substitui de novo o que já substituiu.
        return strtr($template, $pairs);
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
