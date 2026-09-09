import type { DragEvent, PointerEvent } from 'react';
import { useEffect, useState } from 'react';
import {
    FieldBox,
    type FieldBoxVariant,
} from '@/components/envelopes/field-box';
import { DEFAULT_FIELD_SIZE } from '@/components/envelopes/field-types';
import type { RecipientColor } from '@/components/envelopes/recipient-colors';
import {
    GRID_STEP,
    type MinSize,
    type NormalizedRect,
    type PageSize,
    type ResizeHandle,
    moveRect,
    rectAroundPoint,
    resizeRect,
    snapRect,
} from '@/lib/geometry';
import { cn } from '@/lib/utils';
import type { FieldType } from '@/types/enums';

/**
 * Forma mínima de um campo para a camada. `WizardField` (editor) e os campos
 * do detalhe do documento (somente leitura) satisfazem este contrato.
 */
export interface LayerField {
    client_id: string;
    recipient_client_id: string;
    type: FieldType;
    page: number | 'all';
    x: number;
    y: number;
    w: number;
    h: number;
    placeholder?: string | null;
}

/** Tipo MIME interno do arraste da paleta para a página. */
export const FIELD_DRAG_MIME = 'application/x-assinavelox-field-type';

/** Passo do teclado: 0,5 % da página por seta (2 % com Ctrl). */
const KEYBOARD_STEP = 0.005;
const KEYBOARD_STEP_LARGE = 0.02;

interface DragState {
    clientId: string;
    handle: ResizeHandle | null;
    startX: number;
    startY: number;
    startRect: NormalizedRect;
    min: MinSize | undefined;
}

export interface FieldLayerProps<T extends LayerField = LayerField> {
    /** Campos já filtrados para a página exibida. */
    fields: T[];
    /** Dimensões renderizadas da página, em pixels CSS. */
    page: PageSize;
    selectedId: string | null;
    onSelect: (clientId: string | null) => void;
    /** Chamado continuamente durante o arraste/redimensionamento. */
    onChange: (clientId: string, rect: NormalizedRect) => void;
    /** Fim de uma interação (solta o mouse ou solta a tecla). */
    onCommit?: () => void;
    onDelete: (clientId: string) => void;
    onDuplicate: (clientId: string) => void;
    /** Soltar um tipo da paleta sobre a página. */
    onDropType?: (type: FieldType, rect: NormalizedRect) => void;
    colorOf: (field: T) => RecipientColor;
    tagOf: (field: T) => string;
    hintOf?: (field: T) => string | null;
    /** Tamanho mínimo do campo (fração da página) — ver `minSizeFor`. */
    minSizeOf?: (field: T) => MinSize;
    /** Aparência da caixa: edição, pendente ou assinada (DESIGN §4.19). */
    variantOf?: (field: T) => FieldBoxVariant;
    /** Linha de 9px no rodapé da caixa ("✓ Assinado em …"). */
    footnoteOf?: (field: T) => string | null;
    /** Alinhar à grade de 2 % ao mover/redimensionar. */
    grid?: boolean;
    readOnly?: boolean;
    className?: string;
}

/**
 * Camada de campos sobre o canvas do PDF (DESIGN §4.19 "Campos sobrepostos").
 *
 * Toda a matemática de posição está em `lib/geometry.ts`: aqui só entram
 * deltas em pixels do ponteiro, convertidos para fração dividindo pelas
 * dimensões renderizadas da página. Os limites (`x + w ≤ 1`, `y + h ≤ 1`) são
 * garantidos por `clampRect`, então nenhum campo sai do papel.
 *
 * Teclado: `Tab` percorre os campos; setas movem; `Shift` + setas
 * redimensionam; `Ctrl`/`⌘` acelera o passo; `Delete`/`Backspace` remove;
 * `Ctrl`/`⌘` + `D` duplica; `Esc` limpa a seleção.
 */
