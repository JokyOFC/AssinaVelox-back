import { useEffect, useLayoutEffect, useRef } from 'react';
import { interactionOrigin } from './interaction-origin';
import { LOGIN_TIMING, LoginScene } from './login-scene';
import { LOGOUT_TIMING, LogoutScene } from './logout-scene';
import { easeInOutCubic, type SceneHandle, span } from './timeline';

export type AuthTransitionScene = {
    kind: 'login' | 'logout';
    /** Nome completo de quem entrou ou saiu. */
    name: string;
    email: string;
    /** Só em desenvolvimento: congela a cena neste instante (ms) em vez de tocá-la. */
    frozenAt?: number | null;
};

/** Com "reduzir movimento": a cena aparece pronta, fica um instante e some num fade. */
const REDUCED_TIMING = { exit: 1100, end: 1350 };

/** Por quanto tempo o atributo que estiliza a View Transition fica no <html>. */
const VIEW_TRANSITION_WINDOW_MS = 900;

/** Pétalas da roseta de fundo — o guilhochê dos papéis de segurança. */
const ROSETTE_PETALS = 18;

/** Primeiro nome, e o nome que cabe numa assinatura (nome e último sobrenome). */
export function splitName(fullName: string): {
    first: string;
    signature: string;
} {
    const parts = fullName.trim().split(/\s+/).filter(Boolean);
    const first = parts[0] ?? '';
    const both =
        parts.length > 1 ? `${first} ${parts[parts.length - 1]}` : first;

    return { first, signature: both.length <= 22 ? both : first };
}

/**
 * Cortina da transição de autenticação: cobre a tela no mesmo commit em que a página troca,
 * toca a cena (assinatura na entrada, envelope lacrado na saída) e se abre a partir do selo.
 *
 * Tudo aqui é decorativo — o anúncio para leitores de tela fica no `AuthTransitionRoot`.
 * Clique, toque, Esc, Enter ou espaço pulam para a saída.
 */
