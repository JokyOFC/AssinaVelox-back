import {
    formatBytes as ptFormatBytes,
    formatDate as ptFormatDate,
    formatDateMedium as ptFormatDateMedium,
    formatDateTime as ptFormatDateTime,
    formatNumber as ptFormatNumber,
    formatTime as ptFormatTime,
} from '@/lib/format';
import { BCP47, type Locale } from '@/i18n/locales';

/**
 * Datas e números no idioma e no fuso do participante (F-I18N).
 *
 * Sem idioma nem fuso próprios (flag `multilingual` desligada, ou PT-BR sem fuso
 * do participante), delega para `lib/format` — a saída é exatamente a de
 * sempre. Em `en`/`es` usa `Intl` com o estilo médio ("Sep 18, 2026",
 * "18 sept 2026"), que não é ambíguo entre dia e mês.
 */
export interface Formatters {
    date: (value: string | null | undefined) => string;
    dateMedium: (value: string | null | undefined) => string;
    dateTime: (value: string | null | undefined) => string;
    time: (value: string | null | undefined) => string;
    number: (value: number | null | undefined) => string;
    bytes: (value: number | null | undefined) => string;
    /** "3,5 s" / "3.5 s" — uma casa decimal. */
    seconds: (milliseconds: number | null | undefined) => string;
}

const cache = new Map<string, Intl.DateTimeFormat>();

function dateFormatter(
    locale: string,
    timeZone: string | undefined,
    options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat {
    const key = `${locale}|${timeZone ?? ''}|${JSON.stringify(options)}`;
    let formatter = cache.get(key);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat(locale, {
            ...(timeZone ? { timeZone } : {}),
            ...options,
        });
        cache.set(key, formatter);
    }

    return formatter;
}

function parse(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function decimal(tag: string, value: number, digits: number): string {
    return new Intl.NumberFormat(tag, {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
    }).format(value);
}

export function createFormatters(
    locale: Locale,
    timeZone: string | null | undefined,
): Formatters {
    const tag = BCP47[locale];
    const zone = timeZone || undefined;

    const seconds = (milliseconds: number | null | undefined): string =>
        milliseconds === null || milliseconds === undefined
            ? ''
            : `${(milliseconds / 1000).toLocaleString(tag, {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
              })} s`;

    const number = (value: number | null | undefined): string =>
        value === null || value === undefined
            ? '—'
            : new Intl.NumberFormat(tag).format(value);

    const bytes = (value: number | null | undefined): string => {
        if (value === null || value === undefined) {
            return '—';
        }

        if (value < 1024) {
            return `${value} B`;
        }

        const units = ['KB', 'MB', 'GB', 'TB'];
        let size = value / 1024;
        let unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit += 1;
        }

        return `${decimal(tag, size, size >= 100 ? 0 : 1)} ${units[unit]}`;
    };

    // Sem fuso próprio, o PT-BR é exatamente o de `lib/format`.
    if (locale === 'pt_BR' && !zone) {
        return {
            date: ptFormatDate,
            dateMedium: ptFormatDateMedium,
            dateTime: ptFormatDateTime,
            time: ptFormatTime,
            number: ptFormatNumber,
            bytes: ptFormatBytes,
            seconds,
        };
    }

    const time = (value: string | null | undefined): string => {
        const date = parse(value);

        return date
            ? dateFormatter(tag, zone, {
                  hour: '2-digit',
                  minute: '2-digit',
              }).format(date)
            : '—';
    };

    if (locale === 'pt_BR') {
        // Mesmo desenho de `lib/format`, no fuso do participante.
        const date = (value: string | null | undefined): string => {
            const parsed = parse(value);

            return parsed
                ? dateFormatter(tag, zone, {
                      day: '2-digit',
                      month: '2-digit',
                      year: 'numeric',
                  }).format(parsed)
                : '—';
        };

        return {
            date,
            dateMedium: (value) => {
                const parsed = parse(value);

                return parsed
                    ? dateFormatter(tag, zone, {
                          day: '2-digit',
                          month: 'short',
                          year: 'numeric',
                      })
                          .format(parsed)
                          .replace(/\./g, '')
                          .replace(/ de /g, ' ')
                    : '—';
            },
            dateTime: (value) =>
                parse(value) ? `${date(value)} ${time(value)}` : '—',
            time,
            number,
            bytes,
            seconds,
        };
    }

    const medium = (value: string | null | undefined): string => {
        const parsed = parse(value);

        return parsed
            ? dateFormatter(tag, zone, { dateStyle: 'medium' }).format(parsed)
            : '—';
    };

    return {
        date: medium,
        dateMedium: medium,
        dateTime: (value) => {
            const parsed = parse(value);

            return parsed
                ? dateFormatter(tag, zone, {
                      dateStyle: 'medium',
                      timeStyle: 'short',
                  }).format(parsed)
                : '—';
        },
        time,
        number,
        bytes,
        seconds,
    };
}