export function FieldLayer<T extends LayerField>({
    fields,
    page,
    selectedId,
    onSelect,
    onChange,
    onCommit,
    onDelete,
    onDuplicate,
    onDropType,
    colorOf,
    tagOf,
    hintOf,
    minSizeOf,
    variantOf,
    footnoteOf,
    grid = false,
    readOnly = false,
    className,
}: FieldLayerProps<T>) {
    const [drag, setDrag] = useState<DragState | null>(null);
    const [dropping, setDropping] = useState(false);

    useEffect(() => {
        if (!drag || page.width <= 0 || page.height <= 0) {
            return;
        }

        const apply = (event: globalThis.PointerEvent): void => {
            const dx = (event.clientX - drag.startX) / page.width;
            const dy = (event.clientY - drag.startY) / page.height;
            const next = drag.handle
                ? resizeRect(drag.startRect, drag.handle, dx, dy, drag.min)
                : moveRect(drag.startRect, dx, dy, drag.min);

            onChange(
                drag.clientId,
                grid ? snapRect(next, GRID_STEP, drag.min) : next,
            );
        };

        const finish = (): void => {
            setDrag(null);
            onCommit?.();
        };

        window.addEventListener('pointermove', apply);
        window.addEventListener('pointerup', finish);
        window.addEventListener('pointercancel', finish);

        return () => {
            window.removeEventListener('pointermove', apply);
            window.removeEventListener('pointerup', finish);
            window.removeEventListener('pointercancel', finish);
        };
    }, [drag, page.width, page.height, grid, onChange, onCommit]);

    const startDrag = (
        field: T,
        handle: ResizeHandle | null,
        event: PointerEvent<HTMLElement>,
    ): void => {
        if (readOnly || event.button !== 0) {
            return;
        }

        // `preventDefault` evita a seleção de texto durante o arraste; o foco
        // é dado à mão para que as setas continuem funcionando logo depois
        // (a alça é filha da caixa, daí o `closest`).
        event.preventDefault();
        event.currentTarget
            .closest<HTMLElement>('[role="button"]')
            ?.focus({ preventScroll: true });
        onSelect(field.client_id);
        setDrag({
            clientId: field.client_id,
            handle,
            startX: event.clientX,
            startY: event.clientY,
            startRect: { x: field.x, y: field.y, w: field.w, h: field.h },
            min: minSizeOf?.(field),
        });
    };

    const handleKeyDown = (
        field: T,
        event: React.KeyboardEvent<HTMLElement>,
    ): void => {
        if (readOnly) {
            return;
        }

        const rect: NormalizedRect = {
            x: field.x,
            y: field.y,
            w: field.w,
            h: field.h,
        };

        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            onDelete(field.client_id);

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            onSelect(null);

            return;
        }

        if (
            (event.ctrlKey || event.metaKey) &&
            event.key.toLowerCase() === 'd'
        ) {
            event.preventDefault();
            onDuplicate(field.client_id);

            return;
        }

        const step =
            event.ctrlKey || event.metaKey
                ? KEYBOARD_STEP_LARGE
                : KEYBOARD_STEP;
        const delta: Record<string, [number, number]> = {
            ArrowLeft: [-step, 0],
            ArrowRight: [step, 0],
            ArrowUp: [0, -step],
            ArrowDown: [0, step],
        };
        const move = delta[event.key];

        if (!move) {
            return;
        }

        event.preventDefault();

        // Shift + seta redimensiona pelo canto inferior direito; a origem fica.
        const min = minSizeOf?.(field);
        const next = event.shiftKey
            ? resizeRect(rect, 'se', move[0], move[1], min)
            : moveRect(rect, move[0], move[1], min);

        onChange(field.client_id, next);
        onCommit?.();
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>): void => {
        setDropping(false);

        if (readOnly || !onDropType) {
            return;
        }

        // Sempre impedir o comportamento padrão do navegador (que abriria o
        // conteúdo solto); só depois decidir se o tipo é conhecido.
        event.preventDefault();

        const type = event.dataTransfer.getData(FIELD_DRAG_MIME) as
            | FieldType
            | '';

        if (!type || !(type in DEFAULT_FIELD_SIZE)) {
            return;
        }

        const bounds = event.currentTarget.getBoundingClientRect();
        const rect = rectAroundPoint(
            event.clientX - bounds.left,
            event.clientY - bounds.top,
            DEFAULT_FIELD_SIZE[type],
            page,
        );

        onDropType(type, grid ? snapRect(rect, GRID_STEP) : rect);
    };

    return (
        <div
            className={cn('absolute inset-0', className)}
            onPointerDown={(event) => {
                if (!readOnly && event.target === event.currentTarget) {
                    onSelect(null);
                }
            }}
            onDragOver={(event) => {
                if (readOnly || !onDropType) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
                setDropping(true);
            }}
            onDragLeave={(event) => {
                if (event.target === event.currentTarget) {
                    setDropping(false);
                }
            }}
            onDrop={handleDrop}
        >
            {grid && !readOnly && (
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 opacity-60"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, rgba(18,87,201,.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(18,87,201,.10) 1px, transparent 1px)',
                        backgroundSize: `${GRID_STEP * page.width}px ${GRID_STEP * page.height}px`,
                    }}
                />
            )}

            {dropping && (
                <div
                    aria-hidden
                    className="border-primary bg-primary-soft/40 pointer-events-none absolute inset-0 rounded border-2 border-dashed"
                />
            )}

            {fields.map((field) => (
                <FieldBox
                    key={field.client_id}
                    rect={{ x: field.x, y: field.y, w: field.w, h: field.h }}
                    page={page}
                    color={colorOf(field)}
                    tag={tagOf(field)}
                    hint={hintOf?.(field) ?? field.placeholder ?? null}
                    variant={variantOf?.(field) ?? 'editor'}
                    footnote={footnoteOf?.(field) ?? null}
                    selected={field.client_id === selectedId}
                    interactive={!readOnly}
                    ariaLabel={`${tagOf(field)}. Posição ${Math.round(field.x * 100)}% da esquerda, ${Math.round(field.y * 100)}% do topo.${readOnly ? '' : ' Setas movem, Shift com setas redimensiona, Delete remove.'}`}
                    onPointerDownBox={(event) => startDrag(field, null, event)}
                    onPointerDownHandle={(handle, event) =>
                        startDrag(field, handle, event)
                    }
                    onKeyDown={(event) => handleKeyDown(field, event)}
                    onFocus={() => onSelect(field.client_id)}
                />
            ))}
        </div>
    );
}
