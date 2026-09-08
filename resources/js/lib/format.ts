/**
 * Formatação pt-BR: datas (fuso da organização/usuário), moeda BRL a partir
 * de centavos, bytes, iniciais, máscaras de e-mail, CPF e CNPJ.
 */

const LOCALE = 'pt-BR';
export const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

let currentTimeZone = DEFAULT_TIMEZONE;

/** Define o fuso usado pelas funções de data (chamado pelo layout). */
export function setTimeZone(timeZone: string | null | undefined): void {
    currentTimeZone = timeZone || DEFAULT_TIMEZONE;
}

export function getTimeZone(): string {
    return currentTimeZone;
}

function toDate(value: string | number | Date): Date {
    return value instanceof Date ? value : new Date(value);
}

function isValid(date: Date): boolean {
    return !Number.isNaN(date.getTime());
}

const partsCache = new Map<string, Intl.DateTimeFormat>();

function formatter(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const key = `${currentTimeZone}|${JSON.stringify(options)}`;
    let fmt = partsCache.get(key);

    if (!fmt) {
        fmt = new Intl.DateTimeFormat(LOCALE, {
            timeZone: currentTimeZone,
            ...options,
        });
        partsCache.set(key, fmt);
    }

    return fmt;
}

/** "03/09/2026" */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    return isValid(date)
        ? formatter({ day: '2-digit', month: '2-digit', year: 'numeric' }).format(
              date,
          )
        : '—';
}

/** "03 set 2026" */
export function formatDateMedium(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    if (!isValid(date)) {
        return '—';
    }

    return formatter({ day: '2-digit', month: 'short', year: 'numeric' })
        .format(date)
        .replace(/\./g, '')
        .replace(/ de /g, ' ');
}

/** "3 set" — para eixos e datas curtas. */
export function formatDayMonth(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    if (!isValid(date)) {
        return '—';
    }

    return formatter({ day: 'numeric', month: 'short' })
        .format(date)
        .replace(/\./g, '')
        .replace(/ de /g, ' ');
}

/** "09:12" */
export function formatTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    return isValid(date)
        ? formatter({ hour: '2-digit', minute: '2-digit' }).format(date)
        : '—';
}

/** "03/09/2026 14:32" */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    if (!isValid(date)) {
        return '—';
    }

    return `${formatDate(value)} ${formatTime(value)}`;
}

function dayKey(date: Date): string {
    return formatter({ year: 'numeric', month: '2-digit', day: '2-digit' }).format(
        date,
    );
}

/**
 * "Hoje, 09:12" · "Ontem, 17:25" · "28 ago, 16:48" · "12 jul 2025, 09:15"
 * (padrão das colunas "Atualizado" e "Último acesso" dos mocks).
 */
export function formatRelativeDateTime(
    value: string | null | undefined,
    now: Date = new Date(),
): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    if (!isValid(date)) {
        return '—';
    }

    const time = formatTime(value);
    const today = dayKey(now);
    const target = dayKey(date);

    if (target === today) {
        return `Hoje, ${time}`;
    }

    const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);

    if (target === dayKey(yesterday)) {
        return `Ontem, ${time}`;
    }

    const sameYear =
        formatter({ year: 'numeric' }).format(date) ===
        formatter({ year: 'numeric' }).format(now);

    return sameYear
        ? `${formatDayMonth(value)}, ${time}`
        : `${formatDateMedium(value)}, ${time}`;
}

/** "há 2 dias" · "há 3 horas" · "agora" */
export function formatTimeAgo(
    value: string | null | undefined,
    now: Date = new Date(),
): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    if (!isValid(date)) {
        return '—';
    }

    const diffSeconds = Math.round((now.getTime() - date.getTime()) / 1000);
    const abs = Math.abs(diffSeconds);
    const rtf = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' });

    if (abs < 60) {
        return 'agora';
    }

    if (abs < 3600) {
        return rtf.format(-Math.round(diffSeconds / 60), 'minute');
    }

    if (abs < 86400) {
        return rtf.format(-Math.round(diffSeconds / 3600), 'hour');
    }

    if (abs < 86400 * 30) {
        return rtf.format(-Math.round(diffSeconds / 86400), 'day');
    }

    return rtf.format(-Math.round(diffSeconds / (86400 * 30)), 'month');
}

/** "Quarta-feira, 3 de setembro de 2026" */
export function formatLongDate(value: string | Date = new Date()): string {
    const date = toDate(value);

    if (!isValid(date)) {
        return '—';
    }

    const text = formatter({
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);

    return text.charAt(0).toUpperCase() + text.slice(1);
}

// ---------------------------------------------------------------------------
// Números e moeda
// ---------------------------------------------------------------------------

/** 1.284 */
export function formatNumber(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return new Intl.NumberFormat(LOCALE).format(value);
}

/** "R$ 49,00" a partir de centavos. */
export function formatCurrency(
    cents: number | null | undefined,
    currency = 'BRL',
): string {
    if (cents === null || cents === undefined) {
        return '—';
    }

    return new Intl.NumberFormat(LOCALE, {
        style: 'currency',
        currency,
    }).format(cents / 100);
}

/** "R$ 49" quando inteiro, "R$ 59,80" quando há centavos. */
export function formatCurrencyCompact(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) {
        return '—';
    }

    if (cents % 100 === 0) {
        return new Intl.NumberFormat(LOCALE, {
            style: 'currency',
            currency: 'BRL',
            maximumFractionDigits: 0,
        }).format(cents / 100);
    }

    return formatCurrency(cents);
}

