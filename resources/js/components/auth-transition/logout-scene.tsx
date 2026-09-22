import { type Ref, useId, useImperativeHandle, useRef } from 'react';
import AppLogo from '@/components/app-logo';
import {
    centerOf,
    clamp01,
    easeInOutCubic,
    easeOutBack,
    easeOutCubic,
    lerp,
    type SceneHandle,
    type SceneTiming,
    span,
} from './timeline';

export const LOGOUT_TIMING: SceneTiming = { exit: 1900, end: 2400 };

/** Marcos da cena, em ms. */
const STAGE_IN = [80, 460] as const;
const SHEET = [300, 780] as const;
const FLAP = [760, 1080] as const;
const SEAL = [1060, 1320] as const;
const RIPPLE = [1190, 1640] as const;
const HEADLINE = [1180, 1460] as const;
const FAREWELL = [1280, 1560] as const;

/** Instante em que o selo encosta no papel (o envelope "sente" a batida). */
const IMPACT = SEAL[0] + (SEAL[1] - SEAL[0]) * 0.5;

/** Geometria (unidades do viewBox 360×236). */
const FLAP_HINGE_Y = 92;
const SHEET_TRAVEL = 82;
const SEAL_X = 180;
const SEAL_Y = 166;

type Rgb = readonly [number, number, number];

/** Face de dentro da aba (aberta), face de fora (fechada) e o tom de quando está de perfil. */
const FLAP_INSIDE: Rgb = [207, 218, 238];
const FLAP_OUTSIDE: Rgb = [234, 240, 250];
const FLAP_EDGE: Rgb = [159, 178, 211];

function mix(from: Rgb, to: Rgb, amount: number): string {
    const channel = (index: 0 | 1 | 2) =>
        Math.round(lerp(from[index], to[index], amount));

    return `rgb(${channel(0)} ${channel(1)} ${channel(2)})`;
}

export type LogoutSceneProps = {
    ref: Ref<SceneHandle>;
    firstName: string;
};

/**
 * Saída: o documento desce para dentro do envelope, a aba fecha e um selo com cadeado lacra —
 * "envelope" é o nome que o produto dá ao processo de assinatura, e é ele que se guarda ao sair.
 */
