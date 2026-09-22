/**
 * Relógio da transição de autenticação: todo valor animado é função pura do tempo (ms desde
 * que a cortina montou). Um relógio só mantém caneta, tinta, selo e saída sincronizados, e
 * deixa qualquer quadro reproduzível (`window.__avAuthTransition`, em desenvolvimento).
 */

export const clamp01 = (value: number): number =>
    Math.min(1, Math.max(0, value));

/** Progresso 0–1 de `t` dentro do trecho [start, end]. */
export const span = (t: number, start: number, end: number): number =>
    clamp01((t - start) / (end - start));

export const lerp = (from: number, to: number, progress: number): number =>
    from + (to - from) * progress;

export const easeOutCubic = (p: number): number => 1 - (1 - p) ** 3;

export const easeInOutCubic = (p: number): number =>
    p < 0.5 ? 4 * p ** 3 : 1 - (-2 * p + 2) ** 3 / 2;

export const easeInOutSine = (p: number): number =>
    -(Math.cos(Math.PI * p) - 1) / 2;

/** Passa do alvo e volta — o "toc" do selo batendo no papel. */
export const easeOutBack = (p: number): number => {
    const c1 = 1.70158;
    const c3 = c1 + 1;

    return 1 + c3 * (p - 1) ** 3 + c1 * (p - 1) ** 2;
};

/** Marcos de uma cena, em ms. `exit` é quando a cortina começa a abrir; `end`, quando some. */
export type SceneTiming = {
    exit: number;
    end: number;
};

/** O que a cortina espera de uma cena. */
export type SceneHandle = {
    /** Desenha o quadro do instante `t`. */
    update: (t: number) => void;
    /** Centro (px da viewport) de onde a cortina se abre na saída. */
    focusPoint: () => { x: number; y: number } | null;
};

/** Centro de um elemento em coordenadas da viewport. */
export function centerOf(
    element: Element | null,
): { x: number; y: number } | null {
    if (!element) {
        return null;
    }

    const rect = element.getBoundingClientRect();

    return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
}
