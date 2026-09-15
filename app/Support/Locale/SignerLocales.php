<?php

namespace App\Support\Locale;

use App\Enums\AuditEventType;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Qual idioma vale para um participante (Fase 3 §3.3 — F-I18N, docs/fase-3/multilingue.md §4).
 *
 * 1. Flag `multilingual` desligada para a organização → sempre PT-BR (o comportamento de hoje).
 * 2. O participante trocou o idioma de exibição NESTE navegador → a escolha da sessão. Ela não
 *    altera `recipients.locale`: o que o remetente registrou continua valendo para os e-mails.
 * 3. Senão, `recipients.locale` (definido pelo remetente no wizard).
 *
 * Todo valor passa por {@see SignerLocale::tryFromInput()}: o que não está na lista fechada cai
 * no idioma de referência e nunca é usado como caminho, chave ou nome de arquivo.
 */
final class SignerLocales
{
    /** Chave da sessão: `signer_display_locale.{ulid do participante}` => código do idioma. */
    public const SESSION_KEY = 'signer_display_locale';

    /** Atributo da requisição com o idioma resolvido pelo middleware (só com a flag ligada). */
    public const ATTRIBUTE = 'signer.display_locale';

    public static function enabledFor(?Organization $organization): bool
    {
        return MultilingualFeature::enabled($organization);
    }

    /** Idioma gravado pelo remetente (sem olhar a flag). Valor inválido ou ausente = PT-BR. */
    public static function stored(Recipient $recipient): SignerLocale
    {
        return SignerLocale::tryFromInput($recipient->getAttribute('locale')) ?? SignerLocale::reference();
    }

    /**
     * Idioma da página pública para este participante neste navegador.
     */
    public function forContext(SignerContext $context, Request $request): SignerLocale
    {
        if (! self::enabledFor($context->organization)) {
            return SignerLocale::reference();
        }

        $chosen = $request->hasSession()
            ? $request->session()->get(self::sessionKey($context->recipient))
            : null;

        return SignerLocale::tryFromInput($chosen) ?? self::stored($context->recipient);
    }

    /**
     * Idioma resolvido para a requisição corrente pelo middleware `ApplySignerLocale`, ou
     * `null` quando a flag está desligada (ou a rota não passa pelo middleware).
     */
    public static function current(?Request $request = null): ?SignerLocale
    {
        $request ??= request();
        $locale = $request->attributes->get(self::ATTRIBUTE);

        return $locale instanceof SignerLocale ? $locale : null;
    }

    /**
     * Idioma dos e-mails e das mensagens ao participante: o registrado pelo remetente, só com a
     * flag ligada para a organização do participante.
     */
    public static function forRecipient(Recipient $recipient, ?Organization $organization = null): SignerLocale
    {
        $organization ??= Organization::query()->find($recipient->organization_id);

        return self::enabledFor($organization) ? self::stored($recipient) : SignerLocale::reference();
    }

    /**
     * Valor para `Notification::$locale`: `null` no idioma de referência — o Laravel então não
     * troca o idioma da aplicação e a mensagem sai exatamente como antes.
     */
    public static function notificationLocale(Recipient $recipient, ?Organization $organization = null): ?string
    {
        $locale = self::forRecipient($recipient, $organization);

        return $locale->isReference() ? null : $locale->value;
    }

    /**
     * O participante troca o idioma de EXIBIÇÃO: vale para esta sessão e vai para a trilha.
     */
    public function switchTo(SignerContext $context, Request $request, SignerLocale $to): void
    {
        $from = $this->forContext($context, $request);

        $request->session()->put(self::sessionKey($context->recipient), $to->value);

        if ($from === $to) {
            return;
        }

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::RecipientDisplayLocaleChanged, [
            'from' => $from->value,
            'to' => $to->value,
            // O que o remetente registrou não muda: a troca é só de exibição.
            'stored' => self::stored($context->recipient)->value,
        ]);
    }

    /**
     * Fuso para datas mostradas ao participante: o dele (se válido) ou o da organização.
     */
    public static function timezone(Recipient $recipient, ?Organization $organization = null): string
    {
        $timezone = $recipient->getAttribute('timezone');

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        $organization ??= Organization::query()->find($recipient->organization_id);

        return (string) ($organization->timezone ?? Organization::DEFAULT_TIMEZONE);
    }

    /**
     * "18/09/2026 às 17:30" (PT-BR, idêntico ao de sempre), "September 18, 2026 at 5:30 PM" (en)
     * e "18 de septiembre de 2026 a las 17:30" (es), no fuso informado.
     */
    public static function deadline(CarbonInterface $moment, SignerLocale $locale, string $timezone): string
    {
        $local = $moment->copy()->setTimezone($timezone);

        return match ($locale) {
            SignerLocale::PtBr => $local->format('d/m/Y \à\s H:i'),
            SignerLocale::En => $local->locale('en')->isoFormat('MMMM D, YYYY [at] h:mm A'),
            SignerLocale::Es => $local->locale('es')->isoFormat('D [de] MMMM [de] YYYY [a las] HH:mm'),
        };
    }

    private static function sessionKey(Recipient $recipient): string
    {
        // ULID: 26 caracteres alfanuméricos, sem ponto — seguro como segmento da chave.
        return self::SESSION_KEY.'.'.$recipient->ulid;
    }
}