export function LogoutScene({ ref, firstName }: LogoutSceneProps) {
    const glossId = useId();
    const stage = useRef<HTMLDivElement>(null);
    const envelope = useRef<SVGGElement>(null);
    const sheet = useRef<SVGGElement>(null);
    const flapBack = useRef<SVGPathElement>(null);
    const flapFront = useRef<SVGPathElement>(null);
    const seal = useRef<SVGGElement>(null);
    const ripple = useRef<SVGCircleElement>(null);
    const headline = useRef<HTMLHeadingElement>(null);
    const farewell = useRef<HTMLParagraphElement>(null);

    useImperativeHandle(ref, () => ({
        focusPoint: () => centerOf(seal.current),
        update: (t: number) => {
            const stageEl = stage.current;

            if (!stageEl) {
                return;
            }

            const stageIn = easeOutCubic(span(t, ...STAGE_IN));
            stageEl.style.opacity = String(stageIn);
            stageEl.style.transform = `translateY(${lerp(14, 0, stageIn)}px)`;

            const slide = easeInOutCubic(span(t, ...SHEET));
            sheet.current?.setAttribute(
                'transform',
                `translate(0 ${SHEET_TRAVEL * slide})`,
            );

            // A aba gira em torno da dobra: vista de frente, isso é a altura indo de −1 a 1.
            const fold = -Math.cos(Math.PI * easeInOutCubic(span(t, ...FLAP)));
            const flapTransform = `translate(0 ${FLAP_HINGE_Y}) scale(1 ${fold}) translate(0 ${-FLAP_HINGE_Y})`;
            const face = fold < 0 ? FLAP_INSIDE : FLAP_OUTSIDE;
            const flapFill = mix(FLAP_EDGE, face, Math.abs(fold));

            // Aberta, a aba fica atrás do documento; fechando, passa para a frente de tudo.
            for (const [element, visible] of [
                [flapBack.current, fold < 0],
                [flapFront.current, fold >= 0],
            ] as const) {
                if (element) {
                    element.setAttribute('transform', flapTransform);
                    element.setAttribute('fill', flapFill);
                    element.style.opacity = visible ? '1' : '0';
                }
            }

            const stamp = span(t, ...SEAL);

            if (seal.current) {
                const scale = lerp(2.4, 1, easeOutBack(stamp));
                const tilt = lerp(-24, -9, easeOutCubic(stamp));

                seal.current.setAttribute(
                    'transform',
                    `translate(${SEAL_X} ${SEAL_Y}) rotate(${tilt}) scale(${scale})`,
                );
                seal.current.style.opacity = String(clamp01(stamp * 3));
            }

            const wave = easeOutCubic(span(t, ...RIPPLE));

            if (ripple.current) {
                ripple.current.setAttribute('r', String(lerp(21, 54, wave)));
                ripple.current.style.opacity = String(
                    t < RIPPLE[0] ? 0 : 0.55 * (1 - wave),
                );
            }

            const thud =
                t < IMPACT
                    ? 0
                    : 2.5 * (1 - easeOutCubic(span(t, IMPACT, IMPACT + 260)));
            envelope.current?.setAttribute('transform', `translate(0 ${thud})`);

            for (const [element, range] of [
                [headline.current, HEADLINE],
                [farewell.current, FAREWELL],
            ] as const) {
                if (element) {
                    const shown = easeOutCubic(span(t, range[0], range[1]));

                    element.style.opacity = String(shown);
                    element.style.transform = `translateY(${lerp(8, 0, shown)}px)`;
                }
            }
        },
    }));

    const flapPath = `M70 ${FLAP_HINGE_Y} L290 ${FLAP_HINGE_Y} L188 168 Q180 174 172 168 Z`;

    return (
        <div
            ref={stage}
            className="flex w-full flex-col items-center"
            style={{ opacity: 0 }}
        >
            <AppLogo inverted height={28} />

            <svg
                viewBox="0 0 360 236"
                className="mt-4 block w-full max-w-[360px] overflow-visible"
            >
                <defs>
                    <radialGradient id={glossId} cx="35%" cy="30%" r="75%">
                        <stop offset="0" stopColor="#fff" stopOpacity=".38" />
                        <stop offset="1" stopColor="#fff" stopOpacity="0" />
                    </radialGradient>
                </defs>

                <g ref={envelope}>
                    {/* Fundo (o lado de dentro do envelope). */}
                    <path
                        d={`M70 ${FLAP_HINGE_Y} H290 V214 Q290 222 282 222 H78 Q70 222 70 214 Z`}
                        fill="#c3d0e8"
                    />
                    <path
                        ref={flapBack}
                        d={flapPath}
                        stroke="#b4c3de"
                        strokeLinejoin="round"
                    />

                    {/* Documento: título, linhas de texto e uma rubrica. */}
                    <g ref={sheet}>
                        <rect
                            x="92"
                            y="14"
                            width="176"
                            height="124"
                            rx="7"
                            fill="#fff"
                        />
                        <rect
                            x="108"
                            y="30"
                            width="84"
                            height="7"
                            rx="3.5"
                            fill="#0b1f42"
                            opacity=".8"
                        />
                        {[50, 62, 74].map((y, index) => (
                            <rect
                                key={y}
                                x="108"
                                y={y}
                                width={[144, 132, 96][index]}
                                height="4"
                                rx="2"
                                fill="#d5dce9"
                            />
                        ))}
                        <path
                            d="M0 8 C4 -6 9 -8 10 2 C11 10 16 6 19 -1 C22 -8 27 -5 26 2 C25 8 31 7 36 0 C40 -5 46 -3 54 1"
                            transform="translate(108 106)"
                            fill="none"
                            stroke="#1257c9"
                            strokeWidth="2"
                            strokeLinecap="round"
                        />
                        <line
                            x1="108"
                            y1="122"
                            x2="176"
                            y2="122"
                            stroke="#c9d4e6"
                            strokeDasharray="2 3"
                        />
                        <circle cx="244" cy="112" r="9" fill="#e6f7ee" />
                        <path
                            d="M239.5 112 l3.2 3.2 l6 -6.4"
                            fill="none"
                            stroke="#12784a"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    </g>

                    {/* Bolso da frente, com as dobras. */}
                    <path
                        d={`M70 ${FLAP_HINGE_Y} L180 170 L290 ${FLAP_HINGE_Y} V214 Q290 222 282 222 H78 Q70 222 70 214 Z`}
                        fill="#f4f7fc"
                        stroke="#c9d4e6"
                        strokeLinejoin="round"
                    />
                    <path
                        d="M76 217 L162 160 M284 217 L198 160"
                        fill="none"
                        stroke="#dde5f2"
                        strokeWidth="1.2"
                        strokeLinecap="round"
                    />

                    <path
                        ref={flapFront}
                        d={flapPath}
                        stroke="#c9d4e6"
                        strokeLinejoin="round"
                        style={{ opacity: 0 }}
                    />

                    <circle
                        ref={ripple}
                        cx={SEAL_X}
                        cy={SEAL_Y}
                        r="21"
                        fill="none"
                        stroke="#2e7bef"
                        strokeWidth="2"
                        style={{ opacity: 0 }}
                    />

                    {/* Selo com cadeado: é ele que lacra o envelope. */}
                    <g ref={seal} style={{ opacity: 0 }}>
                        <circle r="22.5" fill="#0b1f42" opacity=".18" cy="2" />
                        <circle r="21" fill="#1257c9" />
                        <circle r="21" fill={`url(#${glossId})`} />
                        <circle
                            r="16.5"
                            fill="none"
                            stroke="rgba(255,255,255,.6)"
                            strokeDasharray="1.5 2.6"
                        />
                        <path
                            d="M-4 -2 v-3 a4 4 0 0 1 8 0 v3"
                            fill="none"
                            stroke="#fff"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                        />
                        <rect
                            x="-6.5"
                            y="-2"
                            width="13"
                            height="10"
                            rx="2.2"
                            fill="#fff"
                        />
                        <circle cy="2.8" r="1.5" fill="#1257c9" />
                    </g>
                </g>
            </svg>

            <h2
                ref={headline}
                className="mt-6 text-center text-[24px] font-bold tracking-[-.01em] text-white"
                style={{ opacity: 0 }}
            >
                Sessão encerrada
            </h2>
            <p
                ref={farewell}
                className="text-on-navy-secondary mt-1.5 text-center text-[14px]"
                style={{ opacity: 0 }}
            >
                {firstName ? `Até logo, ${firstName}.` : 'Até logo.'}
            </p>
        </div>
    );
}
