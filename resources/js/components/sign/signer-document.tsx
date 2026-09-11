import { ChevronRight, Download, FileText, Maximize2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PdfViewer, type RenderedPageSize } from '@/components/pdf/pdf-viewer';
import { DEFAULT_ZOOM } from '@/components/pdf/pdf-zoom-controls';
import {
    type PdfDocumentStatus,
    usePdfDocument,
} from '@/components/pdf/use-pdf-document';
import {
    type OtherField,
    type SignerField,
    SignerFieldLayer,
    type StampOwner,
} from '@/components/sign/signer-field-layer';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { plural } from '@/lib/format';

export interface SignerDocumentProps {
    /** Rota autorizada que transmite o PDF apresentado (`sign.document`). */
    pdfUrl: string;
    title: string;
    pages: number;
    displayCode: string;
    fields: SignerField[];
    others: OtherField[];
    values: Record<string, string | boolean>;
    signatureImage: string | null;
    initialsImage: string | null;
    activeFieldId: string | null;
    onActivateField: (field: SignerField) => void;
    /** Página exibida (controlada pelo pai para a navegação entre campos). */
    page: number;
    onPageChange: (page: number) => void;
    /** Próximo campo obrigatório ainda pendente, se houver. */
    nextPending?: SignerField | null;
    onGoToNextPending?: () => void;
    /** Somente leitura: comprovante, sem campos clicáveis. */
    readOnly?: boolean;
    /** Fase 2 §2.8: marca da organização para a prévia do carimbo visual. */
    stampOwner?: StampOwner | null;
    /**
     * Estado do visualizador, para quem precisa saber se o documento chegou a ser
     * apresentado — a declaração de aceite afirma que o conteúdo foi apresentado nesta
     * tela. `delivered` diz que os BYTES chegaram (403/404 nunca são entrega); `status`
     * diz se o visualizador conseguiu desenhá-los.
     */
    onStatusChange?: (status: PdfDocumentStatus, delivered: boolean) => void;
}

/**
 * Painel do documento na página pública (DESIGN §6.12 e §4.19, variante
 * pública: página até 680px, barra em card próprio com "Baixar PDF" e
 * "Ampliar").
 *
 * O PDF é aberto uma única vez e compartilhado entre o painel e o diálogo de
 * tela cheia — abrir "Ampliar" não baixa o arquivo de novo.
 */
export function SignerDocument({
    pdfUrl,
    title,
    pages,
    displayCode,
    fields,
    others,
    values,
    signatureImage,
    initialsImage,
    activeFieldId,
    onActivateField,
    page,
    onPageChange,
    nextPending,
    onGoToNextPending,
    readOnly = false,
    onStatusChange,
    stampOwner = null,
}: SignerDocumentProps) {
    const pdf = usePdfDocument(pdfUrl);
    const [zoom, setZoom] = useState(DEFAULT_ZOOM);

    useEffect(() => {
        onStatusChange?.(pdf.status, pdf.delivered);
    }, [pdf.status, pdf.delivered, onStatusChange]);

    const [expanded, setExpanded] = useState(false);
    const [expandedPage, setExpandedPage] = useState(1);

    const overlay = (current: number) =>
        function overlayLayer(size: RenderedPageSize) {
            return (
                <SignerFieldLayer
                    page={size}
                    pageNumber={current}
                    fields={fields}
                    others={others}
                    values={values}
                    signatureImage={signatureImage}
                    initialsImage={initialsImage}
                    activeId={activeFieldId}
                    onActivate={onActivateField}
                    stampOwner={stampOwner}
                    className={readOnly ? 'pointer-events-none' : undefined}
                />
            );
        };

    return (
        <div className="flex min-w-0 flex-col gap-3">
            <div className="border-border bg-card text-text-secondary flex flex-wrap items-center justify-between gap-3 rounded-[10px] border px-3.5 py-2.5 text-[13px]">
                <span className="flex min-w-0 items-center gap-2.5">
                    <FileText className="text-primary size-4 shrink-0" />
                    <b className="text-foreground truncate">{title}</b>
                    <span className="whitespace-nowrap">
                        · {plural(pages, 'página')}
                    </span>
                </span>
                <span className="flex gap-1.5">
                    <Button asChild variant="outline" size="xs">
                        <a href={pdfUrl} download>
                            <Download className="size-3.5" />
                            Baixar PDF
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        size="xs"
                        onClick={() => {
                            setExpandedPage(page);
                            setExpanded(true);
                        }}
                    >
                        <Maximize2 className="size-3.5" />
                        Ampliar
                    </Button>
                </span>
            </div>

            <PdfViewer
                pdf={pdf}
                page={page}
                onPageChange={onPageChange}
                zoom={zoom}
                onZoomChange={setZoom}
                maxPageWidth={680}
                stamp={`${displayCode} · pág. ${page}/${pages || 1}`}
                overlay={overlay(page)}
                toolbarEnd={
                    nextPending && onGoToNextPending ? (
                        <Button
                            variant="outline"
                            size="xxs"
                            onClick={onGoToNextPending}
                        >
                            Próximo campo
                            <ChevronRight className="size-3" />
                        </Button>
                    ) : undefined
                }
            />

            <Dialog open={expanded} onOpenChange={setExpanded}>
                <DialogContent className="flex h-[92svh] max-w-[min(96vw,1100px)] flex-col gap-3 overflow-hidden p-4">
                    <DialogHeader>
                        <DialogTitle className="truncate text-[15px]">
                            {title}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="min-h-0 flex-1 overflow-auto">
                        <PdfViewer
                            pdf={pdf}
                            page={expandedPage}
                            onPageChange={setExpandedPage}
                            zoom={zoom}
                            onZoomChange={setZoom}
                            maxPageWidth={1000}
                            stamp={`${displayCode} · pág. ${expandedPage}/${pages || 1}`}
                            overlay={overlay(expandedPage)}
                        />
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
