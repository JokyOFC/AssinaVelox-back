import type { PDFDocumentProxy } from 'pdfjs-dist';
import { useEffect, useState } from 'react';
import { devicePixelRatioCapped, isRenderCancelled } from '@/lib/pdf';
import { cn } from '@/lib/utils';

/** Largura das miniaturas (DESIGN §6.5: rail de 72 px). */
const THUMB_WIDTH = 72;

/**
 * Rail de miniaturas das páginas (DESIGN §4.19 / §6.5).
 *
 * As miniaturas são renderizadas **no cliente**, pelo mesmo documento já
 * aberto pelo visualizador — não há rota de imagem por página envolvida. A
 * renderização é sequencial (uma página por vez) para não disputar o worker
 * com a página em edição; cada miniatura vira um data URL e fica em memória.
 */
export function PdfPageRail({
    document,
    pageCount,
    current,
    onSelect,
    fieldCounts,
    className,
}: {
    document: PDFDocumentProxy | null;
    pageCount: number;
    current: number;
    onSelect: (page: number) => void;
    /** Quantidade de campos por página (1-based) — mostra o dot azul. */
    fieldCounts?: Record<number, number>;
    className?: string;
}) {
    const [thumbs, setThumbs] = useState<Record<number, string>>({});

    useEffect(() => {
        if (!document) {
            setThumbs({});

            return;
        }

        let cancelled = false;
        setThumbs({});

        const run = async (): Promise<void> => {
            const ratio = devicePixelRatioCapped();

            for (let number = 1; number <= document.numPages; number += 1) {
                if (cancelled) {
                    return;
                }

                try {
                    const page = await document.getPage(number);
                    const base = page.getViewport({ scale: 1 });
                    const viewport = page.getViewport({
                        scale: (THUMB_WIDTH * ratio) / base.width,
                    });
                    const canvas = globalThis.document.createElement('canvas');
                    canvas.width = Math.round(viewport.width);
                    canvas.height = Math.round(viewport.height);
                    const context = canvas.getContext('2d');

                    if (!context) {
                        return;
                    }

                    await page.render({
                        canvas,
                        canvasContext: context,
                        viewport,
                    }).promise;

                    if (cancelled) {
                        return;
                    }

                    const url = canvas.toDataURL('image/png');
                    setThumbs((previous) => ({ ...previous, [number]: url }));
                } catch (error) {
                    if (isRenderCancelled(error) || cancelled) {
                        return;
                    }
                    // Uma miniatura que falha não impede a navegação: a página
                    // continua clicável com o esqueleto no lugar da imagem.
                }
            }
        };

        void run();

        return () => {
            cancelled = true;
        };
    }, [document]);

    const total = pageCount || document?.numPages || 0;

    return (
        <nav
            aria-label="Páginas do documento"
            className={cn(
                'flex gap-2 overflow-x-auto pb-1 md:flex-col md:overflow-x-visible md:overflow-y-auto md:pb-0',
                className,
            )}
        >
            {Array.from({ length: total }, (_, index) => index + 1).map(
                (number) => {
                    const active = number === current;
                    const fields = fieldCounts?.[number] ?? 0;

                    return (
                        <button
                            key={number}
                            type="button"
                            onClick={() => onSelect(number)}
                            aria-current={active ? 'page' : undefined}
                            aria-label={
                                fields > 0
                                    ? `Página ${number}, ${fields} ${fields === 1 ? 'campo' : 'campos'}`
                                    : `Página ${number}`
                            }
                            className={cn(
                                'relative aspect-[1/1.3] w-[72px] shrink-0 cursor-pointer overflow-hidden rounded border-2 bg-white transition-colors',
                                active
                                    ? 'border-primary'
                                    : 'border-border hover:border-border-dashed',
                            )}
                        >
                            {thumbs[number] ? (
                                <img
                                    src={thumbs[number]}
                                    alt=""
                                    className="h-full w-full object-contain"
                                />
                            ) : (
                                <span className="flex h-full w-full flex-col gap-1 p-2">
                                    <span className="bg-accent h-[3px] rounded-sm" />
                                    <span className="bg-accent h-[3px] w-4/5 rounded-sm" />
                                    <span className="bg-accent h-[3px] rounded-sm" />
                                    <span className="bg-accent h-[3px] w-3/5 rounded-sm" />
                                </span>
                            )}
                            <span className="text-muted-foreground tabular absolute right-1 bottom-0.5 rounded bg-white/80 px-0.5 text-[9px]">
                                {number}
                            </span>
                            {fields > 0 && (
                                <span
                                    aria-hidden
                                    className="bg-primary absolute top-1 right-1 size-2 rounded-full ring-1 ring-white"
                                />
                            )}
                        </button>
                    );
                },
            )}
        </nav>
    );
}
