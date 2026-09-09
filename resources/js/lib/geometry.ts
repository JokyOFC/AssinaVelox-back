/**
 * Geometria dos campos posicionados sobre o PDF — funções puras.
 *
 * ## Convenção de coordenadas (contrato com o backend)
 *
 * `x`, `y`, `w` e `h` são frações em `[0, 1]` relativas ao **CropBox exibido**
 * da página, com a origem no canto **superior esquerdo** e **já considerando a
 * rotação** da página. Na prática são exatamente as coordenadas do canvas do
 * PDF.js divididas pelas dimensões renderizadas:
 *
 * ```
 * x = left_px / rendered_width_px
 * y = top_px  / rendered_height_px
 * w = width_px  / rendered_width_px
 * h = height_px / rendered_height_px
 * ```
 *
 * Como o PDF.js já aplica a rotação ao montar o `viewport`, o canvas é sempre
 * "o que o olho vê": não há conversão de eixo Y nem correção de ângulo aqui.
 *
 * O navegador **não** envia dimensões de página ao servidor: o backend
 * revalida `0 ≤ x`, `0 ≤ y`, `x + w ≤ 1`, `y + h ≤ 1` e os tamanhos mínimos
 * contra a versão do documento que ele mesmo carregou
 * (`docs/arquitetura.md` §3.1, tabela `signing_fields`).
 */

/** Retângulo normalizado em [0,1], origem no canto superior esquerdo. */
export interface NormalizedRect {
    x: number;
    y: number;
    w: number;
    h: number;
}

/** Retângulo em pixels CSS dentro do canvas renderizado. */
export interface PixelRect {
    left: number;
    top: number;
    width: number;
    height: number;
}

/** Dimensões renderizadas da página (pixels CSS do canvas). */
export interface PageSize {
    width: number;
    height: number;
}

/** Alças de redimensionamento (cantos). */
export type ResizeHandle = 'nw' | 'ne' | 'sw' | 'se';

export const RESIZE_HANDLES: readonly ResizeHandle[] = [
    'nw',
    'ne',
    'sw',
    'se',
] as const;

/**
 * Piso absoluto de tamanho, em fração da página: abaixo disso a geometria não
 * sobrevive ao `DECIMAL(9,6)` do banco. O mínimo **real** é por tipo de campo,
 * definido em pontos e convertido pela dimensão exibida da página
 * (`components/envelopes/field-types.ts`; docs/campos-e-geometria.md §3).
 */
export const MIN_FIELD_WIDTH = 0.001;
export const MIN_FIELD_HEIGHT = 0.001;

/** Tamanho mínimo aplicado numa operação (fração da página). */
export interface MinSize {
    w: number;
    h: number;
}

const ABSOLUTE_MIN: MinSize = { w: MIN_FIELD_WIDTH, h: MIN_FIELD_HEIGHT };

function floorOf(min?: MinSize): MinSize {
    return {
        w: Math.max(MIN_FIELD_WIDTH, min?.w ?? MIN_FIELD_WIDTH),
        h: Math.max(MIN_FIELD_HEIGHT, min?.h ?? MIN_FIELD_HEIGHT),
    };
}

/** Passo da grade opcional (2 % da página em cada eixo). */
export const GRID_STEP = 0.02;

export function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

export function clamp01(value: number): number {
    return clamp(value, 0, 1);
}

/** Arredonda para 6 casas — a coluna do banco é DECIMAL(9,6). */
export function roundCoordinate(value: number): number {
    return Math.round(value * 1_000_000) / 1_000_000;
}

export function roundRect(rect: NormalizedRect): NormalizedRect {
    return {
        x: roundCoordinate(rect.x),
        y: roundCoordinate(rect.y),
        w: roundCoordinate(rect.w),
        h: roundCoordinate(rect.h),
    };
}

/** Normalizado → pixels CSS do canvas. */
export function toPixels(rect: NormalizedRect, page: PageSize): PixelRect {
    return {
        left: rect.x * page.width,
        top: rect.y * page.height,
        width: rect.w * page.width,
        height: rect.h * page.height,
    };
}

