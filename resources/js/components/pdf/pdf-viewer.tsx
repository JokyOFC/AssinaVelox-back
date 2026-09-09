import { ChevronLeft, ChevronRight, FileWarning, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import {
    DEFAULT_ZOOM,
    PdfZoomControls,
} from '@/components/pdf/pdf-zoom-controls';
import {
    type PdfDocumentState,
    usePdfDocument,
} from '@/components/pdf/use-pdf-document';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { isRenderCancelled, renderPageToCanvas } from '@/lib/pdf';
import type { RenderedPageSize } from '@/lib/pdf';
import { cn } from '@/lib/utils';
import type { DocumentProcessingStatus } from '@/types/enums';

export type { RenderedPageSize };

export interface PdfProcessingState {
    status: DocumentProcessingStatus;
    error?: string | null;
    progress_pct?: number | null;
}

export interface PdfViewerProps {
    /** Rota autorizada que devolve `application/pdf`. */
    url?: string | null;
    /** Documento já aberto (ex.: compartilhado com o rail de páginas). */
    pdf?: PdfDocumentState;
    /** Página exibida (1-based). */
    page: number;
    onPageChange?: (page: number) => void;
    zoom?: number;
    onZoomChange?: (zoom: number) => void;
    /** Estado de processamento do documento (upload/conversão). */
    processing?: PdfProcessingState | null;
    /** Camada sobreposta ao canvas — recebe as dimensões renderizadas. */
    overlay?: (size: RenderedPageSize) => ReactNode;
    onPageSizeChange?: (size: RenderedPageSize) => void;
    /** Largura máxima da página em px (editor 520, detalhe 560, público 680). */
    maxPageWidth?: number;
    /** Conteúdo extra no canto direito da barra (ex.: link "Certificado"). */
    toolbarEnd?: ReactNode;
    showToolbar?: boolean;
    /** Carimbo do rodapé da página ("AV-00148 · pág. 1/6"). */
    stamp?: ReactNode;
    className?: string;
    /** Altura mínima da área do documento enquanto nada foi renderizado. */
    minHeight?: number;
}

/**
 * Visualizador de PDF (DESIGN §4.19): card com barra de navegação/zoom e a
 * página renderizada por PDF.js num `<canvas>` centralizado sobre `bg-accent`.
 *
 * A escala acompanha a largura do contêiner (`1` = ajustar à largura); as
 * dimensões renderizadas em pixels CSS são devolvidas para a camada de campos,
 * que é quem converte para as coordenadas normalizadas do contrato
 * (ver `lib/geometry.ts`).
 */
export function PdfViewer({
    url,
    pdf,
    page,
    onPageChange,
    zoom = DEFAULT_ZOOM,
    onZoomChange,
    processing,
    overlay,
    onPageSizeChange,
    maxPageWidth = 560,
    toolbarEnd,
    showToolbar = true,
    stamp,
    className,
    minHeight = 320,
}: PdfViewerProps) {
    const blocked =
        processing != null &&
        processing.status !== 'ready' &&
        processing.status !== 'uploaded';

    const ownUrl = pdf !== undefined || blocked ? null : (url ?? null);
    const internal = usePdfDocument(ownUrl);
    const source = pdf ?? internal;

    const areaRef = useRef<HTMLDivElement | null>(null);
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const [available, setAvailable] = useState(0);
    const [size, setSize] = useState<RenderedPageSize | null>(null);
    const [renderError, setRenderError] = useState<string | null>(null);

    // O callback do chamador vive num ref: incluí-lo nas dependências
    // re-renderizaria a página a cada render do componente pai.
    const sizeCallback = useRef(onPageSizeChange);

    useEffect(() => {
        sizeCallback.current = onPageSizeChange;
    }, [onPageSizeChange]);

    // Largura disponível para a página (o padding lateral de 24px já entra aqui).
    useLayoutEffect(() => {
        const area = areaRef.current;

        if (!area) {
            return;
        }

        const observer = new ResizeObserver((entries) => {
            const width = entries[0]?.contentRect.width ?? 0;
            setAvailable(Math.floor(width));
        });

        observer.observe(area);
        setAvailable(Math.floor(area.clientWidth));

        return () => observer.disconnect();
    }, []);

    const document = source.document;
    const pageCount = source.pageCount;
    const safePage = Math.min(Math.max(1, page), Math.max(1, pageCount));
    const targetWidth = Math.max(
        160,
        Math.round(Math.min(available, maxPageWidth) * zoom),
    );

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!document || !canvas || available === 0) {
            return;
        }

        let cancelled = false;
        let cancelRender: (() => void) | null = null;

        setRenderError(null);

        document
            .getPage(safePage)
            .then((pdfPage) => {
                if (cancelled || !canvasRef.current) {
                    return;
                }

                const render = renderPageToCanvas(
                    pdfPage,
                    canvasRef.current,
                    targetWidth,
                );
                cancelRender = render.cancel;
                setSize(render.size);
                sizeCallback.current?.(render.size);

                return render.promise;
            })
            .catch((error: unknown) => {
                if (cancelled || isRenderCancelled(error)) {
                    return;
                }

                setRenderError('Não foi possível desenhar esta página.');
            });

        return () => {
            cancelled = true;
            cancelRender?.();
        };
    }, [document, safePage, targetWidth, available]);

    const body = (() => {
        if (blocked && processing) {
            return <ProcessingState processing={processing} />;
        }

        if (source.status === 'loading' || source.status === 'idle') {
            return (
                <div
                    className="text-muted-foreground flex flex-col items-center justify-center gap-3 text-[13px]"
                    style={{ minHeight }}
                >
                    <Spinner className="size-5" />
                    Carregando o documento…
                </div>
            );
        }

        if (source.status === 'error' || renderError) {
            return (
                <div
                    className="flex flex-col items-center justify-center gap-3 px-6 text-center"
                    style={{ minHeight }}
                >
                    <span className="bg-danger-bg text-danger flex size-11 items-center justify-center rounded-xl">
                        <FileWarning className="size-5" />
                    </span>
                    <p className="text-text-secondary max-w-[360px] text-[13px] leading-[1.5]">
                        {source.error ?? renderError}
                    </p>
                    <Button variant="outline" size="xs" onClick={source.reload}>
                        Tentar de novo
                    </Button>
                </div>
            );
        }

        return (
            <div
                className="shadow-pdf relative bg-white"
                style={
                    size
                        ? { width: size.width, height: size.height }
                        : { width: targetWidth, minHeight }
                }
            >
                <canvas ref={canvasRef} className="block" />
                {size && overlay?.(size)}
                {stamp && (
                    <span className="text-muted-foreground pointer-events-none absolute right-3 bottom-2.5 text-[8px]">
                        {stamp}
                    </span>
                )}
            </div>
        );
    })();

    return (
        <div
            className={cn(
                'border-border bg-card shadow-card overflow-hidden rounded-xl border',
                className,
            )}
        >
            {showToolbar && (
                <div className="border-border text-text-secondary flex flex-wrap items-center justify-between gap-2 border-b px-3.5 py-2.5 text-[13px]">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon-xs"
                            aria-label="Página anterior"
                            disabled={safePage <= 1 || pageCount === 0}
                            onClick={() => onPageChange?.(safePage - 1)}
                        >
                            <ChevronLeft className="size-3.5" />
                        </Button>
                        <span className="tabular">
                            Página <b className="text-foreground">{safePage}</b>{' '}
                            de {pageCount || '—'}
                        </span>
                        <Button
                            variant="outline"
                            size="icon-xs"
                            aria-label="Próxima página"
                            disabled={safePage >= pageCount || pageCount === 0}
                            onClick={() => onPageChange?.(safePage + 1)}
                        >
                            <ChevronRight className="size-3.5" />
                        </Button>
                    </div>
                    <div className="flex items-center gap-3">
                        {onZoomChange && (
                            <PdfZoomControls
                                zoom={zoom}
                                onZoomChange={onZoomChange}
                                disabled={source.status !== 'ready'}
                            />
                        )}
                        {toolbarEnd && (
                            <>
                                <span
                                    aria-hidden
                                    className="bg-border h-4 w-px"
                                />
                                {toolbarEnd}
                            </>
                        )}
                    </div>
                </div>
            )}

            <div
                ref={areaRef}
                className="bg-accent flex justify-center overflow-auto p-4 md:p-6"
            >
                {body}
            </div>
        </div>
    );
}

