import { centerOf } from './timeline';

/**
 * Último ponto em que a pessoa agiu (clique no "Entrar", Enter no campo de senha, clique em
 * "Sair"). É de onde a cortina da transição se abre — a animação nasce do gesto, não do meio
 * da tela.
 */
type Origin = { x: number; y: number; at: number };

/** Depois disto o gesto já não explica a transição (ex.: sessão expirada) e vale o centro. */
const MAX_AGE_MS = 15_000;

let last: Origin | null = null;

function onPointerDown(event: PointerEvent): void {
    last = { x: event.clientX, y: event.clientY, at: performance.now() };
}

function onKeyDown(event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ') {
        return;
    }

    const center = centerOf(document.activeElement);

    if (center) {
        last = { ...center, at: performance.now() };
    }
}

/** Liga o rastreio; devolve a função que desliga. */
export function trackInteractionOrigin(): () => void {
    window.addEventListener('pointerdown', onPointerDown, true);
    window.addEventListener('keydown', onKeyDown, true);

    return () => {
        window.removeEventListener('pointerdown', onPointerDown, true);
        window.removeEventListener('keydown', onKeyDown, true);
    };
}

/** Ponto de origem da cortina, em px da viewport. */
export function interactionOrigin(): { x: number; y: number } {
    if (last && performance.now() - last.at < MAX_AGE_MS) {
        return { x: last.x, y: last.y };
    }

    return { x: window.innerWidth / 2, y: window.innerHeight / 2 };
}
