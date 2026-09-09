import type { PDFDocumentProxy } from 'pdfjs-dist';
import { useCallback, useEffect, useState } from 'react';
import { type OpenedPdf, openPdfDocument, PdfLoadError } from '@/lib/pdf';

export type PdfDocumentStatus = 'idle' | 'loading' | 'ready' | 'error';

export interface PdfDocumentState {
    document: PDFDocumentProxy | null;
    pageCount: number;
    status: PdfDocumentStatus;
    /**
     * Os bytes chegaram do servidor? É diferente de `status === 'ready'`: um PDF que o
     * visualizador não consegue desenhar ainda assim FOI entregue, e a pessoa tem o botão
     * "Baixar PDF" para lê-lo. Um 403/404 nunca é entrega.
     */
    delivered: boolean;
    /** Mensagem PT-BR pronta para exibição (null enquanto não há erro). */
    error: string | null;
    reload: () => void;
}

/**
 * Baixa e abre o PDF da rota autorizada. Uma única instância por URL; o
 * documento é destruído ao trocar de URL ou desmontar (o PDF.js mantém um
 * worker e um cache de páginas vivos até `destroy()`).
 */
export function usePdfDocument(url: string | null): PdfDocumentState {
    const [document, setDocument] = useState<PDFDocumentProxy | null>(null);
    const [status, setStatus] = useState<PdfDocumentStatus>(
        url ? 'loading' : 'idle',
    );
    const [error, setError] = useState<string | null>(null);
    const [delivered, setDelivered] = useState(false);
    const [attempt, setAttempt] = useState(0);

    const reload = useCallback(() => setAttempt((value) => value + 1), []);

    useEffect(() => {
        if (!url) {
            setDocument(null);
            setStatus('idle');
            setError(null);
            setDelivered(false);

            return;
        }

        const controller = new AbortController();
        let cancelled = false;
        let opened: OpenedPdf | null = null;

        setStatus('loading');
        setError(null);
        setDelivered(false);

        openPdfDocument(url, controller.signal, () => {
            if (!cancelled) {
                setDelivered(true);
            }
        })
            .then((pdf) => {
                opened = pdf;

                if (cancelled) {
                    void pdf.destroy();

                    return;
                }

                setDocument(pdf.document);
                setStatus('ready');
            })
            .catch((cause: unknown) => {
                if (cancelled || controller.signal.aborted) {
                    return;
                }

                setDocument(null);
                setStatus('error');
                setError(
                    cause instanceof PdfLoadError
                        ? cause.message
                        : 'Não foi possível exibir o documento. Baixe o arquivo para conferir.',
                );
            });

        return () => {
            cancelled = true;
            controller.abort();
            setDocument(null);

            if (opened) {
                void opened.destroy();
            }
        };
    }, [url, attempt]);

    return {
        document,
        pageCount: document?.numPages ?? 0,
        status,
        delivered,
        error,
        reload,
    };
}
