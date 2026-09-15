import { usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import { requestJson } from '@/components/identity/http';
import InputError from '@/components/input-error';
import { Spinner } from '@/components/ui/spinner';
import {
    locale as recipientLocaleRoute,
    locales as recipientLocalesRoute,
} from '@/routes/envelopes/recipients';

/** Contrato de `GET envelopes.recipients.locales` (RecipientLocaleController::index). */
interface LocaleIndex {
    reference_locale: string;
    locales: { code: string; label: string }[];
    editable: boolean;
    recipients: Record<string, { locale: string; timezone: string | null }>;
}

/** Uma consulta por envelope, compartilhada pelos controles de todos os participantes. */
const indexCache = new Map<string, Promise<LocaleIndex | null>>();

function loadIndex(envelopeId: string): Promise<LocaleIndex | null> {
    let pending = indexCache.get(envelopeId);

    if (!pending) {
        pending = getJson<LocaleIndex>(
            recipientLocalesRoute(envelopeId).url,
        ).then((response) => (response.ok ? response.body : null));
        indexCache.set(envelopeId, pending);
        // Falha não fica guardada: a próxima montagem tenta de novo.
        void pending.then((body) => {
            if (body === null) {
                indexCache.delete(envelopeId);
            }
        });
    }

    return pending;
}

/** Fusos do navegador (lista IANA); vazio em navegadores antigos — fica só "o da organização". */
function timeZones(): string[] {
    const intl = Intl as unknown as {
        supportedValuesOf?: (key: string) => string[];
    };

    try {
        return intl.supportedValuesOf?.('timeZone') ?? [];
    } catch {
        return [];
    }
}

interface ZoneOption {
    value: string;
    label: string;
}

/** Fusos mais usados por quem envia daqui; aparecem primeiro, antes da lista completa. */
const COMMON_ZONES = [
    'America/Sao_Paulo',
    'America/Manaus',
    'America/Cuiaba',
    'America/Rio_Branco',
    'America/Noronha',
    'America/Argentina/Buenos_Aires',
    'America/New_York',
    'Europe/Lisbon',
    'Europe/Madrid',
];

function intlPart(
    zone: string,
    locale: string,
    style: 'longGeneric' | 'shortOffset',
    now: Date,
): string {
    try {
        return (
            new Intl.DateTimeFormat(locale, {
                timeZone: zone,
                timeZoneName: style,
            })
                .formatToParts(now)
                .find((part) => part.type === 'timeZoneName')?.value ?? ''
        );
    } catch {
        return '';
    }
}

/**
 * Nome legível em PT-BR com o deslocamento, nunca o identificador cru:
 * "America/Sao_Paulo" → "Horário de Brasília (UTC−3) — Sao Paulo".
 */
function zoneLabel(zone: string, now: Date): string {
    const city = zone.startsWith('Etc/')
        ? ''
        : (zone.split('/').pop() ?? zone).replace(/_/g, ' ');
    const name = intlPart(zone, 'pt-BR', 'longGeneric', now);
    const offset = intlPart(zone, 'en-US', 'shortOffset', now);
    const utc =
        offset === ''
            ? ''
            : offset === 'GMT'
              ? 'UTC'
              : offset.replace('GMT', 'UTC').replace('-', '−');
    const main =
        name !== '' && !/^(GMT|UTC)/.test(name) ? name : utc || city || zone;
    const parts = [main];

    if (utc !== '' && main !== utc) {
        parts.push(`(${utc})`);
    }

    if (city !== '' && !main.includes(city)) {
        parts.push(`— ${city}`);
    }

    return parts.join(' ');
}

function labeledZones(zones: string[]): ZoneOption[] {
    const now = new Date();

    return zones
        .map((zone) => ({ value: zone, label: zoneLabel(zone, now) }))
        .sort((a, b) => a.label.localeCompare(b.label, 'pt-BR'));
}

/**
 * Idioma e fuso de um participante (Fase 3 §3.3 — F-I18N, docs/fase-3/multilingue.md §3), no
 * passo de participantes do wizard. `PUT envelopes.recipients.locale`, por `fetch` (JSON): uma
 * visita do Inertia cancelaria a gravação automática dos participantes.
 *
 * Vale para os e-mails e para a página pública desse participante (que ainda pode trocar o
 * idioma de exibição na própria página). Com a flag `multilingual` desligada não renderiza nada.
 */
export function RecipientLocaleControl({
    envelopeId,
    recipientId,
    disabled,
}: {
    envelopeId: string;
    /** ULID; `null` enquanto o participante ainda não foi salvo. */
    recipientId: string | null;
    disabled?: boolean;
}) {
    const enabled = Boolean(
        (usePage().props as { features?: { multilingual?: boolean } }).features
            ?.multilingual,
    );
    const [index, setIndex] = useState<LocaleIndex | null>(null);
    const [locale, setLocale] = useState('pt_BR');
    const [timezone, setTimezone] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const zones = useMemo(() => labeledZones(timeZones()), []);
    const commonZones = useMemo(
        () => zones.filter((option) => COMMON_ZONES.includes(option.value)),
        [zones],
    );

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let alive = true;

        void loadIndex(envelopeId).then((body) => {
            if (!alive || body === null) {
                return;
            }

            setIndex(body);

            const current = recipientId ? body.recipients[recipientId] : null;
            setLocale(current?.locale ?? body.reference_locale);
            setTimezone(current?.timezone ?? null);
        });

        return () => {
            alive = false;
        };
    }, [enabled, envelopeId, recipientId]);

    if (!enabled) {
        return null;
    }

    const unsaved = recipientId === null;
    const locked =
        disabled || unsaved || saving || index === null || !index.editable;

    const save = async (nextLocale: string, nextTimezone: string | null) => {
        if (recipientId === null) {
            return;
        }

        const before = { locale, timezone };
        setLocale(nextLocale);
        setTimezone(nextTimezone);
        setSaving(true);
        setError(null);

        const response = await requestJson<{
            locale?: string;
            timezone?: string | null;
            message?: string;
            errors?: Record<string, string[]>;
        }>(
            'PUT',
            recipientLocaleRoute({
                envelope: envelopeId,
                recipient: recipientId,
            }).url,
            { locale: nextLocale, timezone: nextTimezone },
        );

        setSaving(false);

        if (response.ok && response.body) {
            setLocale(response.body.locale ?? nextLocale);
            setTimezone(response.body.timezone ?? null);
            indexCache.delete(envelopeId);

            return;
        }

        setLocale(before.locale);
        setTimezone(before.timezone);
        setError(
            response.network
                ? 'Não foi possível salvar agora. Verifique a conexão e tente de novo.'
                : (Object.values(response.body?.errors ?? {})[0]?.[0] ??
                      response.body?.message ??
                      'Não foi possível salvar o idioma do participante.'),
        );
    };

    return (
        <div className="border-muted flex flex-col gap-2 border-t pt-3">
            <div className="flex flex-wrap items-center gap-2.5 text-[13px]">
                <span className="flex items-center gap-1.5 font-semibold">
                    <Languages className="text-primary size-3.5" />
                    Idioma dos e-mails e da página
                    {saving && <Spinner className="size-3" />}
                </span>
                <select
                    aria-label="Idioma do participante"
                    className="border-input bg-background h-8 rounded-md border px-2 text-[12.5px]"
                    value={locale}
                    disabled={locked}
                    onChange={(event) =>
                        void save(event.target.value, timezone)
                    }
                >
                    {(
                        index?.locales ?? [
                            { code: 'pt_BR', label: 'Português (Brasil)' },
                        ]
                    ).map((option) => (
                        <option key={option.code} value={option.code}>
                            {option.label}
                        </option>
                    ))}
                </select>
                {zones.length > 0 && (
                    <select
                        aria-label="Fuso horário do participante"
                        className="border-input bg-background h-8 max-w-[220px] rounded-md border px-2 text-[12.5px]"
                        value={timezone ?? ''}
                        disabled={locked}
                        onChange={(event) =>
                            void save(locale, event.target.value || null)
                        }
                    >
                        <option value="">Fuso da organização</option>
                        {commonZones.length > 0 && (
                            <optgroup label="Mais usados">
                                {commonZones.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        <optgroup label="Todos os fusos">
                            {zones
                                .filter(
                                    (option) =>
                                        !COMMON_ZONES.includes(option.value),
                                )
                                .map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                        </optgroup>
                    </select>
                )}
            </div>
            <p className="text-muted-foreground text-[12px] leading-[1.45]">
                {unsaved
                    ? 'Salve o participante para escolher.'
                    : index !== null && !index.editable
                      ? 'O idioma só pode ser alterado antes do envio.'
                      : 'Convite, lembretes e página de assinatura saem neste idioma. Em inglês e espanhol, os textos jurídicos são tradução de cortesia; vale o texto em português.'}
            </p>

            <InputError message={error ?? undefined} />
        </div>
    );
}
