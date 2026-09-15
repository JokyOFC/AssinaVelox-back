import { Eraser, Undo2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type SignaturePad from 'signature_pad';
import type { PointGroup } from 'signature_pad';
import { Button } from '@/components/ui/button';
import { Trans, useI18n } from '@/i18n';
import { cn } from '@/lib/utils';

export interface SignaturePadCanvasProps {
    /** Altura do quadro em pixels CSS (DESIGN §4.20 usa 150). */
    height?: number;
    /** Dica sob a linha-base. */
    hint?: string;
    /** Chamado a cada traço concluído, limpeza ou desfazer. */
    onChange: (canvas: HTMLCanvasElement | null) => void;
    ariaLabel: string;
    className?: string;
}

/** Cor do traço (DESIGN §4.20). */
const INK = '#0b1f42';

/**
 * Quadro de desenho da assinatura (DESIGN §4.20), sobre `signature_pad`.
 *
 * O canvas é sempre transparente: quem consome recebe o elemento e recorta o
 * traço em `signature-image.ts`. Funciona com dedo, caneta e mouse — o
 * `touch-action: none` impede que o gesto role a página no celular, que é o
 * dispositivo em que a maioria dos signatários assina.
 */
export function SignaturePadCanvas({
    height = 170,
    hint = 'Desenhe com o dedo ou o mouse',
    onChange,
    ariaLabel,
    className,
}: SignaturePadCanvasProps) {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const padRef = useRef<SignaturePad | null>(null);
    const [empty, setEmpty] = useState(true);
    const [ready, setReady] = useState(false);

    // O callback vive num ref: incluí-lo nas dependências recriaria o pad a
    // cada render do componente pai e apagaria o traço em andamento.
    const changeRef = useRef(onChange);

    useEffect(() => {
        changeRef.current = onChange;
    }, [onChange]);

    const publish = useCallback(() => {
        const pad = padRef.current;
        const canvas = canvasRef.current;

        if (!pad || !canvas) {
            return;
        }

        const isEmpty = pad.isEmpty();
        setEmpty(isEmpty);
        changeRef.current(isEmpty ? null : canvas);
    }, []);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        let disposed = false;
        let pad: SignaturePad | null = null;
        let observer: ResizeObserver | null = null;
        let lastWidth = 0;

        const resize = () => {
            if (!pad || disposed) {
                return;
            }

            const ratio = Math.min(2, window.devicePixelRatio || 1);
            const width = canvas.offsetWidth;

            if (width === 0 || width === lastWidth) {
                return;
            }

            // Reescala os traços já feitos para a nova largura, senão girar o
            // celular deslocaria a assinatura.
            const previous = pad.toData();
            const factor = lastWidth === 0 ? 1 : width / lastWidth;
            lastWidth = width;

            canvas.width = width * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d')?.scale(ratio, ratio);

            pad.clear();

            if (previous.length > 0 && factor !== 1) {
                const scaled: PointGroup[] = previous.map((group) => ({
                    ...group,
                    points: group.points.map((point) => ({
                        ...point,
                        x: point.x * factor,
                    })),
                }));
                pad.fromData(scaled);
            } else if (previous.length > 0) {
                pad.fromData(previous);
            }
        };

        void import('signature_pad').then(({ default: SignaturePadClass }) => {
            if (disposed) {
                return;
            }

            pad = new SignaturePadClass(canvas, {
                backgroundColor: 'rgba(0, 0, 0, 0)',
                penColor: INK,
                minWidth: 0.9,
                maxWidth: 2.6,
                velocityFilterWeight: 0.75,
                minDistance: 1,
            });
            padRef.current = pad;
            pad.addEventListener('endStroke', publish);

            observer = new ResizeObserver(resize);
            observer.observe(canvas);
            resize();
            setReady(true);
        });

        return () => {
            disposed = true;
            observer?.disconnect();
            pad?.removeEventListener('endStroke', publish);
            pad?.off();
            padRef.current = null;
        };
    }, [publish]);

    const clear = () => {
        padRef.current?.clear();
        publish();
    };

    const undo = () => {
        const pad = padRef.current;

        if (!pad) {
            return;
        }

        const data = pad.toData();
        data.pop();
        pad.fromData(data);
        publish();
    };

    return (
        <PadFrame
            className={className}
            height={height}
            canvasRef={canvasRef}
            ariaLabel={ariaLabel}
            hint={hint}
            ready={ready}
            empty={empty}
            onUndo={undo}
            onClear={clear}
        />
    );
}

/**
 * Moldura do quadro (F-I18N): os rótulos "Desfazer"/"Limpar" e o aviso de preparo vêm do
 * dicionário, no idioma da página.
 */
function PadFrame({
    className,
    height,
    canvasRef,
    ariaLabel,
    hint,
    ready,
    empty,
    onUndo: undo,
    onClear: clear,
}: {
    className?: string;
    height: number;
    canvasRef: React.RefObject<HTMLCanvasElement | null>;
    ariaLabel: string;
    hint: string;
    ready: boolean;
    empty: boolean;
    onUndo: () => void;
    onClear: () => void;
}) {
    const { t } = useI18n();

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div
                className="border-border-dashed bg-background relative rounded-[10px] border-[1.5px] border-dashed"
                style={{ height }}
            >
                <canvas
                    ref={canvasRef}
                    aria-label={ariaLabel}
                    role="img"
                    className="block h-full w-full cursor-crosshair touch-none"
                />
                <span
                    aria-hidden
                    className="bg-input pointer-events-none absolute inset-x-4 bottom-7 h-px"
                />
                <span className="text-muted-foreground pointer-events-none absolute bottom-2.5 left-4 text-[11px]">
                    {ready ? hint : t('signature.pad.preparing')}
                </span>
                <div className="absolute top-2.5 right-2.5 flex gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="xxs"
                        disabled={empty}
                        onClick={undo}
                    >
                        <Undo2 className="size-3" />
                        <Trans k="signature.pad.undo">Desfazer</Trans>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="xxs"
                        disabled={empty}
                        onClick={clear}
                    >
                        <Eraser className="size-3" />
                        {t('signature.pad.clear')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
