/**
 * Paleta de cores por signatário no editor de campos (DESIGN §4.19 e §6.5).
 *
 * O mock usa azul (`#1257c9`) para o 1º signatário e âmbar (`#d59b2a`) para o
 * 2º; as posições 3 e 4 seguem a paleta cíclica de avatares (§4.17) em verde e
 * cinza. As cores entram como estilo inline porque dependem do índice do
 * destinatário em tempo de execução — não há classe Tailwind por índice.
 */

export interface RecipientColor {
    /** Índice na paleta (0–3), o mesmo do avatar do signatário. */
    index: number;
    /** Nome em PT-BR — usado em `aria-label` e legendas textuais. */
    name: string;
    /** Cor sólida: borda do campo, tag flutuante e dot da lista. */
    solid: string;
    /** Preenchimento translúcido do campo sobre a página. */
    fill: string;
    /** Cor do texto dentro do campo (contraste sobre `fill`). */
    text: string;
    /** Fundo suave para pills e chips fora da página. */
    soft: string;
}

const PALETTE: readonly RecipientColor[] = [
    {
        index: 0,
        name: 'azul',
        solid: '#1257c9',
        fill: 'rgba(18, 87, 201, 0.08)',
        text: '#1257c9',
        soft: '#e8f0fd',
    },
    {
        index: 1,
        name: 'âmbar',
        solid: '#d59b2a',
        fill: 'rgba(213, 155, 42, 0.10)',
        text: '#9a5b00',
        soft: '#fff4e0',
    },
    {
        index: 2,
        name: 'verde',
        solid: '#1fb865',
        fill: 'rgba(31, 184, 101, 0.10)',
        text: '#12784a',
        soft: '#e6f7ee',
    },
    {
        index: 3,
        name: 'cinza',
        solid: '#6b7891',
        fill: 'rgba(107, 120, 145, 0.10)',
        text: '#47536b',
        soft: '#f1f4f9',
    },
] as const;

export const RECIPIENT_COLOR_COUNT = PALETTE.length;

/** Cor do signatário na posição `index` (cíclica). */
export function recipientColor(index: number): RecipientColor {
    return PALETTE[Math.abs(Math.trunc(index)) % PALETTE.length];
}
