import { IdCard } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    DEFAULT_FIELD_SIZE,
    FIELD_TYPE_HINTS,
    FIELD_TYPE_ICONS,
    FIELD_TYPE_PLACEHOLDER,
    minSizeFor,
    paletteFieldTypes,
    SERVER_FILLED_TYPES,
    type PaletteFieldType,
} from '@/components/envelopes/field-types';
import type { MinSize, PageSize } from '@/lib/geometry';
import type { SigningFieldType } from '@/types/enums';

/**
 * Tipos de campo da Fase 2, onda B que `field-types.ts` (área C-BRAND) ainda não cobre.
 *
 * `field-types.ts` já tem o carimbo (`stamp`); o CPF (`cpf`, Fase 2 §2.11, C-ID) entra aqui,
 * delegando todo o resto para as tabelas originais. O editor e a página pública consultam
 * estas funções em vez de indexar as tabelas diretamente — assim um campo `cpf` vindo do
 * servidor nunca cai num `undefined`.
 */
export type EditorFieldType = PaletteFieldType | 'cpf';

export const CPF_FIELD_TYPE = 'cpf' as const;

/** Placeholder sugerido pelo backend (docs/fase-2/identidade.md §7). */
export const CPF_PLACEHOLDER = '000.000.000-00';

/**
 * Mínimo proposto para `FieldGeometry::MINIMUM_POINTS['cpf']` (relatório C-ID): o mesmo de
 * `name`/`date`, que também são uma linha de texto.
 */
const CPF_MIN_POINTS = { w: 40, h: 9 };

const A4_POINTS: PageSize = { width: 595.28, height: 841.89 };

const CPF_ENTRY = {
    icon: IdCard,
    // Uma linha de texto com 14 caracteres ("000.000.000-00").
    size: { w: 0.2, h: 0.035 },
    hint: 'CPF digitado pelo participante. O servidor confere só os dígitos verificadores: isso não confirma que a pessoa é a titular.',
} as const;

/** Paleta do passo 3: a de `field-types.ts` (com `stamp` pela flag `branding`) + CPF pela flag `cpf_field`. */
export function editorPaletteTypes(features: {
    branding?: boolean;
    cpf_field?: boolean;
}): readonly EditorFieldType[] {
    const base = paletteFieldTypes(features);

    if (!features.cpf_field) {
        return base;
    }

    // O CPF fica logo depois do texto livre: é outro campo digitado pelo participante.
    const index = base.indexOf('text');

    return index >= 0
        ? [
              ...base.slice(0, index + 1),
              CPF_FIELD_TYPE,
              ...base.slice(index + 1),
          ]
        : [...base, CPF_FIELD_TYPE];
}

export function isKnownFieldType(value: string): value is EditorFieldType {
    return value === CPF_FIELD_TYPE || value in DEFAULT_FIELD_SIZE;
}

export function fieldTypeIcon(type: SigningFieldType): LucideIcon {
    return type === CPF_FIELD_TYPE ? CPF_ENTRY.icon : FIELD_TYPE_ICONS[type];
}

export function defaultFieldSize(type: SigningFieldType): {
    w: number;
    h: number;
} {
    return type === CPF_FIELD_TYPE ? CPF_ENTRY.size : DEFAULT_FIELD_SIZE[type];
}

export function fieldPlaceholder(type: SigningFieldType): string | null {
    return type === CPF_FIELD_TYPE
        ? CPF_PLACEHOLDER
        : FIELD_TYPE_PLACEHOLDER[type];
}

export function fieldTypeHint(type: SigningFieldType): string {
    return type === CPF_FIELD_TYPE ? CPF_ENTRY.hint : FIELD_TYPE_HINTS[type];
}

/** Mínimo do tipo em fração da página (mesma regra de `minSizeFor`). */
export function fieldMinSize(
    type: SigningFieldType,
    pagePoints?: PageSize | null,
): MinSize {
    if (type !== CPF_FIELD_TYPE) {
        return minSizeFor(type, pagePoints);
    }

    const page = pagePoints ?? A4_POINTS;

    return {
        w: page.width > 0 ? CPF_MIN_POINTS.w / page.width : 0.02,
        h: page.height > 0 ? CPF_MIN_POINTS.h / page.height : 0.012,
    };
}

/** Conteúdo carimbado pelo servidor (o participante não digita). */
export function isServerFilled(type: SigningFieldType): boolean {
    return (
        type !== CPF_FIELD_TYPE &&
        (SERVER_FILLED_TYPES as readonly string[]).includes(type)
    );
}

/** Campos sem fonte nem texto: o conteúdo é uma imagem (assinatura, rubrica, carimbo). */
export function isImageField(type: SigningFieldType): boolean {
    return type === 'signature' || type === 'initials' || type === 'stamp';
}
