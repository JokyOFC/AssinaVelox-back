/**
 * Idiomas da página pública do participante (Fase 3 §3.3 — F-I18N,
 * docs/fase-3/multilingue.md). Mesma lista fechada de
 * `App\Support\Locale\SignerLocale`: qualquer valor fora dela cai no idioma de
 * referência.
 */

export const LOCALES = ['pt_BR', 'en', 'es'] as const;

export type Locale = (typeof LOCALES)[number];

/** Idioma de referência: o texto jurídico gravado como evidência é o dele. */
export const REFERENCE_LOCALE: Locale = 'pt_BR';

/** Etiqueta BCP 47 para `Intl` e para o atributo `lang`. */
export const BCP47: Record<Locale, string> = {
    pt_BR: 'pt-BR',
    en: 'en',
    es: 'es',
};

/** Nome de cada idioma no próprio idioma (endônimo). */
export const LOCALE_LABELS: Record<Locale, string> = {
    pt_BR: 'Português (Brasil)',
    en: 'English',
    es: 'Español',
};

export function isLocale(value: unknown): value is Locale {
    return (
        typeof value === 'string' &&
        (LOCALES as readonly string[]).includes(value)
    );
}