/** Pixels CSS do canvas → normalizado (sem clamp; use `clampRect` depois). */
export function toNormalized(rect: PixelRect, page: PageSize): NormalizedRect {
    if (page.width <= 0 || page.height <= 0) {
        return { x: 0, y: 0, w: 0, h: 0 };
    }

    return {
        x: rect.left / page.width,
        y: rect.top / page.height,
        w: rect.width / page.width,
        h: rect.height / page.height,
    };
}

/**
 * Garante que o retângulo caiba na página: primeiro limita o tamanho
 * (mínimo e máximo), depois empurra a origem para dentro dos limites.
 * O resultado sempre satisfaz `x + w ≤ 1` e `y + h ≤ 1`.
 */
export function clampRect(
    rect: NormalizedRect,
    min: MinSize = ABSOLUTE_MIN,
): NormalizedRect {
    const floor = floorOf(min);
    const w = clamp(rect.w, floor.w, 1);
    const h = clamp(rect.h, floor.h, 1);

    return roundRect({
        x: clamp(rect.x, 0, 1 - w),
        y: clamp(rect.y, 0, 1 - h),
        w,
        h,
    });
}

/** Move o retângulo por um delta normalizado, mantendo-o dentro da página. */
export function moveRect(
    rect: NormalizedRect,
    dx: number,
    dy: number,
    min: MinSize = ABSOLUTE_MIN,
): NormalizedRect {
    return clampRect({ ...rect, x: rect.x + dx, y: rect.y + dy }, min);
}

/**
 * Redimensiona a partir de uma alça de canto. O canto oposto fica ancorado;
 * o tamanho mínimo é respeitado e nada sai da página.
 */
export function resizeRect(
    rect: NormalizedRect,
    handle: ResizeHandle,
    dx: number,
    dy: number,
    min: MinSize = ABSOLUTE_MIN,
): NormalizedRect {
    const floor = floorOf(min);
    const right = rect.x + rect.w;
    const bottom = rect.y + rect.h;

    let { x, y } = rect;
    let nextRight = right;
    let nextBottom = bottom;

    if (handle === 'nw' || handle === 'sw') {
        x = clamp(rect.x + dx, 0, right - floor.w);
    } else {
        nextRight = clamp(right + dx, rect.x + floor.w, 1);
    }

    if (handle === 'nw' || handle === 'ne') {
        y = clamp(rect.y + dy, 0, bottom - floor.h);
    } else {
        nextBottom = clamp(bottom + dy, rect.y + floor.h, 1);
    }

    return clampRect({ x, y, w: nextRight - x, h: nextBottom - y }, min);
}

function snapValue(value: number, step: number): number {
    return step > 0 ? Math.round(value / step) * step : value;
}

/** Alinha origem e tamanho à grade, sem deixar o campo sair da página. */
export function snapRect(
    rect: NormalizedRect,
    step: number = GRID_STEP,
    min: MinSize = ABSOLUTE_MIN,
): NormalizedRect {
    const floor = floorOf(min);

    return clampRect(
        {
            x: snapValue(rect.x, step),
            y: snapValue(rect.y, step),
            w: Math.max(snapValue(rect.w, step), floor.w),
            h: Math.max(snapValue(rect.h, step), floor.h),
        },
        min,
    );
}

/**
 * Centraliza um retângulo do tamanho pedido em torno de um ponto (em pixels
 * do canvas) — usado ao soltar um tipo de campo sobre a página.
 */
export function rectAroundPoint(
    pointX: number,
    pointY: number,
    size: MinSize,
    page: PageSize,
    min: MinSize = ABSOLUTE_MIN,
): NormalizedRect {
    const normalized = toNormalized(
        {
            left: pointX - (size.w * page.width) / 2,
            top: pointY - (size.h * page.height) / 2,
            width: size.w * page.width,
            height: size.h * page.height,
        },
        page,
    );

    return clampRect(normalized, min);
}

/** Área do retângulo em fração da página (para ordenar campos sobrepostos). */
export function rectArea(rect: NormalizedRect): number {
    return rect.w * rect.h;
}

/** Ordem de leitura: de cima para baixo, depois da esquerda para a direita. */
export function compareByReadingOrder(
    a: NormalizedRect,
    b: NormalizedRect,
): number {
    return a.y === b.y ? a.x - b.x : a.y - b.y;
}
