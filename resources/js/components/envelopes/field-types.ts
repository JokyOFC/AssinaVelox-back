import {
    CalendarDays,
    CheckSquare,
    PenLine,
    Signature,
    Stamp,
    Type,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { MinSize, PageSize } from '@/lib/geometry';
import type { FieldType } from '@/types/enums';

/**
 * Tipos de campo da paleta do passo 3 (DESIGN §6.5, já sem "CPF" e "Carimbo",
 * removidos do escopo pela ROUTES §2.6). Os rótulos vivem em `lib/labels.ts`
 * (`fieldTypeLabels`); aqui ficam apenas ícone, tamanho padrão e dica.
 */
export const FIELD_TYPES: readonly FieldType[] = [
    'signature',
    'initials',
    'name',
    'date',
    'text',
    'checkbox',
] as const;

/**
 * Fase 2 §2.8 (C-BRAND): carimbo visual da organização (logo + nome) —
 * representação visual, não prova. Só entra na paleta com `features.branding`;
 * sem a flag a paleta é exatamente `FIELD_TYPES`.
 */
export type PaletteFieldType = FieldType | 'stamp';

export const STAMP_FIELD_TYPE = 'stamp' as const;

export function paletteFieldTypes(features: {
    branding?: boolean;
}): readonly PaletteFieldType[] {
    return features.branding ? [...FIELD_TYPES, STAMP_FIELD_TYPE] : FIELD_TYPES;
}

export const FIELD_TYPE_ICONS: Record<PaletteFieldType, LucideIcon> = {
    signature: Signature,
    initials: PenLine,
    name: UserRound,
    date: CalendarDays,
    text: Type,
    checkbox: CheckSquare,
    stamp: Stamp,
};

/**
 * Tamanho padrão ao inserir um campo, em fração da página
 * (mesma convenção de `lib/geometry.ts`).
 */
export const DEFAULT_FIELD_SIZE: Record<
    PaletteFieldType,
    { w: number; h: number }
> = {
    signature: { w: 0.28, h: 0.06 },
    initials: { w: 0.1, h: 0.04 },
    name: { w: 0.28, h: 0.035 },
    date: { w: 0.16, h: 0.03 },
    text: { w: 0.24, h: 0.035 },
    checkbox: { w: 0.03, h: 0.022 },
    // Proporção 3:1 do desenho do carimbo (StampRenderer 900×300) em A4.
    stamp: { w: 0.3, h: 0.07 },
};

/**
 * Posição da rubrica automática (RECONCILIACAO Q10): quando "Rubrica em todas
 * as páginas" está ligada, o backend cria um campo `initials` real por página
 * neste ponto. O editor mostra a mesma posição para não surpreender.
 */
export const INITIALS_ON_ALL_PAGES_RECT = {
    x: 0.86,
    y: 0.94,
    w: 0.1,
    h: 0.04,
} as const;

/**
 * Tamanho **mínimo** por tipo, em pontos — espelho da tabela de
 * `docs/campos-e-geometria.md` §3, que é o que o servidor valida. Definido em
 * pontos (não em fração) porque o que importa é o campo ser legível no papel,
 * qualquer que seja o tamanho da página.
 */
export const FIELD_MIN_SIZE_PT: Record<
    PaletteFieldType,
    { w: number; h: number }
> = {
    signature: { w: 56, h: 20 },
    initials: { w: 22, h: 14 },
    name: { w: 40, h: 9 },
    date: { w: 40, h: 9 },
    text: { w: 18, h: 9 },
    checkbox: { w: 8, h: 8 },
    // Proposta para `FieldGeometry::MINIMUM_POINTS['stamp']` (fora da área C-BRAND).
    stamp: { w: 60, h: 20 },
};

/** Fallback quando o documento não informou as dimensões da página. */
const A4_POINTS: PageSize = { width: 595.28, height: 841.89 };

/**
 * Converte o mínimo do tipo para fração da página, usando as dimensões
 * **exibidas** em pontos (`document.page_sizes`). Sem elas, assume A4 retrato —
 * o mesmo fallback do servidor.
 */
export function minSizeFor(
    type: PaletteFieldType,
    pagePoints?: PageSize | null,
): MinSize {
    const page = pagePoints ?? A4_POINTS;
    const minimum = FIELD_MIN_SIZE_PT[type];

    return {
        w: page.width > 0 ? minimum.w / page.width : 0.02,
        h: page.height > 0 ? minimum.h / page.height : 0.012,
    };
}

/** Texto exibido dentro da caixa vazia, por tipo. */
export const FIELD_TYPE_PLACEHOLDER: Record<PaletteFieldType, string | null> = {
    signature: null,
    initials: null,
    name: 'Nome completo',
    date: 'DD/MM/AAAA',
    text: 'Texto',
    checkbox: null,
    stamp: null,
};

/** Descrição curta usada na paleta e no painel de propriedades. */
export const FIELD_TYPE_HINTS: Record<PaletteFieldType, string> = {
    stamp: 'Carimbo visual da organização (logo e nome) — representação visual, não prova.',
    signature: 'Representação visual da assinatura do signatário.',
    initials: 'Rubrica curta, geralmente no rodapé de cada página.',
    name: 'Nome do signatário, preenchido no aceite.',
    date: 'Data do aceite, carimbada pelo servidor no fuso da organização.',
    text: 'Texto livre digitado pelo signatário.',
    checkbox: 'Marcação de concordância com um item específico.',
};

/** Campos cujo conteúdo o signatário não digita (somente leitura para ele). */
export const SERVER_FILLED_TYPES: readonly FieldType[] = ['date'] as const;

/** Formatos de data oferecidos no painel de propriedades. */
export const DATE_FORMATS: readonly { value: string; label: string }[] = [
    { value: 'd/m/Y', label: '31/12/2026' },
    { value: 'd/m/Y H:i', label: '31/12/2026 14:32' },
    { value: 'd \\d\\e F \\d\\e Y', label: '31 de dezembro de 2026' },
] as const;

export const FONT_SIZES: readonly number[] = [
    8, 9, 10, 11, 12, 14, 16,
] as const;

export const DEFAULT_FONT_SIZE = 10;
