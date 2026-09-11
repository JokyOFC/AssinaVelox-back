/**
 * Contraste WCAG 2.x — espelho de App\Services\Branding\ColorContrast.
 *
 * Só serve para a tela AVISAR antes de salvar: quem recusa uma combinação
 * ilegível é o servidor.
 */

export const WHITE = '#FFFFFF';

/** `#abc`, `abc`, `#aabbcc` ou `aabbcc` → `#AABBCC`; qualquer outra coisa → null. */
export function normalizeHex(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    let hex = value.trim().replace(/^#/, '');

    if (/^[0-9a-f]{3}$/i.test(hex)) {
        hex = hex
            .split('')
            .map((char) => char + char)
            .join('');
    }

    return /^[0-9a-f]{6}$/i.test(hex) ? `#${hex.toUpperCase()}` : null;
}

function channel(value: number): number {
    const c = value / 255;

    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

export function luminance(hex: string): number {
    const normalized = normalizeHex(hex) ?? '#000000';
    const r = parseInt(normalized.slice(1, 3), 16);
    const g = parseInt(normalized.slice(3, 5), 16);
    const b = parseInt(normalized.slice(5, 7), 16);

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

export function contrastRatio(a: string, b: string): number {
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** "4,5:1" — arredondado para baixo, como no servidor. */
export function formatRatio(ratio: number): string {
    return `${(Math.floor(ratio * 10) / 10).toFixed(1).replace('.', ',')}:1`;
}
