<?php

namespace App\Support\Locale;

use App\Models\Organization;
use App\Models\Recipient;
use Carbon\CarbonInterface;

/**
 * E-mails ao participante no idioma dele (Fase 3 §3.3 — F-I18N, docs/fase-3/multilingue.md §6).
 *
 * Usado pelas notificações ao participante (convite, lembrete, código, cancelamento, prazo). No
 * construtor, {@see self::localizeFor()} define `Notification::$locale` — só quando a flag
 * `multilingual` está ligada para a organização e o idioma do participante não é o PT-BR. Com a
 * flag desligada nada muda: `$locale` fica nulo, o Laravel não troca o idioma e os textos saem
 * de `lang/pt_BR/signer_mail.php`, idênticos aos de sempre.
 *
 * Os textos são lidos com o idioma EXPLÍCITO (`__()` com o terceiro argumento), sem depender do
 * idioma corrente da aplicação.
 */
trait LocalizesRecipientMail
{
    /** Fuso das datas do e-mail: o do participante com a flag ligada; nulo = fuso da organização. */
    public ?string $recipientTimezone = null;

    protected function localizeFor(Recipient $recipient, ?Organization $organization = null): void
    {
        $organization ??= Organization::query()->find($recipient->organization_id);

        if (! SignerLocales::enabledFor($organization)) {
            return;
        }

        $this->locale = SignerLocales::notificationLocale($recipient, $organization);
        $this->recipientTimezone = SignerLocales::timezone($recipient, $organization);
    }

    protected function mailLocale(): SignerLocale
    {
        return SignerLocale::tryFromInput($this->locale) ?? SignerLocale::reference();
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected function mailText(string $key, array $replace = []): string
    {
        $text = __('signer_mail.'.$key, $replace, $this->mailLocale()->value);

        return is_string($text) ? $text : '';
    }

    protected function mailDeadline(CarbonInterface $moment, ?string $organizationTimezone): string
    {
        return SignerLocales::deadline(
            $moment,
            $this->mailLocale(),
            $this->recipientTimezone ?? $organizationTimezone ?? Organization::DEFAULT_TIMEZONE,
        );
    }
}
