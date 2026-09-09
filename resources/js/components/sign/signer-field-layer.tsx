import { Check } from 'lucide-react';
import type { CSSProperties } from 'react';
import { type PageSize, toPixels } from '@/lib/geometry';
import { cn } from '@/lib/utils';
import type { FieldType } from '@/types/enums';

/** Campo do próprio signatário, já resolvido para uma página concreta. */
export interface SignerField {
    id: string;
    type: FieldType;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    placeholder: string | null;
    /** Valor carimbado pelo servidor (nome, data) — somente leitura. */
    prefill: string | null;
}

/** Campo de outro participante — só indicação, nunca editável. */
export interface OtherField {
    key: string;
    recipient_name: string;
    role: string | null;
    type: FieldType;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    signed: boolean;
    /** Nota exibida quando ainda não assinou ("assina depois de você"). */
    hint?: string | null;
}

export interface SignerFieldLayerProps {
    page: PageSize;
    /** Número da página exibida (1-based). */
    pageNumber: number;
    fields: SignerField[];
    others: OtherField[];
    /** Valores digitados/marcados pelo signatário, por id de campo. */
    values: Record<string, string | boolean>;
    /** Imagem PNG aplicada aos campos `signature` / `initials`. */
    signatureImage: string | null;
    initialsImage: string | null;
    /** Campo em foco (destacado com anel). */
    activeId: string | null;
    onActivate: (field: SignerField) => void;
    className?: string;
}

const MINE_LABEL: Record<FieldType, string> = {
    signature: 'Assinatura',
    initials: 'Rubrica',
    name: 'Nome',
    date: 'Data',
    text: 'Texto',
    checkbox: 'Marcar',
};

const EMPTY_HINT: Record<FieldType, string> = {
    signature: 'Clique para assinar aqui',
    initials: 'Clique para rubricar',
    name: 'Preenchido no aceite',
    date: 'Data do aceite',
    text: 'Clique para preencher',
    checkbox: 'Clique para marcar',
};

/**
 * Camada de campos da página pública (DESIGN §4.19 "Campos sobrepostos",
 * variantes públicas).
 *
 * Só os campos do próprio signatário são interativos; os dos demais aparecem
 * como indicação ("assina depois de você" / "✓ assinou em …"). A geometria vem
 * das mesmas frações normalizadas do editor, convertidas por `lib/geometry.ts`
 * contra as dimensões renderizadas pelo PDF.js — nada é recalculado aqui.
 */
export function SignerFieldLayer({
    page,
    pageNumber,
    fields,
    others,
    values,
    signatureImage,
    initialsImage,
    activeId,
    onActivate,
    className,
}: SignerFieldLayerProps) {
    return (
        <div
            className={cn('pointer-events-none absolute inset-0', className)}
            aria-label={`Campos da página ${pageNumber}`}
        >
            {others
                .filter((field) => field.page === pageNumber)
                .map((field) => (
                    <OtherFieldBox key={field.key} field={field} page={page} />
                ))}

            {fields
                .filter((field) => field.page === pageNumber)
                .map((field) => (
                    <MyFieldBox
                        key={`${field.id}-${field.page}`}
                        field={field}
                        page={page}
                        value={values[field.id]}
                        signatureImage={signatureImage}
                        initialsImage={initialsImage}
                        active={activeId === field.id}
                        onActivate={onActivate}
                    />
                ))}
        </div>
    );
}

/** Conteúdo já definido para o campo (imagem, texto ou marcação). */
function filledContent(
    field: SignerField,
    value: string | boolean | undefined,
    signatureImage: string | null,
    initialsImage: string | null,
): { image?: string; text?: string; checked?: boolean } | null {
    if (field.type === 'signature') {
        return signatureImage ? { image: signatureImage } : null;
    }

    if (field.type === 'initials') {
        return initialsImage ? { image: initialsImage } : null;
    }

    if (field.type === 'checkbox') {
        return value === true ? { checked: true } : null;
    }

    const text =
        typeof value === 'string' && value.trim() !== ''
            ? value
            : (field.prefill ?? '');

    return text.trim() === '' ? null : { text };
}