/** "R$ 96,4 mil" para KPIs. */
export function formatCurrencyShort(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) {
        return '—';
    }

    const value = cents / 100;

    if (value >= 1_000_000) {
        return `R$ ${formatDecimal(value / 1_000_000, 1)} mi`;
    }

    if (value >= 1_000) {
        return `R$ ${formatDecimal(value / 1_000, 1)} mil`;
    }

    return formatCurrency(cents);
}

export function formatDecimal(value: number, digits = 1): string {
    return new Intl.NumberFormat(LOCALE, {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
    }).format(value);
}

/** "2,4 GB" · "412 KB" */
export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${formatDecimal(value, value >= 100 ? 0 : 1)} ${units[unit]}`;
}

/** "12%" · "−8%" */
export function formatPercent(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const sign = value < 0 ? '−' : '';

    return `${sign}${formatDecimal(Math.abs(value), 1)}%`;
}

/** Percentual de uso limitado a [0, 100]; null quando não há limite. */
export function usagePercent(
    used: number,
    limit: number | null | undefined,
): number | null {
    if (!limit || limit <= 0) {
        return null;
    }

    return Math.min(100, Math.round((used / limit) * 100));
}

/** "42 min" · "1 h 12 min" · "2 d 3 h" */
export function formatDuration(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) {
        return '—';
    }

    if (minutes < 60) {
        return `${Math.round(minutes)} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = Math.round(minutes % 60);

    if (hours < 24) {
        return rest > 0 ? `${hours} h ${rest} min` : `${hours} h`;
    }

    const days = Math.floor(hours / 24);
    const restHours = hours % 24;

    return restHours > 0 ? `${days} d ${restHours} h` : `${days} d`;
}

// ---------------------------------------------------------------------------
// Texto e máscaras
// ---------------------------------------------------------------------------

/** "Ana Ribeiro" → "AR" */
export function initials(fullName: string | null | undefined): string {
    if (!fullName) {
        return '';
    }

    const names = fullName.trim().split(/\s+/u).filter(Boolean);

    if (names.length === 0) {
        return '';
    }

    const first = Array.from(names[0])[0] ?? '';

    if (names.length === 1) {
        return first.toUpperCase();
    }

    const last = Array.from(names[names.length - 1])[0] ?? '';

    return `${first}${last}`.toUpperCase();
}

/** "maria@dominio.com" → "m•••@dominio.com" */
export function maskEmail(email: string | null | undefined): string {
    if (!email || !email.includes('@')) {
        return email ?? '';
    }

    const [local, domain] = email.split('@');
    const head = Array.from(local)[0] ?? '';

    return `${head}•••@${domain}`;
}

export function onlyDigits(value: string): string {
    return value.replace(/\D+/g, '');
}

/** "12345678000190" → "12.345.678/0001-90" */
export function formatCnpj(value: string): string {
    const d = onlyDigits(value).slice(0, 14);

    return d
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}

/** "12345678901" → "123.456.789-01" */
export function formatCpf(value: string): string {
    const d = onlyDigits(value).slice(0, 11);

    return d
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}

/** Aplica máscara de CPF até 11 dígitos e de CNPJ acima disso. */
export function formatCpfCnpj(value: string): string {
    const d = onlyDigits(value);

    return d.length <= 11 ? formatCpf(d) : formatCnpj(d);
}

export function isValidCpf(value: string): boolean {
    const d = onlyDigits(value);

    if (d.length !== 11 || /^(\d)\1+$/.test(d)) {
        return false;
    }

    const calc = (len: number): number => {
        let sum = 0;

        for (let i = 0; i < len; i += 1) {
            sum += Number(d[i]) * (len + 1 - i);
        }

        const rest = (sum * 10) % 11;

        return rest === 10 ? 0 : rest;
    };

    return calc(9) === Number(d[9]) && calc(10) === Number(d[10]);
}

export function isValidCnpj(value: string): boolean {
    const d = onlyDigits(value);

    if (d.length !== 14 || /^(\d)\1+$/.test(d)) {
        return false;
    }

    const calc = (len: number): number => {
        const weights =
            len === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        let sum = 0;

        for (let i = 0; i < len; i += 1) {
            sum += Number(d[i]) * weights[i];
        }

        const rest = sum % 11;

        return rest < 2 ? 0 : 11 - rest;
    };

    return calc(12) === Number(d[12]) && calc(13) === Number(d[13]);
}

/** "AV-00148" a partir do número sequencial. */
export function displayCode(number: number): string {
    return `AV-${String(number).padStart(5, '0')}`;
}

/** "ABCDEFGHJKLM" → "ABCD-EFGH-JKLM" */
export function formatVerificationCode(code: string | null | undefined): string {
    if (!code) {
        return '—';
    }

    const clean = code.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    return clean.match(/.{1,4}/g)?.join('-') ?? clean;
}

/** "1 de 2" */
export function formatProgress(done: number, total: number): string {
    return `${done} de ${total}`;
}

/** Pluralização simples: plural(3, 'documento') → "3 documentos". */
export function plural(
    count: number,
    singular: string,
    pluralForm = `${singular}s`,
): string {
    return `${formatNumber(count)} ${count === 1 ? singular : pluralForm}`;
}
