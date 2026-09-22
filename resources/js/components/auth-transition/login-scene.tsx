import {
    type Ref,
    useId,
    useImperativeHandle,
    useLayoutEffect,
    useRef,
} from 'react';
import AppLogo from '@/components/app-logo';
import { formatDateTime } from '@/lib/format';
import {
    centerOf,
    clamp01,
    easeInOutCubic,
    easeInOutSine,
    easeOutBack,
    easeOutCubic,
    lerp,
    type SceneHandle,
    type SceneTiming,
    span,
} from './timeline';

export const LOGIN_TIMING: SceneTiming = { exit: 1850, end: 2350 };

/** Marcos da cena, em ms. */
const STAGE_IN = [80, 460] as const;
const WRITE = [320, 960] as const;
const FLOURISH = [1000, 1290] as const;
const PEN_OUT = [1290, 1470] as const;
const STATUS = [1220, 1480] as const;
const CAPTION = [1300, 1600] as const;

/** Geometria da área de assinatura (unidades do viewBox 360×124). */
const TEXT_X = 38;
const BASE_Y = 84;
const MAX_TEXT_WIDTH = 286;
const BASE_FONT = 50;
const MIN_FONT = 26;
/** O nome sobe 3° — letra de mão raramente anda na horizontal. */
const TILT = (3 * Math.PI) / 180;

type Geometry = {
    width: number;
    length: number;
    /** Sobe-e-desce da caneta enquanto escreve; acompanha o tamanho do nome. */
    cycles: number;
};

export type LoginSceneProps = {
    ref: Ref<SceneHandle>;
    /** Nome escrito à mão (nome e sobrenome, ou só o nome quando não cabe). */
    signature: string;
    email: string;
};

/**
 * Entrada: o nome de quem entrou é escrito à mão sobre a linha de assinatura, a caneta fecha
 * com um floreio e o cartão confirma o acesso. Mesmo cartão de vidro do aside do login.
 *
 * A legenda diz só o que aconteceu ("Acesso autenticado"): o login por senha não entra em
 * trilha de auditoria, então nada aqui fala em registro nem em assinatura do acesso.
 */
