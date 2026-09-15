import { usePage } from '@inertiajs/react';
import {
    createContext,
    Fragment,
    type ReactNode,
    useContext,
    useMemo,
} from 'react';
import { createFormatters, type Formatters } from '@/i18n/format';
import { BCP47, isLocale, type Locale, REFERENCE_LOCALE } from '@/i18n/locales';
import { en } from '@/i18n/messages/en';
import { es } from '@/i18n/messages/es';
import { type MessageKey, type Messages, ptBR } from '@/i18n/messages/pt_BR';

/**
 * Camada mínima de tradução da página pública do participante (Fase 3 §3.3 —
 * F-I18N, docs/fase-3/multilingue.md §5). Sem pacote novo: dicionários em
 * `messages/{pt_BR,en,es}`, `t()` com `{nome}` e plural por contagem.
 *
 * O idioma vem, nesta ordem, do `I18nProvider` mais próximo e da prop
 * compartilhada `i18n` — que o servidor só envia com a flag `multilingual`
 * ligada. Sem ela, tudo é PT-BR e cada texto é exatamente o de antes (o
 * dicionário PT-BR guarda os textos originais, palavra por palavra).
 *
 * Texto do remetente ou do participante nunca passa por aqui como modelo: ele
 * entra só como VALOR de um `{nome}` e é devolvido como texto (T6).
 */

export type { Locale, MessageKey };
export { LOCALE_LABELS, LOCALES, REFERENCE_LOCALE } from '@/i18n/locales';

export const DICTIONARIES: Record<Locale, Messages> = {
    pt_BR: ptBR,
    en,
    es,
};

type Params = Record<string, string | number>;

/** Prop compartilhada `i18n` (App\Http\Middleware\ApplySignerLocale::props). */
export interface SignerI18nProps {
    locale: Locale;
    bcp47: string;
    reference_locale: Locale;
    locales: { code: Locale; label: string }[];
    /** POST `sign.locale.update` — só na página `sign/show`. */
    switch_url?: string | null;
    time_zone: string | null;
    /** A tradução jurídica deste idioma já foi revisada por profissional? */
    legal_reviewed: boolean;
}

export interface I18n extends Formatters {
    locale: Locale;
    bcp47: string;
    /** Idioma de referência (o texto jurídico gravado é o dele). */
    isReference: boolean;
    t: (key: MessageKey, params?: Params) => string;
    /** Plural por contagem: `{key}.one` quando 1, senão `{key}.other`. */
    tp: (key: PluralBase, count: number, params?: Params) => string;
    /** Como `t`, mas os valores podem ser elementos (negrito, links). */
    rich: (key: MessageKey, nodes: Record<string, ReactNode>) => ReactNode;
}

/** Chaves que têm as formas `.one` e `.other`. */
export type PluralBase = MessageKey extends infer K
    ? K extends `${infer Base}.one`
        ? Base
        : never
    : never;

function interpolate(template: string, params?: Params): string {
    if (!params) {
        return template;
    }

    return template.replace(/\{([a-z_]+)\}/g, (match, name: string) =>
        Object.prototype.hasOwnProperty.call(params, name)
            ? String(params[name])
            : match,
    );
}

/** Texto de uma chave num idioma (sem hook — para módulos fora de componente). */
export function translate(
    locale: Locale,
    key: MessageKey,
    params?: Params,
): string {
    const dictionary = DICTIONARIES[locale] ?? ptBR;

    return interpolate(dictionary[key] ?? ptBR[key] ?? key, params);
}

export function createI18n(
    locale: Locale,
    timeZone: string | null | undefined = null,
): I18n {
    const formatters = createFormatters(locale, timeZone);

    const t = (key: MessageKey, params?: Params): string =>
        translate(locale, key, params);

    const tp = (key: PluralBase, count: number, params?: Params): string =>
        t(`${key}.${count === 1 ? 'one' : 'other'}` as MessageKey, {
            count: formatters.number(count),
            ...params,
        });

    const rich = (
        key: MessageKey,
        nodes: Record<string, ReactNode>,
    ): ReactNode => {
        const parts = t(key).split(/(\{[a-z_]+\})/g);

        return parts.map((part, index) => {
            const name = /^\{([a-z_]+)\}$/.exec(part)?.[1];

            return (
                <Fragment key={index}>
                    {name !== undefined &&
                    Object.prototype.hasOwnProperty.call(nodes, name)
                        ? nodes[name]
                        : part}
                </Fragment>
            );
        });
    };

    return {
        ...formatters,
        locale,
        bcp47: BCP47[locale],
        isReference: locale === REFERENCE_LOCALE,
        t,
        tp,
        rich,
    };
}

const I18nContext = createContext<{
    locale: Locale;
    timeZone: string | null;
} | null>(null);

/**
 * Força um idioma para um trecho da árvore (ex.: a vez de um participante no
 * dispositivo presencial). Sem ele, vale a prop compartilhada `i18n`.
 */
export function I18nProvider({
    locale,
    timeZone = null,
    children,
}: {
    locale: Locale;
    timeZone?: string | null;
    children: ReactNode;
}) {
    const value = useMemo(() => ({ locale, timeZone }), [locale, timeZone]);

    return (
        <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
    );
}

/** A prop `i18n` da página, quando o servidor a enviou (flag ligada). */
export function useSignerI18nProps(): SignerI18nProps | null {
    const shared = (usePage().props as { i18n?: SignerI18nProps | null }).i18n;

    return shared && isLocale(shared.locale) ? shared : null;
}

export function useI18n(): I18n {
    const forced = useContext(I18nContext);
    const shared = useSignerI18nProps();

    const locale: Locale = forced?.locale ?? shared?.locale ?? REFERENCE_LOCALE;
    const timeZone = forced ? forced.timeZone : (shared?.time_zone ?? null);

    return useMemo(() => createI18n(locale, timeZone), [locale, timeZone]);
}

/**
 * Rótulo curto com a cópia PT-BR (a referência) escrita no próprio JSX:
 * `<Trans k="signature.pad.undo">Desfazer</Trans>`. Em PT-BR mostra `children`
 * — idêntico ao dicionário de referência —; nos demais idiomas, a tradução da
 * chave. Mantém a cópia de referência legível no código e verificável pelos
 * testes de cópia (integração I-3F: tests/Feature/Review/SignerCopyAndLabelsTest).
 */
export function Trans({ k, children }: { k: MessageKey; children: string }) {
    const { isReference, t } = useI18n();

    return <>{isReference ? children : t(k)}</>;
}