function MyFieldBox({
    field,
    page,
    value,
    signatureImage,
    initialsImage,
    active,
    onActivate,
}: {
    field: SignerField;
    page: PageSize;
    value: string | boolean | undefined;
    signatureImage: string | null;
    initialsImage: string | null;
    active: boolean;
    onActivate: (field: SignerField) => void;
}) {
    const box = toPixels(field, page);
    const filled = filledContent(field, value, signatureImage, initialsImage);

    const style: CSSProperties = {
        left: box.left,
        top: box.top,
        width: box.width,
        height: box.height,
        borderWidth: 1.5,
        borderStyle: filled ? 'solid' : 'dashed',
        borderColor: filled ? 'var(--success-solid)' : 'var(--primary)',
        backgroundColor: filled ? 'var(--success-bg)' : 'var(--primary-soft)',
    };

    const tagColor = filled ? 'var(--success)' : 'var(--primary)';
    const tag = field.label?.trim()
        ? field.label
        : `${MINE_LABEL[field.type]} · você`;

    return (
        <button
            type="button"
            style={style}
            onClick={() => onActivate(field)}
            aria-label={`${tag}${filled ? ' — preenchido' : ' — pendente'}. ${
                field.required ? 'Obrigatório.' : 'Opcional.'
            }`}
            className={cn(
                'focus-ring pointer-events-auto absolute cursor-pointer rounded-md',
                active && 'ring-primary ring-2 ring-offset-1',
            )}
        >
            <span
                style={{
                    color: tagColor,
                    backgroundColor: style.backgroundColor,
                }}
                className="pointer-events-none absolute -top-[9px] left-2 max-w-[calc(100%-8px)] truncate px-1 text-[9px] font-bold tracking-[.1em] uppercase"
            >
                {tag}
            </span>

            {filled?.image && (
                <img
                    src={filled.image}
                    alt=""
                    className="absolute inset-0 m-auto max-h-[88%] max-w-[94%] object-contain"
                />
            )}

            {filled?.text && (
                <span className="text-foreground absolute inset-x-1.5 top-1/2 -translate-y-1/2 truncate text-left text-[10px]">
                    {filled.text}
                </span>
            )}

            {filled?.checked && (
                <Check className="text-success absolute inset-0 m-auto size-3.5 stroke-[3]" />
            )}

            {!filled && (
                <span className="text-primary absolute inset-x-1.5 top-1/2 -translate-y-1/2 truncate text-left text-[10px] font-semibold">
                    {field.placeholder ?? EMPTY_HINT[field.type]}
                </span>
            )}
        </button>
    );
}

function OtherFieldBox({ field, page }: { field: OtherField; page: PageSize }) {
    const box = toPixels(field, page);
    const first = field.recipient_name.split(' ')[0] || field.recipient_name;

    return (
        <div
            style={{
                left: box.left,
                top: box.top,
                width: box.width,
                height: box.height,
                borderWidth: 1.5,
                borderStyle: field.signed ? 'solid' : 'dashed',
                borderColor: field.signed
                    ? 'var(--success-solid)'
                    : 'var(--input)',
                backgroundColor: field.signed
                    ? 'var(--success-bg)'
                    : 'var(--background)',
            }}
            className="absolute rounded-md"
        >
            <span
                style={{
                    color: field.signed
                        ? 'var(--success)'
                        : 'var(--muted-foreground)',
                    backgroundColor: field.signed
                        ? 'var(--success-bg)'
                        : 'var(--background)',
                }}
                className="absolute -top-[9px] left-2 max-w-[calc(100%-8px)] truncate px-1 text-[9px] font-bold tracking-[.1em] uppercase"
            >
                {field.role ?? MINE_LABEL[field.type]}
            </span>

            {field.signed ? (
                <>
                    <span className="font-hand text-foreground absolute inset-x-2 bottom-3 -rotate-2 truncate text-[18px] leading-none">
                        {field.recipient_name}
                    </span>
                    <span className="text-success absolute inset-x-2 bottom-0.5 truncate text-[9px]">
                        Aceite registrado
                    </span>
                </>
            ) : (
                <span className="text-muted-foreground absolute inset-x-1.5 top-1/2 -translate-y-1/2 truncate text-[10px]">
                    {field.hint ?? `${first} · ainda não assinou`}
                </span>
            )}
        </div>
    );
}