export function AuthTransitionOverlay({
    scene,
    onDone,
}: {
    scene: AuthTransitionScene;
    onDone: () => void;
}) {
    const root = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const rosette = useRef<SVGSVGElement>(null);
    const ring = useRef<HTMLDivElement>(null);
    const handle = useRef<SceneHandle>(null);
    const skip = useRef<() => void>(() => {});
    const { kind, frozenAt = null } = scene;
    const { first, signature } = splitName(scene.name);

    // Estiliza a View Transition desta visita (resources/css/app.css): a cortina nasce do
    // ponto do clique. Roda dentro do commit da troca de página, antes da foto do novo estado.
    useLayoutEffect(() => {
        const html = document.documentElement;
        const origin = interactionOrigin();

        // Raio até o canto mais distante: a cortina termina de cobrir a tela no último
        // quadro, e não no primeiro terço da animação (que é o que um raio fixo enorme faz).
        const reach = Math.hypot(
            Math.max(origin.x, window.innerWidth - origin.x),
            Math.max(origin.y, window.innerHeight - origin.y),
        );

        html.dataset.authTransition = kind;
        html.style.setProperty('--av-origin-x', `${origin.x}px`);
        html.style.setProperty('--av-origin-y', `${origin.y}px`);
        html.style.setProperty('--av-reach', `${Math.ceil(reach) + 2}px`);

        const clear = () => {
            delete html.dataset.authTransition;
            html.style.removeProperty('--av-origin-x');
            html.style.removeProperty('--av-origin-y');
            html.style.removeProperty('--av-reach');
        };
        const timer = window.setTimeout(clear, VIEW_TRANSITION_WINDOW_MS);

        return () => {
            window.clearTimeout(timer);
            clear();
        };
    }, [kind]);

    useEffect(() => {
        const rootEl = root.current;

        if (!rootEl) {
            return;
        }

        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const timing = reduced
            ? REDUCED_TIMING
            : kind === 'login'
              ? LOGIN_TIMING
              : LOGOUT_TIMING;
        const startedAt = performance.now();

        // Para testes e para quem depura: qual das duas versões está tocando.
        rootEl.dataset.motion = reduced ? 'reduced' : 'full';

        let offset = 0;
        let frame = 0;
        let finished = false;
        let focus: { x: number; y: number } | null = null;

        const render = (t: number) => {
            rootEl.style.pointerEvents = t >= timing.exit ? 'none' : '';

            if (reduced) {
                handle.current?.update(Number.MAX_SAFE_INTEGER);
                rootEl.style.opacity = String(
                    1 - span(t, timing.exit, timing.end),
                );

                return;
            }

            handle.current?.update(t);

            if (rosette.current) {
                rosette.current.style.transform = `rotate(${t * 0.005}deg)`;
            }

            if (content.current) {
                content.current.style.opacity = String(
                    1 - span(t, timing.exit, timing.exit + 220),
                );
            }

            // Saída: um furo se abre a partir do selo e revela a página que já está pronta.
            const open = easeInOutCubic(span(t, timing.exit, timing.end));

            if (open <= 0) {
                rootEl.style.removeProperty('-webkit-mask-image');
                rootEl.style.removeProperty('mask-image');

                if (ring.current) {
                    ring.current.style.opacity = '0';
                }

                return;
            }

            focus ??= handle.current?.focusPoint() ?? {
                x: window.innerWidth / 2,
                y: window.innerHeight / 2,
            };

            const reach =
                Math.hypot(
                    Math.max(focus.x, window.innerWidth - focus.x),
                    Math.max(focus.y, window.innerHeight - focus.y),
                ) + 8;
            const radius = open * reach;
            const mask = `radial-gradient(circle at ${focus.x}px ${focus.y}px, transparent ${radius}px, #000 ${radius + 1.5}px)`;

            rootEl.style.setProperty('-webkit-mask-image', mask);
            rootEl.style.setProperty('mask-image', mask);

            if (ring.current) {
                const outer = radius + 3;

                ring.current.style.width = `${outer * 2}px`;
                ring.current.style.height = `${outer * 2}px`;
                ring.current.style.transform = `translate(${focus.x - outer}px, ${focus.y - outer}px)`;
                ring.current.style.opacity = String(0.8 * (1 - open));
            }
        };

        const finish = () => {
            if (!finished) {
                finished = true;
                onDone();
            }
        };

        if (frozenAt !== null) {
            render(frozenAt);

            return;
        }

        const tick = (now: number) => {
            const t = now - startedAt + offset;

            render(t);

            if (t >= timing.end) {
                finish();

                return;
            }

            frame = window.requestAnimationFrame(tick);
        };

        skip.current = () => {
            const t = performance.now() - startedAt + offset;

            if (t < timing.exit) {
                offset += timing.exit - t;
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (['Escape', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                skip.current();
            }
        };

        // Aba em segundo plano não roda requestAnimationFrame; o temporizador garante que a
        // cortina nunca fique presa na frente da página.
        const guard = window.setTimeout(finish, timing.end + 2000);

        // Primeiro quadro já aqui, sem esperar o navegador: com movimento reduzido a cena
        // inteira aparece de uma vez, e uma cortina vazia por um quadro seria um pisca.
        render(0);
        frame = window.requestAnimationFrame(tick);
        window.addEventListener('keydown', onKeyDown, true);

        return () => {
            finished = true;
            window.cancelAnimationFrame(frame);
            window.clearTimeout(guard);
            window.removeEventListener('keydown', onKeyDown, true);
        };
    }, [kind, frozenAt, onDone]);

    return (
        <div
            ref={root}
            aria-hidden
            data-auth-transition-overlay={kind}
            onPointerDown={() => skip.current()}
            className="bg-navy fixed inset-0 z-[2147483000] flex items-center justify-center overflow-hidden px-6 text-white select-none"
        >
            <div
                className="pointer-events-none absolute -top-[180px] -right-[160px] size-[560px] rounded-full"
                style={{
                    background:
                        'radial-gradient(circle, rgba(46,123,239,.35), rgba(46,123,239,0) 70%)',
                }}
            />
            <div
                className="pointer-events-none absolute -bottom-[220px] -left-[180px] size-[520px] rounded-full"
                style={{
                    background:
                        'radial-gradient(circle, rgba(18,87,201,.28), rgba(18,87,201,0) 70%)',
                }}
            />
            <svg
                ref={rosette}
                viewBox="-200 -200 400 400"
                className="pointer-events-none absolute size-[min(150vmin,900px)] opacity-[.06]"
            >
                {Array.from({ length: ROSETTE_PETALS }, (_, index) => (
                    <ellipse
                        key={index}
                        rx="196"
                        ry="72"
                        fill="none"
                        stroke="#9cc0ff"
                        strokeWidth="0.5"
                        transform={`rotate(${(index * 180) / ROSETTE_PETALS})`}
                    />
                ))}
            </svg>

            {/* Em tela grande a cena cresce inteira (texto e desenho juntos). */}
            <div
                ref={content}
                className="relative w-full max-w-[400px] xl:scale-[1.15] 2xl:scale-[1.3]"
            >
                {kind === 'login' ? (
                    <LoginScene
                        ref={handle}
                        signature={signature || 'AssinaVelox'}
                        email={scene.email}
                    />
                ) : (
                    <LogoutScene ref={handle} firstName={first} />
                )}
            </div>

            <div
                ref={ring}
                className="border-primary-bright pointer-events-none absolute top-0 left-0 rounded-full border-2"
                style={{ opacity: 0 }}
            />
        </div>
    );
}