/**
 * Estados do pipeline documental (arquitetura §5). Nenhum deles é erro de
 * PDF.js: o arquivo pode ainda estar em conversão, ter sido bloqueado
 * (protegido por senha ou já assinado) ou ter falhado no processamento.
 */
function ProcessingState({ processing }: { processing: PdfProcessingState }) {
    if (processing.status === 'converting') {
        return (
            <div className="text-text-secondary flex min-h-[260px] flex-col items-center justify-center gap-3 px-6 text-center text-[13px]">
                <Spinner className="size-5" />
                <p>
                    Convertendo o arquivo para PDF
                    {processing.progress_pct != null && (
                        <span className="tabular">
                            {' '}
                            · {processing.progress_pct}%
                        </span>
                    )}
                </p>
                <p className="text-muted-foreground text-[12.5px]">
                    A pré-visualização aparece assim que a conversão terminar.
                </p>
            </div>
        );
    }

    const isBlocked = processing.status === 'blocked';

    return (
        <div className="flex min-h-[260px] flex-col items-center justify-center gap-3 px-6 text-center">
            <span className="bg-danger-bg text-danger flex size-11 items-center justify-center rounded-xl">
                {isBlocked ? (
                    <Lock className="size-5" />
                ) : (
                    <FileWarning className="size-5" />
                )}
            </span>
            <p className="text-foreground text-[13.5px] font-semibold">
                {isBlocked
                    ? 'Arquivo bloqueado para preparação'
                    : 'Falha ao processar o arquivo'}
            </p>
            <p className="text-text-secondary max-w-[380px] text-[13px] leading-[1.5]">
                {processing.error ??
                    (isBlocked
                        ? 'PDFs protegidos por senha ou que já contêm assinatura digital não podem receber campos. Envie uma versão sem proteção.'
                        : 'Não conseguimos preparar este arquivo. Remova-o e envie um PDF válido.')}
            </p>
        </div>
    );
}