export function LoginScene({ ref, signature, email }: LoginSceneProps) {
    const clipId = useId();
    const stage = useRef<HTMLDivElement>(null);
    const wipe = useRef<SVGRectElement>(null);
    const text = useRef<SVGTextElement>(null);
    const flourish = useRef<SVGPathElement>(null);
    const pen = useRef<SVGGElement>(null);
    const status = useRef<HTMLSpanElement>(null);
    const caption = useRef<HTMLParagraphElement>(null);
    const geometry = useRef<Geometry | null>(null);

    // Mede o nome na fonte real e desenha o floreio sob ele. Roda de novo quando a Caveat
    // termina de carregar: até lá a largura é a da fonte reserva.
    useLayoutEffect(() => {
        const measure = () => {
            const textEl = text.current;
            const flourishEl = flourish.current;

            if (!textEl || !flourishEl) {
                return;
            }

            let size = BASE_FONT;
            textEl.setAttribute('font-size', String(size));
            let width = textEl.getComputedTextLength();

            if (width > MAX_TEXT_WIDTH) {
                size = Math.max(MIN_FONT, (size * MAX_TEXT_WIDTH) / width);
                textEl.setAttribute('font-size', size.toFixed(1));
                width = textEl.getComputedTextLength();
            }

            const x0 = TEXT_X;
            const xe = TEXT_X + width * Math.cos(TILT);
            const ye = BASE_Y - width * Math.sin(TILT);

            // Sai do fim do nome, volta por baixo até antes do começo, faz o laço e corre
            // para a direita — o sublinhado de quem assina com pressa.
            flourishEl.setAttribute(
                'd',
                [
                    `M ${xe + 3} ${ye + 2}`,
                    `C ${xe + 10} ${ye + 26}, ${x0 + width * 0.5} 119, ${x0 + 4} 105`,
                    `C ${x0 - 10} 100, ${x0 - 2} 90, ${x0 + 14} 98`,
                    `C ${x0 + width * 0.35} 110, ${xe - width * 0.2} ${ye + 30}, ${xe + 30} ${ye + 6}`,
                ].join(' '),
            );

            const length = flourishEl.getTotalLength();
            flourishEl.style.strokeDasharray = String(length);

            geometry.current = {
                width,
                length,
                cycles: Math.max(4, Math.round(signature.length * 0.9)),
            };
        };

        measure();

        let cancelled = false;

        void document.fonts
            ?.load(`600 ${BASE_FONT}px Caveat`, signature)
            .then(() => {
                if (!cancelled) {
                    measure();
                }
            });

        return () => {
            cancelled = true;
        };
    }, [signature]);

    useImperativeHandle(ref, () => ({
        focusPoint: () => centerOf(status.current),
        update: (t: number) => {
            const g = geometry.current;
            const stageEl = stage.current;
            const flourishEl = flourish.current;

            if (!g || !stageEl || !flourishEl) {
                return;
            }

            const stageIn = easeOutCubic(span(t, ...STAGE_IN));
            stageEl.style.opacity = String(stageIn);
            stageEl.style.transform = `translateY(${lerp(14, 0, stageIn)}px)`;

            // Escrita: a caneta anda pelo nome subindo e descendo, e a tinta aparece atrás.
            const write = easeInOutSine(span(t, ...WRITE));
            const phase = write * g.cycles * 2 * Math.PI;
            const along = g.width * write;
            const writeX =
                TEXT_X + along * Math.cos(TILT) + 2.2 * Math.cos(phase);
            const writeY =
                BASE_Y - along * Math.sin(TILT) - (12 + 10 * Math.sin(phase));

            wipe.current?.setAttribute(
                'width',
                write <= 0 ? '0' : write >= 1 ? '360' : String(writeX + 3),
            );

            // Floreio: traço de verdade, desenhado pelo comprimento do caminho.
            const flourishIn = easeInOutCubic(span(t, ...FLOURISH));
            flourishEl.style.strokeDashoffset = String(
                g.length * (1 - flourishIn),
            );
            flourishEl.style.opacity = flourishIn > 0 ? '1' : '0';

            let penX = writeX;
            let penY = writeY;

            if (t > WRITE[1]) {
                const start = flourishEl.getPointAtLength(0);
                const travel = span(t, WRITE[1], FLOURISH[0]);
                const point =
                    flourishIn > 0
                        ? flourishEl.getPointAtLength(g.length * flourishIn)
                        : {
                              x: lerp(writeX, start.x, travel),
                              y: lerp(writeY, start.y, travel),
                          };

                penX = point.x;
                penY = point.y;
            }

            const penOut = easeOutCubic(span(t, ...PEN_OUT));
            const penEl = pen.current;

            if (penEl) {
                penEl.setAttribute(
                    'transform',
                    `translate(${penX + 12 * penOut} ${penY - 18 * penOut})`,
                );
                penEl.style.opacity = String(
                    span(t, WRITE[0] - 140, WRITE[0]) * (1 - penOut),
                );
            }

            const statusIn = span(t, ...STATUS);

            if (status.current) {
                status.current.style.opacity = String(clamp01(statusIn * 2));
                status.current.style.transform = `scale(${lerp(0.6, 1, easeOutBack(statusIn))})`;
            }

            const captionIn = easeOutCubic(span(t, ...CAPTION));

            if (caption.current) {
                caption.current.style.opacity = String(captionIn);
                caption.current.style.transform = `translateY(${lerp(8, 0, captionIn)}px)`;
            }
        },
    }));

    return (
        <div
            ref={stage}
            className="flex w-full flex-col items-center"
            style={{ opacity: 0 }}
        >
            <AppLogo inverted height={28} />

            <div className="mt-7 w-full rounded-xl border border-white/10 bg-white/[.06] px-5 pt-[18px] pb-3.5">
                <div className="text-on-navy-muted flex items-center justify-between gap-3 text-[9.5px] font-bold tracking-[.16em] uppercase">
                    <span>Boas-vindas</span>
                    <span className="tabular">
                        {formatDateTime(new Date().toISOString())}
                    </span>
                </div>

                <svg
                    viewBox="0 0 360 124"
                    className="mt-1 block w-full overflow-visible"
                >
                    <defs>
                        <clipPath id={clipId}>
                            <rect
                                ref={wipe}
                                x="0"
                                y="-20"
                                width="0"
                                height="164"
                            />
                        </clipPath>
                    </defs>

                    {/* Linha de assinatura e o "x" de "assine aqui". */}
                    <line
                        x1="14"
                        y1="98"
                        x2="346"
                        y2="98"
                        stroke="rgba(255,255,255,.2)"
                        strokeDasharray="3 5"
                    />
                    <path
                        d="M14 83 l9 9 m0 -9 l-9 9"
                        fill="none"
                        stroke="rgba(255,255,255,.42)"
                        strokeWidth="1.6"
                        strokeLinecap="round"
                    />

                    <g clipPath={`url(#${clipId})`}>
                        <text
                            ref={text}
                            x={TEXT_X}
                            y={BASE_Y}
                            transform={`rotate(-3 ${TEXT_X} ${BASE_Y})`}
                            fontFamily="'Caveat', 'Segoe Script', cursive"
                            fontWeight="600"
                            fontSize={BASE_FONT}
                            fill="#fff"
                        >
                            {signature}
                        </text>
                    </g>

                    <path
                        ref={flourish}
                        fill="none"
                        stroke="#fff"
                        strokeWidth="2.1"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        style={{ opacity: 0 }}
                    />

                    {/* Caneta: ponta na origem, corpo inclinado como na mão direita. */}
                    <g ref={pen} style={{ opacity: 0 }}>
                        <g transform="rotate(36)">
                            <path
                                d="M0 0 L-3.4 -10 L3.4 -10 Z"
                                fill="#e8f0fd"
                            />
                            <path
                                d="M0 -2 L0 -8"
                                stroke="#0b1f42"
                                strokeWidth="0.7"
                            />
                            <rect
                                x="-3.8"
                                y="-15"
                                width="7.6"
                                height="5.4"
                                rx="1"
                                fill="#2e7bef"
                            />
                            <rect
                                x="-3.8"
                                y="-52"
                                width="7.6"
                                height="37.4"
                                rx="3.4"
                                fill="#fff"
                            />
                            <rect
                                x="-3.8"
                                y="-52"
                                width="7.6"
                                height="7"
                                rx="3.4"
                                fill="#2e7bef"
                            />
                        </g>
                    </g>
                </svg>

                <div className="text-on-navy-secondary mt-1.5 flex items-center justify-between gap-3 border-t border-dashed border-white/[.14] pt-3 text-[12px]">
                    <span className="truncate">{email}</span>
                    <span
                        ref={status}
                        className="text-success-solid shrink-0 font-bold"
                        style={{ opacity: 0 }}
                    >
                        Acesso autenticado ✓
                    </span>
                </div>
            </div>

            <p
                ref={caption}
                className="text-on-navy-secondary mt-7 text-center text-[14px]"
                style={{ opacity: 0 }}
            >
                Abrindo sua conta…
            </p>
        </div>
    );
}
